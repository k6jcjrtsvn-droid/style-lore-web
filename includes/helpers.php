<?php
/**
 * Shared helpers for every api/*.php endpoint. Every endpoint should
 * require_once this file first (it also requires db.php), then this file's
 * exception/shutdown handlers guarantee the client always gets JSON back —
 * never a raw PHP error page — even if something unexpected throws.
 */

require_once __DIR__ . '/db.php';

// CORS for the native apps. The website itself is same-origin and never
// needs this, but the iOS/Android shells load the bundled index.html from
// their own local origin (capacitor://localhost on iOS, https://localhost
// on Android) and call this API cross-origin. Only those app origins are
// allowed — never "*", because the API reads bearer tokens. OPTIONS
// preflights are answered here (and by api/_cors.php for paths whose
// rewrite rule is method-conditional) so the real request can follow.
cors_headers();

function cors_headers(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed = ['capacitor://localhost', 'https://localhost', 'http://localhost', 'ionic://localhost'];
    if ($origin === '' || !in_array($origin, $allowed, true)) return;
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Admin-Key');
    header('Access-Control-Max-Age: 86400');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}


header('Content-Type: application/json; charset=utf-8');

// Both handlers below log the real detail server-side only. The client
// only ever sees a generic message — an earlier version of this file
// echoed the raw exception/error message straight into the JSON response,
// which handed out internal detail (file paths, DB errors, etc.) to
// whoever happened to trigger the error. error_log() is where the real
// detail belongs; check your hosting's PHP error log to see it.
set_exception_handler(function ($e) {
    error_log('Uncaught exception: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Something went wrong on the server. Try again in a moment.']);
    exit;
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('Fatal error: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
        // A fatal error can happen after headers/output already started in
        // rare cases; guard against a double Content-Type/body.
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['error' => 'Something went wrong on the server. Try again in a moment.']);
    }
});

function json_response($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}

function error_response(string $message, int $status = 400): void {
    json_response(['error' => $message], $status);
}

function require_method(string $method): void {
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        error_response('Method not allowed.', 405);
    }
}

/** Decodes a JSON request body the same way express.json() does; [] on any failure. */
function request_json(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function normalize_email($v): string {
    return strtolower(trim((string)$v));
}

function is_valid_email(string $email): bool {
    return (bool)preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $email);
}

