<?php
/**
 * api/asc_review_status — what App Store Connect thinks of our iOS app,
 * read without a browser and without anyone logging in.
 *
 *   GET /api/asc_review_status.php?token=…
 *
 * WHY THIS EXISTS. The scheduled "Review verdict watch" task used to read
 * App Store Connect by driving Kenneth's browser. ASC signs itself out
 * constantly: between 2 and 17 Oct 2026 that check was signed out for 24
 * consecutive runs across 15 days, so Apple's 12 Oct rejection of iOS 1.0
 * (Guideline 2.1(b)) went unnoticed for five days. A credential that does
 * not expire with a browser session fixes that for good.
 *
 * The App Store Connect API authenticates with a JWT signed ES256 by a
 * private key (.p8) generated at Users and Access > Integrations. The key
 * never leaves this server: it lives OUTSIDE public_html, exactly like the
 * Firebase service account, and only its path is in config.php.
 *
 * Returns, for each of the most recent app versions and review
 * submissions, the state Apple is reporting right now. It does NOT return
 * App Review's rejection message — Resolution Center messages are not in
 * the public API, so a rejection still has to be read in the console. What
 * this buys is knowing within the hour that there IS one.
 *
 * Read-only: nothing here writes to App Store Connect.
 */
require_once __DIR__ . '/../includes/helpers.php';
@include_once __DIR__ . '/../includes/watchdog_token.php';
// The App Store Connect settings may live in config.php, or — better — in a
// small file beside it one level ABOVE public_html, so the four constants can
// be added without opening config.php (which holds the database password, the
// Stripe secret key and the Anthropic key). Either location works.
@include_once dirname(__DIR__, 2) . '/asc_config.php';

$token = (string)($_GET['token'] ?? '');
if (!defined('WATCHDOG_READ_TOKEN') || WATCHDOG_READ_TOKEN === '' || !hash_equals(WATCHDOG_READ_TOKEN, $token)) {
    error_response('Not authorized.', 401);
}

foreach (['ASC_KEY_ID', 'ASC_ISSUER_ID', 'ASC_PRIVATE_KEY_PATH', 'ASC_APP_ID'] as $c) {
    if (!defined($c) || constant($c) === '') {
        error_response('App Store Connect API is not configured on this server (' . $c . ').', 503);
    }
}

$pem = @file_get_contents(ASC_PRIVATE_KEY_PATH);
if ($pem === false || trim($pem) === '') {
    error_response('Could not read the App Store Connect private key.', 503);
}

/* openssl_sign on an EC key returns a DER SEQUENCE of two INTEGERs.
   JWS ES256 wants the raw 64-byte r||s instead, each half left-padded. */
function asc_der_to_jose(string $der): string {
    $off = 0;
    if (($der[$off++] ?? '') !== "\x30") throw new RuntimeException('signature is not a SEQUENCE');
    $len = ord($der[$off++]);
    if ($len & 0x80) $off += ($len & 0x7f);          // long-form length
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        if (($der[$off++] ?? '') !== "\x02") throw new RuntimeException('signature part is not an INTEGER');
        $ilen = ord($der[$off++]);
        $int  = substr($der, $off, $ilen);
        $off += $ilen;
        $int  = ltrim($int, "\x00");                  // drop DER sign padding
        if (strlen($int) > 32) throw new RuntimeException('signature part too long');
        $out .= str_pad($int, 32, "\x00", STR_PAD_LEFT);
    }
    return $out;
}

