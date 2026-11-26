<?php
/**
 * includes/asc_api — talking to the App Store Connect API.
 *
 * Extracted from api/asc_review_status.php so that more than one endpoint
 * can use it. Nothing here changed in the move: the token is still signed
 * ES256 with the .p8 key that lives OUTSIDE public_html, and only its path
 * is in config.
 */

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

/**
 * The report endpoints do NOT return JSON. They return a gzipped TSV, so
 * they need their own fetch: asc_get() would try to decode it as JSON and
 * throw the whole report away.
 */
function asc_get_report(string $path, string $jwt): array {
    $ch = curl_init('https://api.appstoreconnect.apple.com' . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 40,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $jwt,
            'Accept: application/a-gzip, application/json',
        ],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($body === false) return ['ok' => false, 'status' => 0, 'error' => $err];
    if ($code !== 200) {
        $json   = json_decode($body, true);
        $detail = $json['errors'][0]['detail'] ?? $json['errors'][0]['title'] ?? null;
        if ($detail === null) {
            // A 400 can come back as an HTML error page rather than Apple's
            // JSON, so strip the tags instead of pasting markup into ours.
            $detail = trim(preg_replace('/\s+/', ' ', strip_tags((string)$body)));
            $detail = $detail === '' ? ('HTTP ' . $code) : substr($detail, 0, 200);
        }
        return ['ok' => false, 'status' => $code, 'error' => $detail];
    }
    // gzip magic; Apple has been known to send it uncompressed as well
    if (substr($body, 0, 2) === "\x1f\x8b") {
        $plain = @gzdecode($body);
        if ($plain === false) return ['ok' => false, 'status' => 200, 'error' => 'report did not decompress'];
        $body = $plain;
    }
    return ['ok' => true, 'status' => 200, 'tsv' => $body];
}