/** RFC 4122 v4 UUID, formatted exactly like crypto.randomUUID()'s output. */
function uuidv4(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function current_time_ms(): int {
    return (int) round(microtime(true) * 1000);
}

/** A fresh random auth token — 64 hex chars, returned to the client exactly
 *  once (at signup, login, or password reset). Never stored raw. */
function new_auth_token(): string {
    return bin2hex(random_bytes(32));
}

/** How a raw token is stored/compared — one-way, so a leaked database
 *  dump alone can't be used to log in as anyone. */
function hash_token(string $token): string {
    return hash('sha256', $token);
}

/**
 * Requires that $token is the current auth token for account $accountId,
 * else halts the request with a 401 — the fix for endpoints that used to
 * trust whatever id the client sent with no way to check it actually
 * belongs to them. Every write that claims an identity (editing a
 * profile, creating a post, following someone, liking a post) should
 * call this before touching the database.
 */
/**
 * The display name + avatar the server holds for an account — used
 * instead of whatever name a client sends along with an action, so one
 * account can't label its DMs/follows/group joins with someone else's name.
 */
function profile_identity(PDO $pdo, string $accountId): array {
    $stmt = $pdo->prepare('SELECT name, avatar_url FROM profiles WHERE id = ?');
    $stmt->execute([$accountId]);
    $row = $stmt->fetch();
    $name = $row ? mb_substr(trim((string)$row['name']), 0, 60) : '';
    return ['name' => $name !== '' ? $name : 'Style-LORE member', 'avatar' => $row ? $row['avatar_url'] : null];
}

/**
 * Requires the account's email to be verified before it can reach other
 * people directly (DMs, groups). Call after require_owner().
 */
function require_verified(PDO $pdo, string $accountId): void {
    $stmt = $pdo->prepare('SELECT email_verified FROM accounts WHERE id = ?');
    $stmt->execute([$accountId]);
    $row = $stmt->fetch();
    if (!$row || !(int)$row['email_verified']) {
        error_response('Verify your email address first — check your inbox for the Style-LORE verification link.', 403);
    }
}

/**
 * Auth token from the "Authorization: Bearer <token>" header — used by
 * GET endpoints so the token never lands in URLs, access logs, or
 * referrers. Falls back to ?authToken= for older clients.
 */
function bearer_token(): string {
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($hdr === '' && function_exists('apache_request_headers')) {
        $all = apache_request_headers();
        $hdr = $all['Authorization'] ?? $all['authorization'] ?? '';
    }
    if (preg_match('/^Bearer\s+(\S+)$/i', trim((string)$hdr), $m)) {
        return $m[1];
    }
    return (string)($_GET['authToken'] ?? '');
}

function require_owner(PDO $pdo, string $accountId, ?string $token): void {
    if ($accountId === '' || !$token) {
        error_response('Missing account id or auth token — try logging in again.', 401);
    }
    $stmt = $pdo->prepare('SELECT auth_token_hash FROM accounts WHERE id = ?');
    $stmt->execute([$accountId]);
    $row = $stmt->fetch();
    if (!$row || !$row['auth_token_hash'] || !hash_equals($row['auth_token_hash'], hash_token($token))) {
        error_response('Not authorized for this account — try logging in again.', 401);
    }
}

/**
 * Fixed-window rate limiter backed by the rate_limits table (see
 * MIGRATE-2026-09-17.sql). Allows $max hits per $windowSeconds for $key,
 * otherwise responds 429 and exits. Keys are things like
 * "login:ip:1.2.3.4" or "signup:email:foo@bar.com". Fails OPEN if the
 * table is missing (pre-migration) so a forgotten migration can't lock
 * everyone out — it just logs once per request.
 */
function rate_limit(PDO $pdo, string $key, int $max, int $windowSeconds, string $message = 'Too many attempts — please wait a bit and try again.'): void {
    $key = substr($key, 0, 160);
    $now = time();
    $ownTxn = !$pdo->inTransaction();
    try {
        $stmt = $pdo->prepare('SELECT hits, window_start FROM rate_limits WHERE rl_key = ? FOR UPDATE');
        if ($ownTxn) $pdo->beginTransaction();
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if (!$row || ($now - (int)$row['window_start']) >= $windowSeconds) {
            $pdo->prepare('INSERT INTO rate_limits (rl_key, hits, window_start) VALUES (?, 1, ?)
                           ON DUPLICATE KEY UPDATE hits = 1, window_start = VALUES(window_start)')->execute([$key, $now]);
            if ($ownTxn) $pdo->commit();
            // Opportunistic cleanup, ~1% of calls: drop windows older than a day.
            if (random_int(1, 100) === 1) {
                $pdo->prepare('DELETE FROM rate_limits WHERE window_start < ?')->execute([$now - 86400]);
            }
            return;
        }
        $hits = (int)$row['hits'] + 1;
        $pdo->prepare('UPDATE rate_limits SET hits = ? WHERE rl_key = ?')->execute([$hits, $key]);
        if ($ownTxn) $pdo->commit();
        if ($hits > $max) {
            header('Retry-After: ' . max(1, $windowSeconds - ($now - (int)$row['window_start'])));
            error_response($message, 429);
        }
    } catch (PDOException $e) {
        if ($ownTxn && $pdo->inTransaction()) $pdo->rollBack();
        error_log('rate_limit unavailable (' . $key . '): ' . $e->getMessage());
    }
}

/** Best-effort client IP for rate limiting (shared hosting behind Apache; no proxy trust). */
function client_ip(): string {
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

/**
 * Sends a plain-text email via PHP's built-in mail() (works out of the box
 * on GoDaddy shared hosting, no SMTP setup needed) — but only if the
 * envelope sender (the "-f" additional parameter) is set to a real mailbox
 * on this domain. Without it, GoDaddy's sendmail uses a default envelope
 * sender (something like the server's own hostname account) that fails
 * SPF for style-lore.com and gets silently dropped by receiving mail
 * servers — mail() still returns true either way, so this failure mode is
 * invisible unless you know to look for it. Confirmed by an earlier
 * diagnostic on this exact box: a plain mail() call with no "-f" never
 * arrived anywhere (inbox or spam), while the same call with "-f
 * no-reply@style-lore.com" (after that mailbox was created in cPanel) did.
 * MAIL_FROM is that same mailbox, so it doubles as the envelope sender.
 *
 * Returns whether mail() reported success — callers should treat a false
 * return as "couldn't send it right now" but generally shouldn't reveal
 * that to the client for auth emails (see auth_forgot.php's
 * anti-enumeration note).
 */
/**
 * The single place mail actually leaves the app.
 *
 * Two transports, chosen at runtime:
 *
 *   1. Resend (HTTP API) -- used whenever RESEND_API_KEY is defined and
 *      non-empty in config.php. This is the intended path. A curl POST with
 *      a hard 10-second timeout means a slow provider fails fast with a
 *      readable error, and Resend's dashboard shows every send and its
 *      delivery status.
 *
 *   2. PHP mail() -- the fallback when no key is configured, so nothing
 *      breaks before the key is set. On this shared host mail() has been
 *      observed to BLOCK for tens of seconds on handoff to Exim rather than
 *      return, which is the whole reason the Resend path exists.
 *
 * Either way the failure reason is recorded in $GLOBALS['last_mail_error']
 * and written to the error log, so the calling endpoint can report it. The
 * original code called @mail(...) and the "@" threw the reason away, which
 * made a broken send undiagnosable.
 *
 * Deliberately a single attempt, no retries: a blocking sender must fail
 * once, not three times. (An earlier retry loop tripled a hang and pushed
 * the whole request past PHP's execution limit.)
 *
 * $html may be null for a plain-text-only message.
 */
function send_mail_tracked(string $to, string $subject, ?string $html, string $text): bool {
    $GLOBALS['last_mail_error'] = null;

    $useResend = defined('RESEND_API_KEY') && RESEND_API_KEY !== '' && function_exists('curl_init');
    return $useResend
        ? send_via_resend($to, $subject, $html, $text)
        : send_via_php_mail($to, $subject, $html, $text);
}

function send_via_resend(string $to, string $subject, ?string $html, string $text): bool {
    $payload = [
        'from'    => 'Style-LORE <' . MAIL_FROM . '>',
        'to'      => [$to],
        'subject' => $subject,
        'text'    => $text,
    ];
    if ($html !== null) $payload['html'] = $html;

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . RESEND_API_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $response = curl_exec($ch);
    $status   = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response !== false && $status >= 200 && $status < 300) {
        return true;
    }

    if ($response === false) {
        $reason = 'Resend: transport error - ' . ($curlErr !== '' ? $curlErr : 'no response');
    } else {
        $decoded = json_decode((string)$response, true);
        $apiMsg  = is_array($decoded) && isset($decoded['message']) ? (string)$decoded['message'] : trim((string)$response);
        $reason  = 'Resend: HTTP ' . $status . ' - ' . ($apiMsg !== '' ? $apiMsg : 'no error body');
    }
    error_log('[style-lore mail] FAILED to=' . $to . ' subject=' . $subject . ' reason=' . $reason);
    $GLOBALS['last_mail_error'] = $reason;
    return false;
}

function send_via_php_mail(string $to, string $subject, ?string $html, string $text): bool {
    if ($html === null) {
        $headers = "From: Style-LORE <" . MAIL_FROM . ">\r\n" .
            "Content-Type: text/plain; charset=utf-8\r\n";
        $body = $text;
    } else {
        // multipart/alternative: clients that prefer or require plain text
        // still get a good experience, and spam filters see a real text part
        // alongside the HTML one.
        $boundary = 'stylelore-' . bin2hex(random_bytes(12));
        $headers = "From: Style-LORE <" . MAIL_FROM . ">\r\n" .
            "MIME-Version: 1.0\r\n" .
            "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";
        $body = "--$boundary\r\n" .
            "Content-Type: text/plain; charset=utf-8\r\n\r\n" .
            $text . "\r\n\r\n" .
            "--$boundary\r\n" .
            "Content-Type: text/html; charset=utf-8\r\n\r\n" .
            $html . "\r\n\r\n" .
            "--$boundary--";
    }

    $before = error_get_last();
    $ok = @mail($to, $subject, $body, $headers, '-f' . MAIL_FROM);
    if ($ok) return true;

    $after = error_get_last();
    $reason = ($after && $after !== $before)
        ? $after['message']
        : 'mail() returned false without raising a PHP error - the local MTA refused, or timed out accepting, the message.';
    error_log('[style-lore mail] FAILED to=' . $to . ' subject=' . $subject . ' reason=' . $reason);
    $GLOBALS['last_mail_error'] = $reason;
    return false;
}

function send_app_email(string $to, string $subject, string $body): bool {
    return send_mail_tracked($to, $subject, null, $body);
}

/**
 * Sends a branded HTML email with a plain-text fallback. Both parts are
 * passed through separately so the Resend path can hand them over as
 * fields and the mail() path can assemble multipart/alternative.
 */
function send_app_html_email(string $to, string $subject, string $html, string $textFallback): bool {
    return send_mail_tracked($to, $subject, $html, $textFallback);
}

/**
 * Wraps content in Style-LORE's branded HTML email shell: logo header (the
 * same emblem used as the app's apple-touch-icon, hosted at
 * SITE_BASE_URL . '/email-assets/logo.png' so email clients that block
 * inline/data-URI images still render it), a heading, body copy, an
 * optional call-to-action button, and a footer note. $bodyHtml and
 * $footerNote are raw HTML -- callers must htmlspecialchars() any
 * user-supplied text themselves before interpolating it in.
 */
function style_lore_email_html(string $heading, string $bodyHtml, ?string $buttonText, ?string $buttonUrl, string $footerNote): string {
    $logoUrl = SITE_BASE_URL . '/email-assets/logo.png';
    $accent = '#C92C69';
    $accentSoft = '#F7D9E5';
    $ink = '#2E1620';
    $inkSoft = '#6C4C56';
    $paper = '#FBF0F3';

    $buttonHtml = '';
    if ($buttonText !== null && $buttonUrl !== null) {
        $buttonHtml = '
              <table role="presentation" cellpadding="0" cellspacing="0" style="margin:28px auto 8px;">
                <tr>
                  <td style="border-radius:999px; background:' . $accent . ';">
                    <a href="' . htmlspecialchars($buttonUrl) . '" style="display:inline-block; padding:14px 34px; font-family:Georgia, \'Times New Roman\', serif; font-size:16px; font-weight:bold; color:#FFF7F9; text-decoration:none; border-radius:999px;">' . htmlspecialchars($buttonText) . '</a>
                  </td>
                </tr>
              </table>';
    }

    return '<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Style-LORE</title>
</head>
<body style="margin:0; padding:0; background:' . $paper . '; font-family: Georgia, \'Times New Roman\', serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . $paper . ';">
    <tr>
      <td align="center" style="padding:32px 16px;">
        <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="max-width:480px; width:100%; background:#FFF8F9; border-radius:16px; overflow:hidden; border:1px solid #EACBD3;">
          <tr>
            <td style="background:' . $accent . '; padding:28px 32px; text-align:center;">
              <table role="presentation" cellpadding="0" cellspacing="0" align="center" style="margin:0 auto 10px;">
                <tr>
                  <td width="64" height="64" style="width:64px; height:64px; background:#FFF7F9; border-radius:50%; text-align:center; vertical-align:middle;">
                    <img src="' . $logoUrl . '" width="44" height="44" alt="Style-LORE" style="display:block; margin:10px auto; border-radius:50%;">
                  </td>
                </tr>
              </table>
              <div style="font-family:Georgia, \'Times New Roman\', serif; font-size:22px; font-weight:bold; color:#FFF7F9; letter-spacing:0.5px;">Style-LORE</div>
            </td>
          </tr>
          <tr>
            <td style="padding:32px;">
              <h1 style="margin:0 0 16px; font-size:20px; color:' . $ink . '; font-family:Georgia, \'Times New Roman\', serif;">' . htmlspecialchars($heading) . '</h1>
              <div style="font-size:15px; line-height:1.6; color:' . $ink . ';">' . $bodyHtml . '</div>
              ' . $buttonHtml . '
              <p style="font-size:13px; color:' . $inkSoft . '; line-height:1.5; margin:24px 0 0;">' . $footerNote . '</p>
            </td>
          </tr>
          <tr>
            <td style="padding:18px 32px; background:' . $accentSoft . '; text-align:center;">
              <p style="margin:0; font-size:12px; color:' . $inkSoft . ';">Style-LORE &mdash; find your Kibbe style</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>';
}

/** A post is hidden once this many distinct people have reported it —
 *  see post_report.php and the `reports` table. */
const REPORT_HIDE_THRESHOLD = 3;

/** Free-tier cap on closet items (see api/closet.php) -- Style-LORE
 *  Premium accounts (has_premium()) are unlimited. */
const CLOSET_FREE_LIMIT = 20;

/**
 * Whether $accountId currently has an active Style-LORE Premium
 * subscription, per the `subscriptions` table -- kept in sync by
 * RevenueCat's webhook (api/revenuecat_webhook.php), not queried live
 * against RevenueCat's API on every request, so this stays fast and keeps
 * working even if RevenueCat is briefly unreachable. A missing row means
 * "never subscribed" (the common case), not an error. expires_at is
 * belt-and-suspenders: RevenueCat already sends an EXPIRATION webhook
 * event that flips is_premium to 0, but checking the timestamp here too
 * means a missed/delayed webhook can't leave someone premium forever.
 */
function has_premium(PDO $pdo, string $accountId): bool {
    if ($accountId === '') return false;
    $stmt = $pdo->prepare('SELECT is_premium, expires_at FROM subscriptions WHERE account_id = ?');
    $stmt->execute([$accountId]);
    $row = $stmt->fetch();
    if (!$row || !$row['is_premium']) return false;
    if ($row['expires_at'] !== null && (int)$row['expires_at'] < current_time_ms()) return false;
    return true;
}

/** HMAC token for the one-click weekly-email unsubscribe link. */
function digest_unsub_token(string $accountId): string {
    $secret = defined('CRON_KEY') && CRON_KEY !== '' ? CRON_KEY : (defined('DB_PASS') ? DB_PASS : 'style-lore');
    return substr(hash_hmac('sha256', 'digest:' . $accountId, $secret), 0, 32);
}

/** Adds the weekly-email columns if a deploy got ahead of the migration. Cheap, idempotent, MariaDB. */
function ensure_digest_columns(PDO $pdo): void {
    static $done = false; if ($done) return; $done = true;
    try { $pdo->exec('ALTER TABLE accounts ADD COLUMN IF NOT EXISTS digest_opt_out TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN IF NOT EXISTS digest_sent_at BIGINT DEFAULT NULL'); }
    catch (Throwable $e) { error_log('ensure_digest_columns: ' . $e->getMessage()); }
}

function digest_opt_out(PDO $pdo, string $accountId): bool {
    ensure_digest_columns($pdo);
    try { $st = $pdo->prepare('SELECT digest_opt_out FROM accounts WHERE id = ?'); $st->execute([$accountId]); return (bool)$st->fetchColumn(); }
    catch (Throwable $e) { return false; }
}

/** 'stripe' | 'play' | null — which billing system owns this account's subscription row. */
function subscription_billing_source(PDO $pdo, string $accountId): ?string {
    try { $st = $pdo->prepare('SELECT product_id FROM subscriptions WHERE account_id = ?'); $st->execute([$accountId]); $p = (string)($st->fetchColumn() ?: ''); }
    catch (Throwable $e) { return null; }
    if ($p === '') return null;
    return strpos($p, 'stripe') === 0 ? 'stripe' : 'play';
}

const FREE_AI_READS = 1;

/** How many free AI Stylist reads this account still has (see ai_reads). */
function free_ai_reads_left(PDO $pdo, string $accountId): int {
    if ($accountId === '') return 0;
    try {
        $stmt = $pdo->prepare('SELECT used FROM ai_reads WHERE account_id = ?');
        $stmt->execute([$accountId]);
        $used = (int)($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        error_log('free_ai_reads_left: ' . $e->getMessage());
        return 0;
    }
    return max(0, FREE_AI_READS - $used);
}

function record_ai_read(PDO $pdo, string $accountId): void {
    try {
        $pdo->prepare('INSERT INTO ai_reads (account_id, used, last_at) VALUES (?, 1, ?)
                       ON DUPLICATE KEY UPDATE used = used + 1, last_at = VALUES(last_at)')
            ->execute([$accountId, current_time_ms()]);
    } catch (Throwable $e) {
        error_log('record_ai_read: ' . $e->getMessage());
    }
}


/**
 * Saves an uploaded file (one $_FILES entry) into web/uploads/$subdir with
 * a random filename, mirroring the local Node app's multer config (30MB
 * cap, image/* or video/* mimetype enforcement per field). Returns the
 * public "/uploads/..." URL, or null if that field wasn't sent. Throws a
 * RuntimeException with a user-facing message on any validation failure —
 * callers should catch and turn that into a 400 the same way the old
 * multer error-handling middleware did.
 */
function save_upload(string $field, string $subdir, string $requiredMimePrefix, string $friendlyName): ?string {
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $file = $_FILES[$field];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            throw new RuntimeException($friendlyName . ' is too large (max 30MB).');
        }
        throw new RuntimeException('Upload failed — try again.');
    }
    if ($file['size'] > 30 * 1024 * 1024) {
        throw new RuntimeException($friendlyName . ' is too large (max 30MB).');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']) ?: '';
    finfo_close($finfo);
    if (strpos($mime, $requiredMimePrefix) !== 0) {
        throw new RuntimeException($friendlyName . ' must be a ' . rtrim($requiredMimePrefix, '/') . ' file.');
    }

    // The extension is derived from the *sniffed* MIME type only — never from
    // the client-supplied filename — so a polyglot "image.php" can never land
    // on disk with an executable extension.
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'video/quicktime' => 'mov',
    ];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException($friendlyName . ' must be a JPG, PNG, WebP, GIF, MP4, WebM or MOV file.');
    }
    $ext = '.' . $allowed[$mime];

    $dir = __DIR__ . '/../uploads/' . $subdir;
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $filename = bin2hex(random_bytes(16)) . $ext;
    $dest = $dir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('Could not save the uploaded file — check that the uploads/ folder is writable.');
    }
    return '/uploads/' . $subdir . '/' . $filename;
}