function asc_b64(string $raw): string {
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function asc_token(string $pem): string {
    $now    = time();
    $header = asc_b64(json_encode(['alg' => 'ES256', 'kid' => ASC_KEY_ID, 'typ' => 'JWT']));
    // 20 minutes is Apple's maximum; 15 leaves room for clock skew.
    $claims = asc_b64(json_encode([
        'iss' => ASC_ISSUER_ID,
        'iat' => $now,
        'exp' => $now + 900,
        'aud' => 'appstoreconnect-v1',
    ]));
    $key = openssl_pkey_get_private($pem);
    if ($key === false) throw new RuntimeException('private key did not parse');
    $der = '';
    if (!openssl_sign($header . '.' . $claims, $der, $key, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('could not sign the token');
    }
    return $header . '.' . $claims . '.' . asc_b64(asc_der_to_jose($der));
}

function asc_get(string $path, string $jwt): array {
    $ch = curl_init('https://api.appstoreconnect.apple.com' . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $jwt],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($body === false) return ['ok' => false, 'status' => 0, 'error' => $err];
    $json = json_decode($body, true);
    if ($code !== 200) {
        // Apple returns {errors:[{title,detail}]} — pass the detail through,
        // it is how a wrong role or a revoked key announces itself.
        $detail = $json['errors'][0]['detail'] ?? $json['errors'][0]['title'] ?? substr((string)$body, 0, 200);
        return ['ok' => false, 'status' => $code, 'error' => $detail];
    }
    return ['ok' => true, 'status' => 200, 'json' => is_array($json) ? $json : []];
}

try {
    $jwt = asc_token($pem);
} catch (Throwable $e) {
    error_response('Could not build the App Store Connect token: ' . $e->getMessage(), 500);
}

$appId = ASC_APP_ID;
$out = ['asOf' => gmdate('c'), 'appId' => $appId];

// --- app versions: the headline state (REJECTED, WAITING_FOR_REVIEW, …) ---
$v = asc_get('/v1/apps/' . rawurlencode($appId) . '/appStoreVersions'
           . '?limit=3&fields[appStoreVersions]=versionString,appStoreState,platform,createdDate', $jwt);
if (!$v['ok']) {
    $out['versions'] = ['error' => $v['error'], 'status' => $v['status']];
} else {
    $out['versions'] = array_map(static function ($row) {
        $a = $row['attributes'] ?? [];
        return [
            'version'  => $a['versionString'] ?? null,
            'state'    => $a['appStoreState'] ?? null,
            'platform' => $a['platform'] ?? null,
            'created'  => $a['createdDate'] ?? null,
        ];
    }, $v['json']['data'] ?? []);
}

// --- review submissions: where a rejection shows as UNRESOLVED_ISSUES ---
$s = asc_get('/v1/reviewSubmissions?filter[app]=' . rawurlencode($appId)
           . '&limit=5&fields[reviewSubmissions]=state,platform,submittedDate', $jwt);
if (!$s['ok']) {
    $out['submissions'] = ['error' => $s['error'], 'status' => $s['status']];
} else {
    $out['submissions'] = array_map(static function ($row) {
        $a = $row['attributes'] ?? [];
        return [
            'id'        => $row['id'] ?? null,
            'state'     => $a['state'] ?? null,
            'platform'  => $a['platform'] ?? null,
            'submitted' => $a['submittedDate'] ?? null,
        ];
    }, $s['json']['data'] ?? []);
}

// One word for the watcher to branch on, so it never has to guess.
$states = [];
foreach (($out['versions'] ?? []) as $row) if (!empty($row['state'])) $states[] = $row['state'];
foreach (($out['submissions'] ?? []) as $row) if (!empty($row['state'])) $states[] = $row['state'];
$bad  = ['REJECTED', 'METADATA_REJECTED', 'DEVELOPER_REJECTED', 'UNRESOLVED_ISSUES', 'INVALID_BINARY'];
$live = ['READY_FOR_SALE', 'READY_FOR_DISTRIBUTION', 'COMPLETE'];
$out['verdict'] = count(array_intersect($bad, $states)) ? 'needs_attention'
                : (count(array_intersect($live, $states)) ? 'approved_or_live' : 'in_progress');
$out['allStates'] = array_values(array_unique($states));
$out['note'] = 'Apple does not expose App Review message text in the public API. '
             . 'A needs_attention verdict means open the App Review page for the reason.';

json_response($out);