/**
 * Reassembles one post row into the exact JSON shape the frontend already
 * expects from the old Express API — likes as a {visitorId: bool} map,
 * comments as an array, styleTags decoded back into a real array, and
 * moderation-only fields (hidden/reportCount) stripped, matching
 * publicPost() in the local server.
 */
function post_to_public(PDO $pdo, array $row): array {
    $likesStmt = $pdo->prepare('SELECT visitor_id, liked FROM likes WHERE post_id = ?');
    $likesStmt->execute([$row['id']]);
    $likes = [];
    foreach ($likesStmt->fetchAll() as $l) {
        $likes[$l['visitor_id']] = (bool)$l['liked'];
    }

    $commentsStmt = $pdo->prepare('SELECT author_name, text, created_at FROM comments WHERE post_id = ? ORDER BY id ASC');
    $commentsStmt->execute([$row['id']]);
    $comments = array_map(function ($c) {
        return [
            'authorName' => $c['author_name'],
            'text' => $c['text'],
            'createdAt' => (int)$c['created_at'],
        ];
    }, $commentsStmt->fetchAll());

    $styleTags = json_decode($row['style_tags'] ?: '[]', true);
    if (!is_array($styleTags)) $styleTags = [];

    return [
        'id' => $row['id'],
        'authorName' => $row['author_name'],
        'authorId' => $row['author_id'],
        'authorAvatarUrl' => $row['author_avatar_url'],
        'kibbeTag' => $row['kibbe_tag'],
        'styleTags' => array_values($styleTags),
        'caption' => $row['caption'],
        'photoUrl' => $row['photo_url'],
        'videoFileUrl' => $row['video_file_url'],
        'videoUrl' => $row['video_url'],
        'createdAt' => (int)$row['created_at'],
        'likes' => $likes,
        'comments' => $comments,
        'groupId' => $row['group_id'] ?? null,
    ];
}

/* =========================================================================
 * Community/social features phase — Notifications, Direct Messages,
 * Stories, Groups. Shared helpers used by more than one api/*.php file
 * live here, same as post_to_public() above.
 * ========================================================================= */

/**
 * Records one notification for $recipientId. Never notifies someone
 * about their own action (e.g. liking your own post) — every call site
 * should still avoid calling this for self-actions, but this is a last
 * line of defense. $actorId is nullable: api/post_comments.php has no
 * verified account id for the commenter (see its own comments), so a
 * comment notification shows actor_name but can't be tapped through to a
 * profile. $data is a small associative array JSON-encoded for the
 * frontend to decide what tapping this notification should do (e.g.
 * ['conversationId' => ..., 'otherId' => ...] for a message).
 */
function create_notification(PDO $pdo, string $recipientId, ?string $actorId, string $actorName, ?string $actorAvatarUrl, string $type, string $message, ?array $data = null, int $dedupeSeconds = 0): void {
    if ($recipientId === '' || $recipientId === $actorId) return;
    // Flip-flop protection: liking/unliking/re-liking (or follow/unfollow/
    // refollow) shouldn't re-ping the recipient. When $dedupeSeconds > 0,
    // an identical (recipient, actor, type, data) notification within that
    // window means we skip creating another one.
    if ($dedupeSeconds > 0 && $actorId !== null) {
        $dup = $pdo->prepare('SELECT id FROM notifications WHERE recipient_id = ? AND actor_id = ? AND type = ? AND created_at > ? AND data <=> ? LIMIT 1');
        $dup->execute([$recipientId, $actorId, $type, current_time_ms() - $dedupeSeconds * 1000, $data !== null ? json_encode($data) : null]);
        if ($dup->fetch()) return;
    }
    $stmt = $pdo->prepare(
        'INSERT INTO notifications (id, recipient_id, actor_id, actor_name, actor_avatar_url, type, message, data, created_at, is_read)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0)'
    );
    $stmt->execute([
        uuidv4(), $recipientId, $actorId ?: null, mb_substr($actorName, 0, 60), $actorAvatarUrl,
        $type, mb_substr($message, 0, 200), $data ? json_encode($data) : null, current_time_ms(),
    ]);

    // Also raise a real phone notification, if this account has any
    // registered devices (api/device_tokens.php) and Firebase is
    // configured (see send_push_notification() below) -- mirrors the same
    // `type`/`data` shape the in-app NotificationsScreen already uses to
    // decide what tapping a notification should open, so a push tap and an
    // in-app tap land in the same place. Never lets a push failure affect
    // the in-app notification already written above.
    send_push_notification($pdo, $recipientId, 'Style-LORE', mb_substr($message, 0, 180), array_merge(['type' => $type], $data ?: []));
}

/**
 * Sends a native push notification via Firebase Cloud Messaging's HTTP v1
 * API to every device this account has registered (see
 * api/device_tokens.php and the `device_tokens` table). Called from
 * create_notification() right above, right after the in-app notification
 * row is committed -- same "gracefully do nothing until configured" pattern
 * as the Anthropic-backed photo checker: leave FIREBASE_PROJECT_ID or
 * FIREBASE_SERVICE_ACCOUNT_PATH blank and this silently no-ops, no error
 * surfaced anywhere.
 *
 * Uses a hand-rolled service-account OAuth2 exchange (see
 * fcm_access_token() below) rather than the Firebase Admin SDK -- this
 * codebase has no Composer/dependency setup anywhere else, so everything
 * here is plain PHP + curl + openssl, matching every other integration in
 * this file.
 *
 * A push-send failure NEVER throws or bubbles up -- the in-app notification
 * is already committed to the database by the time this runs, and it must
 * never be affected just because a phone notification didn't go out (no
 * devices registered, Firebase not configured yet, a transient network
 * error, etc.).
 */
function send_push_notification(PDO $pdo, string $recipientId, string $title, string $body, ?array $data = null): void {
    if (!defined('FIREBASE_PROJECT_ID') || !FIREBASE_PROJECT_ID) return;
    if (!defined('FIREBASE_SERVICE_ACCOUNT_PATH') || !FIREBASE_SERVICE_ACCOUNT_PATH || !is_readable(FIREBASE_SERVICE_ACCOUNT_PATH)) return;

    try {
        $tokensStmt = $pdo->prepare('SELECT token FROM device_tokens WHERE account_id = ?');
        $tokensStmt->execute([$recipientId]);
        $tokens = $tokensStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!$tokens) return;

        $accessToken = fcm_access_token();
        if (!$accessToken) return;

        foreach ($tokens as $token) {
            $payload = [
                'message' => [
                    'token' => $token,
                    'notification' => ['title' => mb_substr($title, 0, 60), 'body' => mb_substr($body, 0, 180)],
                    'data' => array_map('strval', $data ?: []),
                    'android' => ['priority' => 'high'],
                ],
            ];
            $ch = curl_init('https://fcm.googleapis.com/v1/projects/' . FIREBASE_PROJECT_ID . '/messages:send');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_TIMEOUT => 8,
            ]);
            $response = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($status === 404) {
                // Token no longer valid (app uninstalled, etc.) -- drop it so we
                // stop trying every time this account gets a new notification.
                $pdo->prepare('DELETE FROM device_tokens WHERE token = ?')->execute([$token]);
            } elseif ($status >= 400) {
                $decoded = json_decode((string)$response, true);
                $errCode = $decoded['error']['details'][0]['errorCode'] ?? null;
                if ($errCode === 'UNREGISTERED') {
                    $pdo->prepare('DELETE FROM device_tokens WHERE token = ?')->execute([$token]);
                } else {
                    error_log('FCM send failed (' . $status . '): ' . $response);
                }
            }
        }
    } catch (Throwable $e) {
        error_log('send_push_notification error: ' . $e->getMessage());
    }
}

/**
 * Exchanges the Firebase service-account key for a short-lived OAuth2
 * access token -- a self-signed JWT (RS256, the standard service-account
 * grant flow), traded for a bearer token at Google's token endpoint. No
 * external library needed, just openssl_sign() + curl. Fetched fresh on
 * every call rather than cached: this app's notification volume is low
 * enough that the extra round trip isn't worth the complexity/staleness
 * risk of a shared token cache across requests.
 */
function fcm_access_token(): ?string {
    $json = json_decode((string)file_get_contents(FIREBASE_SERVICE_ACCOUNT_PATH), true);
    if (!$json || empty($json['client_email']) || empty($json['private_key'])) return null;

    $now = time();
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $claims = [
        'iss' => $json['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600,
    ];
    $b64url = function (string $v): string {
        return rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
    };
    $segments = $b64url(json_encode($header)) . '.' . $b64url(json_encode($claims));
    $signature = '';
    if (!openssl_sign($segments, $signature, $json['private_key'], 'SHA256')) return null;
    $jwt = $segments . '.' . $b64url($signature);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]),
        CURLOPT_TIMEOUT => 8,
    ]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status !== 200) {
        error_log('FCM token exchange failed (' . $status . '): ' . $response);
        return null;
    }
    $decoded = json_decode((string)$response, true);
    return $decoded['access_token'] ?? null;
}

function notification_to_public(array $row): array {
    $data = null;
    if (!empty($row['data'])) {
        $decoded = json_decode($row['data'], true);
        if (is_array($decoded)) $data = $decoded;
    }
    return [
        'id' => $row['id'],
        'actorId' => $row['actor_id'],
        'actorName' => $row['actor_name'],
        'actorAvatarUrl' => $row['actor_avatar_url'],
        'type' => $row['type'],
        'message' => $row['message'],
        'data' => $data,
        'createdAt' => (int)$row['created_at'],
        'read' => (bool)$row['is_read'],
    ];
}

function message_to_public(array $row): array {
    return [
        'id' => $row['id'],
        'conversationId' => $row['conversation_id'],
        'senderId' => $row['sender_id'],
        'senderName' => $row['sender_name'],
        'text' => $row['text'],
        'createdAt' => (int)$row['created_at'],
    ];
}

function story_to_public(array $row, bool $viewed): array {
    return [
        'id' => $row['id'],
        'authorId' => $row['author_id'],
        'authorName' => $row['author_name'],
        'authorAvatarUrl' => $row['author_avatar_url'],
        'mediaUrl' => $row['media_url'],
        'mediaType' => $row['media_type'],
        'caption' => $row['caption'],
        'createdAt' => (int)$row['created_at'],
        'expiresAt' => (int)$row['expires_at'],
        'viewed' => $viewed,
    ];
}

/**
 * One closet item as returned to the frontend. photo_data is the same
 * downscaled base64 data: URL the client already renders directly via
 * <img src>, stored as-is (no server-side re-encoding, no separate file on
 * disk) — see api/closet.php for why this table is a wholesale
 * replace-on-save mirror of the client's local closet array rather than a
 * normal CRUD resource. visibility/authorName are per-item (each closet
 * item is individually Hidden or Public — see Kenneth's 2026-09-14
 * feedback that a single account-wide toggle wasn't granular enough).
 */
function closet_item_to_public(array $row): array {
    return [
        'id' => $row['id'],
        'description' => $row['description'],
        'photo' => $row['photo_data'],
        'createdAt' => (int)$row['created_at'],
        'visibility' => $row['visibility'] ?? 'hidden',
        'authorName' => $row['author_name'] ?? '',
        'authorId' => $row['account_id'] ?? null,
    ];
}

function group_to_public(array $row, ?bool $isMember = null): array {
    $out = [
        'id' => $row['id'],
        'name' => $row['name'],
        'description' => $row['description'],
        'topicKibbe' => $row['topic_kibbe'],
        'topicStyle' => $row['topic_style'],
        'creatorId' => $row['creator_id'],
        'creatorName' => $row['creator_name'],
        'memberCount' => (int)$row['member_count'],
        'createdAt' => (int)$row['created_at'],
    ];
    if ($isMember !== null) $out['isMember'] = $isMember;
    return $out;
}
