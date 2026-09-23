<?php
/**
 * Shared helpers for every api/*.php endpoint. Every endpoint should
 * require_once this file first (it also requires db.php), then this file's
 * exception/shutdown handlers guarantee the client always gets JSON back —
 * never a raw PHP error page — even if something unexpected throws.
 */

require_once __DIR__ . '/db.php';

// Where replies to any Style-LORE email land, and the address shown for
// support on the site. A cPanel forwarder delivers it to Kenneth's inbox.
if (!defined('SUPPORT_EMAIL')) define('SUPPORT_EMAIL', 'support@style-lore.com');

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

/* ------------------------------------------------------------------
 * Multi-device sessions (2026-09-15).
 * Until now an account had ONE auth token (accounts.auth_token_hash), rotated
 * on every login — so logging in on the phone silently signed the website
 * out (every write from the older device came back 401: closet sync failed,
 * the weekly-email checkbox snapped back, and so on). Now each login adds a
 * row to auth_tokens and every row stays valid until a password reset,
 * account deletion, or the cap below evicts the oldest. accounts.auth_token_hash
 * is kept as the most recent token for backwards compatibility.
 * ------------------------------------------------------------------ */
const AUTH_TOKENS_PER_ACCOUNT = 10;

function ensure_auth_tokens_table(PDO $pdo): void {
    static $done = false; if ($done) return; $done = true;
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS auth_tokens (
            token_hash CHAR(64) NOT NULL PRIMARY KEY,
            account_id CHAR(36) NOT NULL,
            created_at BIGINT NOT NULL,
            last_used_at BIGINT NOT NULL,
            label VARCHAR(40) DEFAULT NULL,
            INDEX idx_account (account_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    } catch (Throwable $e) { error_log('ensure_auth_tokens_table: ' . $e->getMessage()); }
}

/** Issues a new session token for $accountId and returns it (plain, to hand to the client).
 *  Keeps the previous single token alive by copying it into auth_tokens first. */
function issue_auth_token(PDO $pdo, string $accountId, ?string $label = null): string {
    ensure_auth_tokens_table($pdo);
    $now = current_time_ms();
    try {
        // Preserve whatever device is currently signed in via the legacy column.
        $st = $pdo->prepare('SELECT auth_token_hash FROM accounts WHERE id = ?'); $st->execute([$accountId]);
        $legacy = (string)($st->fetchColumn() ?: '');
        if ($legacy !== '') {
            $pdo->prepare('INSERT IGNORE INTO auth_tokens (token_hash, account_id, created_at, last_used_at, label) VALUES (?,?,?,?,?)')
                ->execute([$legacy, $accountId, $now, $now, 'previous']);
        }
    } catch (Throwable $e) { error_log('issue_auth_token preserve: ' . $e->getMessage()); }
    $token = new_auth_token();
    $hash = hash_token($token);
    $pdo->prepare('UPDATE accounts SET auth_token_hash = ? WHERE id = ?')->execute([$hash, $accountId]);
    try {
        $pdo->prepare('INSERT INTO auth_tokens (token_hash, account_id, created_at, last_used_at, label) VALUES (?,?,?,?,?)')
            ->execute([$hash, $accountId, $now, $now, $label ? mb_substr($label, 0, 40) : null]);
        // Cap sessions per account: evict the least recently used beyond the cap.
        $st = $pdo->prepare('SELECT token_hash FROM auth_tokens WHERE account_id = ? ORDER BY last_used_at DESC');
        $st->execute([$accountId]);
        $all = $st->fetchAll(PDO::FETCH_COLUMN);
        foreach (array_slice($all, AUTH_TOKENS_PER_ACCOUNT) as $old) {
            $pdo->prepare('DELETE FROM auth_tokens WHERE token_hash = ?')->execute([$old]);
        }
    } catch (Throwable $e) { error_log('issue_auth_token insert: ' . $e->getMessage()); }
    return $token;
}

/** Signs every device out (password reset, account deletion). */
function revoke_all_auth_tokens(PDO $pdo, string $accountId): void {
    ensure_auth_tokens_table($pdo);
    try { $pdo->prepare('DELETE FROM auth_tokens WHERE account_id = ?')->execute([$accountId]); } catch (Throwable $e) {}
}

function require_owner(PDO $pdo, string $accountId, ?string $token): void {
    if ($accountId === '' || !$token) {
        error_response('Missing account id or auth token — try logging in again.', 401);
    }
    $hash = hash_token($token);
    $stmt = $pdo->prepare('SELECT auth_token_hash FROM accounts WHERE id = ?');
    $stmt->execute([$accountId]);
    $row = $stmt->fetch();
    if (!$row) error_response('Not authorized for this account — try logging in again.', 401);
    if ($row['auth_token_hash'] && hash_equals($row['auth_token_hash'], $hash)) return;
    // Any other device this account is signed in on.
    ensure_auth_tokens_table($pdo);
    try {
        $st = $pdo->prepare('SELECT 1 FROM auth_tokens WHERE token_hash = ? AND account_id = ?');
        $st->execute([$hash, $accountId]);
        if ($st->fetchColumn()) {
            // Touch at most once a minute to keep the eviction order meaningful without a write per request.
            $pdo->prepare('UPDATE auth_tokens SET last_used_at = ? WHERE token_hash = ? AND last_used_at < ?')
                ->execute([current_time_ms(), $hash, current_time_ms() - 60000]);
            return;
        }
    } catch (Throwable $e) { error_log('require_owner auth_tokens: ' . $e->getMessage()); }
    error_response('Not authorized for this account — try logging in again.', 401);
}

/**
 * Fixed-window rate limiter backed by the rate_limits table (see
 * MIGRATE-2026-09-17.sql). Allows $max hits per $windowSeconds for $key,
 * otherwise responds 429 and exits. Keys are things like
 * "login:ip:1.2.3.4" or "signup:email:foo@bar.com". Fails OPEN if the
 * table is missing (pre-migration) so a forgotten migration can't lock
 * everyone out — it just logs once per request.
 */
function rate_limit(PDO $pdo, string $key, int $max, int $windowSeconds, string $message = 'Too many attempts — please wait a bit and try again.', bool $retried = false): void {
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
        // An older rate_limits table (bucket/identifier/created_at, from the
        // pre-hardening limiter) makes MIGRATE-2026-09-17's CREATE TABLE IF
        // NOT EXISTS a no-op, so the new columns never appear. The counters
        // are throwaway, so rebuild the table once and carry on.
        if (!$retried && strpos($e->getMessage(), "Unknown column") !== false) {
            try {
                $pdo->exec('DROP TABLE IF EXISTS rate_limits');
                $pdo->exec('CREATE TABLE rate_limits (rl_key VARCHAR(160) NOT NULL PRIMARY KEY, hits INT NOT NULL DEFAULT 0, window_start BIGINT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
                error_log('rate_limit: rebuilt rate_limits with the hardened schema');
                rate_limit($pdo, $key, $max, $windowSeconds, $message, true);
                return;
            } catch (PDOException $e2) {
                error_log('rate_limit rebuild failed: ' . $e2->getMessage());
            }
        }
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
        'reply_to' => SUPPORT_EMAIL,
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
        $headers = "From: Style-LORE <" . MAIL_FROM . ">\r\nReply-To: " . SUPPORT_EMAIL . "\r\n" .
            "Content-Type: text/plain; charset=utf-8\r\n";
        $body = $text;
    } else {
        // multipart/alternative: clients that prefer or require plain text
        // still get a good experience, and spam filters see a real text part
        // alongside the HTML one.
        $boundary = 'stylelore-' . bin2hex(random_bytes(12));
        $headers = "From: Style-LORE <" . MAIL_FROM . ">\r\nReply-To: " . SUPPORT_EMAIL . "\r\n" .
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
const CLOSET_FREE_LIMIT = 100; // was 20 until 2026-09-15: hard caps are the #1 complaint in every closet-app review; Premium sells on the stylist + gap list, not storage

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
    if ($row && $row['is_premium'] && ($row['expires_at'] === null || (int)$row['expires_at'] >= current_time_ms())) return true;
    // No paid plan (or it lapsed): free months earned through referrals count.
    return referral_premium_until($pdo, $accountId) > current_time_ms();
}

/* ---------------------------------------------------------------------
   Referral program — "give a friend a month, get a month".
   accounts.referral_code   short shareable code (made on first request)
   accounts.referred_by     id of the account whose link they signed up from
   accounts.referral_until  ms timestamp: free Premium earned via referrals
                            runs until this moment (banked; used whenever
                            there's no paid plan, so it stacks after one)
   accounts.referral_count  friends who joined and verified
   accounts.referral_rewarded  1 once this account's referrer got their month
--------------------------------------------------------------------- */
const REFERRAL_DAYS = 30;
const REFERRAL_MAX_PER_YEAR = 12;

function ensure_referral_columns(PDO $pdo): void {
    static $done = false; if ($done) return; $done = true;
    try { $pdo->exec('ALTER TABLE accounts ADD COLUMN IF NOT EXISTS referral_code VARCHAR(12) DEFAULT NULL, ADD COLUMN IF NOT EXISTS referred_by CHAR(36) DEFAULT NULL, ADD COLUMN IF NOT EXISTS referral_until BIGINT DEFAULT NULL, ADD COLUMN IF NOT EXISTS referral_count INT NOT NULL DEFAULT 0, ADD COLUMN IF NOT EXISTS referral_rewarded TINYINT(1) NOT NULL DEFAULT 0, ADD UNIQUE INDEX IF NOT EXISTS idx_referral_code (referral_code)'); }
    catch (Throwable $e) { error_log('ensure_referral_columns: ' . $e->getMessage()); }
}

function referral_premium_until(PDO $pdo, string $accountId): int {
    ensure_referral_columns($pdo);
    try { $st = $pdo->prepare('SELECT referral_until FROM accounts WHERE id = ?'); $st->execute([$accountId]); $v = $st->fetchColumn(); return $v ? (int)$v : 0; }
    catch (Throwable $e) { return 0; }
}

/** This account's share code, generated on first use. Letters/digits that can't be misread. */
function referral_code_for(PDO $pdo, string $accountId): ?string {
    ensure_referral_columns($pdo);
    try {
        $st = $pdo->prepare('SELECT referral_code FROM accounts WHERE id = ?'); $st->execute([$accountId]); $code = $st->fetchColumn();
        if ($code === false) return null;
        if ($code) return (string)$code;
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        for ($try = 0; $try < 5; $try++) {
            $code = ''; for ($i = 0; $i < 7; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            try { $pdo->prepare('UPDATE accounts SET referral_code = ? WHERE id = ? AND referral_code IS NULL')->execute([$code, $accountId]); return $code; }
            catch (PDOException $e) { /* collision — try another */ }
        }
    } catch (Throwable $e) { error_log('referral_code_for: ' . $e->getMessage()); }
    return null;
}

function account_id_for_referral_code(PDO $pdo, string $code): ?string {
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    if ($code === '') return null;
    ensure_referral_columns($pdo);
    try { $st = $pdo->prepare('SELECT id FROM accounts WHERE referral_code = ?'); $st->execute([$code]); $id = $st->fetchColumn(); return $id ? (string)$id : null; }
    catch (Throwable $e) { return null; }
}

/** Adds REFERRAL_DAYS of free Premium to an account (stacked after any existing free period). */
function referral_grant_days(PDO $pdo, string $accountId, int $days): int {
    $now = current_time_ms();
    $current = referral_premium_until($pdo, $accountId);
    $until = max($now, $current) + $days * 86400 * 1000;
    $pdo->prepare('UPDATE accounts SET referral_until = ? WHERE id = ?')->execute([$until, $accountId]);
    return $until;
}

/**
 * Called when a referred account verifies its email: gives the referrer
 * their month (once per referred account, at most REFERRAL_MAX_PER_YEAR a
 * year) and emails them. Quietly does nothing if there's no referrer.
 */
function referral_reward_referrer(PDO $pdo, string $referredId): void {
    ensure_referral_columns($pdo);
    try {
        $st = $pdo->prepare('SELECT referred_by, referral_rewarded FROM accounts WHERE id = ?'); $st->execute([$referredId]); $row = $st->fetch();
        if (!$row || empty($row['referred_by']) || (int)$row['referral_rewarded'] === 1) return;
        $referrer = (string)$row['referred_by'];
        $pdo->prepare('UPDATE accounts SET referral_rewarded = 1 WHERE id = ?')->execute([$referredId]);
        $st = $pdo->prepare('SELECT a.email, a.referral_count, p.name FROM accounts a LEFT JOIN profiles p ON p.id = a.id WHERE a.id = ?'); $st->execute([$referrer]); $r = $st->fetch();
        if (!$r) return;
        $count = (int)$r['referral_count'] + 1;
        $pdo->prepare('UPDATE accounts SET referral_count = ? WHERE id = ?')->execute([$count, $referrer]);
        if ($count > REFERRAL_MAX_PER_YEAR) return; // cap; the friend still gets their month
        $until = referral_grant_days($pdo, $referrer, REFERRAL_DAYS);
        $untilText = date('F j, Y', (int)($until / 1000));
        $first = trim(explode(' ', (string)($r['name'] ?? ''))[0] ?? '');
        $heading = ($first ? $first . ', ' : '') . 'a friend just joined — you earned a month of Premium';
        $body = '<p style="margin:0 0 14px;">Someone signed up with your invite link and verified their email, so <b>a free month of Style-LORE Premium is now on your account</b>.</p>'
              . '<p style="margin:0 0 14px;">If you\'re on the free plan, Premium is on right now through <b>' . htmlspecialchars($untilText) . '</b>. If you already pay for Premium, the month is banked and kicks in whenever your paid plan ends — you can keep stacking them (up to 12 a year).</p>'
              . '<p style="margin:0;">Invite another friend from Home → <b>Invite a friend</b>.</p>';
        $html = style_lore_email_html($heading, $body, 'Open Style-LORE', SITE_BASE_URL . '/', 'You get this because someone joined Style-LORE with your invite link.');
        $text = $heading . "\n\nSomeone signed up with your invite link and verified their email, so a free month of Premium is now on your account (through " . $untilText . " if you're on the free plan; banked after your paid plan otherwise).\n\nOpen Style-LORE: " . SITE_BASE_URL . "/\n";
        if (!empty($r['email'])) send_mail_tracked($r['email'], 'A friend joined — you earned a month of Premium', $html, $text);
    } catch (Throwable $e) { error_log('referral_reward_referrer: ' . $e->getMessage()); }
}

/** HMAC token for the one-click weekly-email unsubscribe link. */
function digest_unsub_token(string $accountId): string {
    $secret = defined('CRON_KEY') && CRON_KEY !== '' ? CRON_KEY : (defined('DB_PASS') ? DB_PASS : 'style-lore');
    return substr(hash_hmac('sha256', 'digest:' . $accountId, $secret), 0, 32);
}

/** profiles.color_result_json — the color-season quiz result, synced like kibbe_result_json. Lazy, idempotent. */
function ensure_color_result_column(PDO $pdo): void {
    static $done = false; if ($done) return; $done = true;
    try { $pdo->exec('ALTER TABLE profiles ADD COLUMN IF NOT EXISTS color_result_json MEDIUMTEXT DEFAULT NULL'); }
    catch (Throwable $e) { error_log('ensure_color_result_column: ' . $e->getMessage()); }
}

/** Adds the weekly-email columns if a deploy got ahead of the migration. Cheap, idempotent, MariaDB. */
function ensure_digest_columns(PDO $pdo): void {
    static $done = false; if ($done) return; $done = true;
    try { $pdo->exec('ALTER TABLE accounts ADD COLUMN IF NOT EXISTS digest_opt_out TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN IF NOT EXISTS digest_sent_at BIGINT DEFAULT NULL, ADD COLUMN IF NOT EXISTS morning_push_opt_out TINYINT(1) NOT NULL DEFAULT 0'); }
    catch (Throwable $e) { error_log('ensure_digest_columns: ' . $e->getMessage()); }
}

/** 8 AM "Today's outfit" push (api/cron_morning_push.php): per-account opt-out. */
function morning_push_opt_out(PDO $pdo, string $accountId): bool {
    ensure_digest_columns($pdo);
    try { $st = $pdo->prepare('SELECT morning_push_opt_out FROM accounts WHERE id = ?'); $st->execute([$accountId]); return (bool)$st->fetchColumn(); }
    catch (Throwable $e) { return false; }
}

/** device_tokens.tz_offset (minutes east of UTC, from the phone) + morning_push_day (local YYYY-MM-DD last sent). Lazy, idempotent. */
function ensure_device_token_columns(PDO $pdo): void {
    static $done = false; if ($done) return; $done = true;
    try { $pdo->exec('ALTER TABLE device_tokens ADD COLUMN IF NOT EXISTS tz_offset INT NOT NULL DEFAULT 0, ADD COLUMN IF NOT EXISTS morning_push_day CHAR(10) DEFAULT NULL'); }
    catch (Throwable $e) { error_log('ensure_device_token_columns: ' . $e->getMessage()); }
}

/**
 * closet_items sync columns. Lazy, idempotent — same pattern as the two above.
 *
 *   client_id  the id the APP gave the item. Stable for the life of the item
 *              on every device. Before 2026-09-15 this table had no such
 *              column and api/closet.php minted a new uuidv4() per row on
 *              every save, so an item's identity changed each time a closet
 *              was saved. That is what let the sign-in merge append the whole
 *              closet over and over until two garments became 31 rows.
 *   updated_at when this row was last edited, in ms. Drives last-write-wins
 *              when two devices change the same item while offline.
 *   deleted_at soft delete. A removed item stays as a tombstone so other
 *              devices learn about the deletion instead of helpfully syncing
 *              the item back, and so a bad sync is recoverable.
 *
 * The unique index on (account_id, client_id) is what makes the upsert in
 * api/closet.php safe: it is the natural key the whole protocol turns on.
 * Adding it can fail on a table that still holds duplicates, so it is
 * best-effort: api/closet.php reads the account's existing rows and decides
 * per item, so it stays correct whether or not the index was created.
 */
function ensure_closet_columns(PDO $pdo): void {
    static $done = false; if ($done) return; $done = true;
    try {
        $pdo->exec('ALTER TABLE closet_items
            ADD COLUMN IF NOT EXISTS client_id VARCHAR(64) DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS updated_at BIGINT DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS deleted_at BIGINT DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS price_cents INT DEFAULT NULL');
    } catch (Throwable $e) { error_log('ensure_closet_columns (add): ' . $e->getMessage()); }
    // Backfill so every existing row has a usable identity and timestamp.
    try { $pdo->exec('UPDATE closet_items SET client_id = id WHERE client_id IS NULL'); }
    catch (Throwable $e) { error_log('ensure_closet_columns (backfill client_id): ' . $e->getMessage()); }
    try { $pdo->exec('UPDATE closet_items SET updated_at = created_at WHERE updated_at IS NULL'); }
    catch (Throwable $e) { error_log('ensure_closet_columns (backfill updated_at): ' . $e->getMessage()); }
    try { $pdo->exec('ALTER TABLE closet_items ADD UNIQUE INDEX IF NOT EXISTS uniq_account_client (account_id, client_id)'); }
    catch (Throwable $e) { error_log('ensure_closet_columns (index): ' . $e->getMessage()); }
    try { $pdo->exec('ALTER TABLE closet_items ADD INDEX IF NOT EXISTS idx_account_deleted (account_id, deleted_at)'); }
    catch (Throwable $e) { error_log('ensure_closet_columns (deleted index): ' . $e->getMessage()); }
}

/** Per-post opt-in to being viewable outside the app (p.php).
 *
 *  Defaults to 0 and is only ever offered at posting time, so no post made
 *  before this existed can be reached by a non-member. That matters: the
 *  privacy policy and the sign-up consent screen both promise Community
 *  posts are shown to "other members", and this feature must not change
 *  that promise retroactively for anyone who already posted under it. */
function ensure_post_share_column(PDO $pdo): void {
    static $done = false; if ($done) return; $done = true;
    try {
        $pdo->exec('ALTER TABLE posts ADD COLUMN IF NOT EXISTS shareable TINYINT(1) NOT NULL DEFAULT 0');
    } catch (Throwable $e) { error_log('ensure_post_share_column: ' . $e->getMessage()); }
}

/** Styling tips left on someone's public closet item (api/closet_tips.php).
 *
 *  Keyed on (owner_id, item_id) rather than item_id alone: a closet item's
 *  public id is its client_id, which is unique only within an account, so
 *  two people's devices can legitimately mint the same one. Keying on the
 *  item id by itself would hang one person's advice under another person's
 *  coat. */
/** Threaded comments: `parent_id` points at the comment being replied to
 *  (NULL for a top-level comment), `author_id` is the replier's account so a
 *  reply notification can be tapped through. Both are added here rather than
 *  in a hand-run migration so a deploy is the only step. Replies are kept
 *  ONE level deep on purpose (api/post_comments.php re-parents a reply-to-a-
 *  reply onto its root) — two levels is all a comment thread on a photo
 *  needs, and it keeps the indent readable on a phone. */
function ensure_comment_threads_schema(PDO $pdo): void {
    static $done = false; if ($done) return; $done = true;
    try {
        $pdo->exec('ALTER TABLE comments ADD COLUMN IF NOT EXISTS author_id CHAR(36) NULL');
        $pdo->exec('ALTER TABLE comments ADD COLUMN IF NOT EXISTS parent_id INT NULL');
        $pdo->exec('ALTER TABLE comments ADD INDEX IF NOT EXISTS idx_parent (parent_id)');
    } catch (Throwable $e) { error_log('ensure_comment_threads_schema: ' . $e->getMessage()); }
}

function ensure_closet_tips_table(PDO $pdo): void {
    static $done = false; if ($done) return; $done = true;
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS closet_item_tips (
                id CHAR(36) NOT NULL PRIMARY KEY,
                owner_id VARCHAR(64) NOT NULL,
                item_id VARCHAR(64) NOT NULL,
                author_id VARCHAR(64) NOT NULL,
                author_name VARCHAR(60) NOT NULL DEFAULT "",
                author_avatar_url VARCHAR(255) DEFAULT NULL,
                text VARCHAR(280) NOT NULL,
                created_at BIGINT NOT NULL,
                hidden TINYINT(1) NOT NULL DEFAULT 0,
                KEY idx_tip_item (owner_id, item_id, hidden),
                KEY idx_tip_author (author_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    } catch (Throwable $e) { error_log('ensure_closet_tips_table: ' . $e->getMessage()); }
}

/** "Help me choose" polls (api/post_vote.php).
 *
 *  Deliberately an extension of `posts` rather than a new content type: a
 *  poll then inherits likes, comments, reporting, moderation, room
 *  placement and the whole feed UI instead of needing its own copy of all
 *  of it. Two columns and one small table is the entire footprint.
 *
 *  One row per (post, voter) with the primary key doing the enforcement, so
 *  one account is one vote no matter how many times the button is tapped or
 *  how many devices it is tapped from. */
function ensure_poll_schema(PDO $pdo): void {
    static $done = false; if ($done) return; $done = true;
    try {
        $pdo->exec('ALTER TABLE posts
            ADD COLUMN IF NOT EXISTS photo_b_url VARCHAR(255) DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS is_poll TINYINT(1) NOT NULL DEFAULT 0');
    } catch (Throwable $e) { error_log('ensure_poll_schema (posts): ' . $e->getMessage()); }
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS poll_votes (
                post_id CHAR(36) NOT NULL,
                voter_id CHAR(36) NOT NULL,
                choice CHAR(1) NOT NULL,
                created_at BIGINT NOT NULL,
                PRIMARY KEY (post_id, voter_id),
                KEY idx_poll_post (post_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    } catch (Throwable $e) { error_log('ensure_poll_schema (votes): ' . $e->getMessage()); }
}

/** Saved outfits (api/outfits.php). Created on first use rather than in a
 *  migration, the same way ensure_closet_columns works, so deploying the
 *  feature needs nothing run by hand on the server. */
function ensure_outfits_table(PDO $pdo): void {
    static $done = false; if ($done) return; $done = true;
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS outfits (
                id CHAR(36) NOT NULL PRIMARY KEY,
                account_id VARCHAR(64) NOT NULL,
                client_id VARCHAR(64) NOT NULL,
                name VARCHAR(80) NOT NULL DEFAULT "",
                item_ids TEXT,
                wear_date CHAR(10) DEFAULT NULL,
                created_at BIGINT NOT NULL,
                updated_at BIGINT DEFAULT NULL,
                deleted_at BIGINT DEFAULT NULL,
                UNIQUE KEY uniq_outfit_account_client (account_id, client_id),
                KEY idx_outfit_account_deleted (account_id, deleted_at),
                KEY idx_outfit_account_date (account_id, wear_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    } catch (Throwable $e) { error_log('ensure_outfits_table: ' . $e->getMessage()); }
}

/** One row → the shape the client holds. */
function outfit_to_public(array $row): array {
    $ids = json_decode((string)($row['item_ids'] ?? '[]'), true);
    return [
        'id'        => (string)$row['client_id'],
        'name'      => (string)$row['name'],
        'itemIds'   => is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [],
        'wearDate'  => $row['wear_date'] !== null && $row['wear_date'] !== '' ? (string)$row['wear_date'] : null,
        'createdAt' => (int)$row['created_at'],
        'updatedAt' => (int)($row['updated_at'] ?? $row['created_at']),
    ];
}

/** The content identity of a closet item — mirrors closetItemKey() in the
 *  client. Used to adopt pre-2026-09-15 rows, which carry a server-minted
 *  client_id that no device will ever send. */
function closet_fingerprint(string $description, ?string $photo): string {
    $desc = preg_replace('/\s+/u', ' ', mb_strtolower(trim($description)));
    $p = (string)($photo ?? '');
    return sha1($desc . '|' . ($p === '' ? '' : sha1($p)));
}

function digest_opt_out(PDO $pdo, string $accountId): bool {
    ensure_digest_columns($pdo);
    try { $st = $pdo->prepare('SELECT digest_opt_out FROM accounts WHERE id = ?'); $st->execute([$accountId]); return (bool)$st->fetchColumn(); }
    catch (Throwable $e) { return false; }
}

/** 'stripe' | 'play' | 'gift' | null — which billing system owns this account's subscription row.
 *  'gift' is a free thank-you period (e.g. sandbox-era upgrades converted at go-live by
 *  api/golive_sandbox_premium.php): Premium is on, but there's no Stripe customer to manage. */
function subscription_billing_source(PDO $pdo, string $accountId): ?string {
    try { $st = $pdo->prepare('SELECT product_id FROM subscriptions WHERE account_id = ?'); $st->execute([$accountId]); $p = (string)($st->fetchColumn() ?: ''); }
    catch (Throwable $e) { return null; }
    if ($p === '') return referral_premium_until($pdo, $accountId) > current_time_ms() ? 'gift' : null;
    if (strpos($p, 'stripe:sandbox-gift') === 0 || strpos($p, 'gift:') === 0) return 'gift';
    // A lapsed paid plan with banked referral months → the months are what's active.
    try { $st = $pdo->prepare('SELECT is_premium, expires_at FROM subscriptions WHERE account_id = ?'); $st->execute([$accountId]); $row = $st->fetch();
        $paidActive = $row && $row['is_premium'] && ($row['expires_at'] === null || (int)$row['expires_at'] >= current_time_ms());
        if (!$paidActive && referral_premium_until($pdo, $accountId) > current_time_ms()) return 'gift';
    } catch (Throwable $e) {}
    return strpos($p, 'stripe') === 0 ? 'stripe' : 'play';
}

/** For gift rows: when the free period ends (ms) and whether it's still active; null otherwise. */
function subscription_gift_notice(PDO $pdo, string $accountId): ?array {
    try { $st = $pdo->prepare('SELECT product_id, expires_at, is_premium FROM subscriptions WHERE account_id = ?'); $st->execute([$accountId]); $row = $st->fetch(); }
    catch (Throwable $e) { return null; }
    $p = $row ? (string)$row['product_id'] : '';
    $paidActive = $row && $row['is_premium'] && ($row['expires_at'] === null || (int)$row['expires_at'] >= current_time_ms()) && strpos($p, 'stripe:sandbox-gift') !== 0 && strpos($p, 'gift:') !== 0;
    if (!$paidActive) {
        $ru = referral_premium_until($pdo, $accountId);
        if ($ru > current_time_ms() && !($row && strpos($p, 'stripe:sandbox-gift') === 0 && (int)$row['expires_at'] > current_time_ms())) return ['kind' => 'referral', 'until' => $ru, 'active' => true];
    }
    if (!$row) return null;
    if (strpos($p, 'stripe:sandbox-gift') !== 0 && strpos($p, 'gift:') !== 0) return null;
    $until = $row['expires_at'] !== null ? (int)$row['expires_at'] : null;
    return ['kind' => strpos($p, 'stripe:sandbox-gift') === 0 ? 'sandbox_gift' : 'gift', 'until' => $until, 'active' => $until === null || $until > current_time_ms()];
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
function post_to_public(PDO $pdo, array $row, ?string $viewerId = null): array {
    $likesStmt = $pdo->prepare('SELECT visitor_id, liked FROM likes WHERE post_id = ?');
    $likesStmt->execute([$row['id']]);
    $likes = [];
    foreach ($likesStmt->fetchAll() as $l) {
        $likes[$l['visitor_id']] = (bool)$l['liked'];
    }

    // id/parent_id/author_id let the client thread replies under their
    // parent and scroll a notification straight to one comment. Older
    // databases may not have those columns yet (see
    // ensure_comment_threads_schema), so fall back to the flat shape
    // rather than 500ing the whole feed.
    // Comments by (or for) someone in a block relationship with the viewer
    // are dropped here rather than in every caller, so blocking works the
    // same on the feed, on a profile and on a single post opened from a
    // notification. Reported-and-hidden comments go the same way.
    $blocked = blocked_ids($pdo, $viewerId);
    $commentBlockSql = blocked_filter_sql($blocked, 'author_id');
    try {
        $commentsStmt = $pdo->prepare(
            'SELECT id, parent_id, author_id, author_name, text, created_at FROM comments
             WHERE post_id = ? AND hidden = 0' . $commentBlockSql . ' ORDER BY id ASC'
        );
        $commentsStmt->execute(array_merge([$row['id']], $blocked));
        $commentRows = $commentsStmt->fetchAll();
    } catch (PDOException $e) {
        // Older database without comments.hidden / author_id / parent_id
        // (see ensure_block_schema / ensure_comment_threads_schema). A feed
        // request must never 500 over a missing moderation column.
        $commentsStmt = $pdo->prepare('SELECT id, author_name, text, created_at FROM comments WHERE post_id = ? ORDER BY id ASC');
        $commentsStmt->execute([$row['id']]);
        $commentRows = $commentsStmt->fetchAll();
    }
    $comments = array_map(function ($c) {
        return [
            'id' => isset($c['id']) ? (int)$c['id'] : null,
            'parentId' => isset($c['parent_id']) && $c['parent_id'] !== null ? (int)$c['parent_id'] : null,
            'authorId' => $c['author_id'] ?? null,
            'authorName' => $c['author_name'],
            'text' => $c['text'],
            'createdAt' => (int)$c['created_at'],
        ];
    }, $commentRows);

    $styleTags = json_decode($row['style_tags'] ?: '[]', true);
    if (!is_array($styleTags)) $styleTags = [];

    $out = [
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
        'shareable' => !empty($row['shareable']),
    ];

    // Polls. Only the tallies go out, never who voted for what — unlike
    // likes, a vote on someone's outfit is the kind of thing people would
    // rather keep to themselves. The viewer's own choice comes back so the
    // UI can show it, and only ever for the caller that proved who they are.
    if (!empty($row['is_poll'])) {
        $out['photoBUrl'] = $row['photo_b_url'] ?? null;
        $counts = ['a' => 0, 'b' => 0];
        try {
            $vs = $pdo->prepare('SELECT choice, COUNT(*) AS n FROM poll_votes WHERE post_id = ? GROUP BY choice');
            $vs->execute([$row['id']]);
            foreach ($vs->fetchAll(PDO::FETCH_ASSOC) as $v) {
                if (isset($counts[$v['choice']])) $counts[$v['choice']] = (int)$v['n'];
            }
        } catch (Throwable $e) { /* table not created yet — an empty poll is fine */ }
        $mine = null;
        if ($viewerId) {
            try {
                $ms = $pdo->prepare('SELECT choice FROM poll_votes WHERE post_id = ? AND voter_id = ?');
                $ms->execute([$row['id'], $viewerId]);
                $mine = $ms->fetchColumn() ?: null;
            } catch (Throwable $e) { /* as above */ }
        }
        $out['poll'] = [
            'a' => $counts['a'],
            'b' => $counts['b'],
            'total' => $counts['a'] + $counts['b'],
            'myVote' => $mine,
        ];
    }

    return $out;
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
        // The app's own id for this item when we have it. The server row id
        // is an internal detail and used to change on every sync — handing
        // it out is what let the client lose track of its own items.
        'id' => (string)($row['client_id'] ?? '') !== '' ? $row['client_id'] : $row['id'],
        'description' => $row['description'],
        'photo' => $row['photo_data'],
        'createdAt' => (int)$row['created_at'],
        'visibility' => $row['visibility'] ?? 'hidden',
        'authorName' => $row['author_name'] ?? '',
        'authorId' => $row['account_id'] ?? null,
    ];
}

/** The owner's own view of a closet item. Same as closet_item_to_public plus
 *  what nobody else may see.
 *
 *  `price` is deliberately NOT in closet_item_to_public: that shape is served
 *  to strangers through api/closet.php's GET and the Community closets feed,
 *  and what someone paid for a coat is nobody else's business. Only
 *  api/closet_mine.php — which requires the owner's auth token — uses this. */
function closet_item_to_owner(array $row): array {
    $out = closet_item_to_public($row);
    $cents = $row['price_cents'] ?? null;
    $out['price'] = $cents === null ? null : round((int)$cents / 100, 2);
    return $out;
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

/* =========================================================================
 * Blocking and comment reporting.
 *
 * App Store Review Guideline 1.2 requires an app with user-generated
 * content to offer ALL of: a filter for objectionable material, a way to
 * report it, a way to BLOCK abusive users, and published contact info.
 * Style-LORE had the first, second and fourth (api/post_report.php,
 * api/admin_moderation.php, support@style-lore.com) but no blocking at
 * all, and no way to report anything smaller than a whole post — which is
 * the usual reason a social feature gets rejected. This section is that
 * gap.
 *
 * A block is deliberately SYMMETRIC in what it hides: once A blocks B,
 * neither sees the other's posts, comments, stories, closet items or
 * messages, and neither can start a conversation with the other. Making it
 * one-way would leave the blocked person able to keep watching and keep
 * replying, which is exactly the situation someone blocks to get out of.
 * It is NOT symmetric in who can undo it: only the blocker can unblock.
 * ========================================================================= */
function ensure_block_schema(PDO $pdo): void {
    static $done = false; if ($done) return; $done = true;
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS blocked_users (
                blocker_id VARCHAR(64) NOT NULL,
                blocked_id VARCHAR(64) NOT NULL,
                created_at BIGINT NOT NULL,
                PRIMARY KEY (blocker_id, blocked_id),
                KEY idx_blocked_by (blocked_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        // Comment-level moderation, mirroring posts.hidden/report_count.
        $pdo->exec('ALTER TABLE comments ADD COLUMN IF NOT EXISTS hidden TINYINT(1) NOT NULL DEFAULT 0');
        $pdo->exec('ALTER TABLE comments ADD COLUMN IF NOT EXISTS report_count INT NOT NULL DEFAULT 0');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS comment_reports (
                comment_id INT NOT NULL,
                visitor_id VARCHAR(64) NOT NULL,
                created_at BIGINT NOT NULL,
                PRIMARY KEY (comment_id, visitor_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    } catch (Throwable $e) { error_log('ensure_block_schema: ' . $e->getMessage()); }
}

/** Every account id $viewerId should not see, in EITHER direction: people
 *  they blocked, and people who blocked them. Cached per request — the
 *  feed calls this once per post otherwise. Returns [] for a signed-out
 *  viewer, which is correct: there is no one to hide from. */
function blocked_ids(PDO $pdo, ?string $viewerId): array {
    if ($viewerId === null || $viewerId === '') return [];
    static $cache = [];
    if (array_key_exists($viewerId, $cache)) return $cache[$viewerId];
    ensure_block_schema($pdo);
    $out = [];
    try {
        $stmt = $pdo->prepare(
            'SELECT blocked_id AS other FROM blocked_users WHERE blocker_id = ?
             UNION
             SELECT blocker_id AS other FROM blocked_users WHERE blocked_id = ?'
        );
        $stmt->execute([$viewerId, $viewerId]);
        $out = array_values(array_filter(array_column($stmt->fetchAll(), 'other'), 'strlen'));
    } catch (Throwable $e) { error_log('blocked_ids: ' . $e->getMessage()); }
    return $cache[$viewerId] = $out;
}

/** SQL fragment excluding $ids from $column, for appending to a WHERE
 *  clause. Returns '' when there is nothing to exclude so the caller's
 *  parameter list stays untouched. NULL authors (legacy rows) are kept —
 *  they belong to no one, so they cannot belong to a blocked account. */
function blocked_filter_sql(array $ids, string $column): string {
    if (!$ids) return '';
    return ' AND (' . $column . ' IS NULL OR ' . $column
         . ' NOT IN (' . implode(',', array_fill(0, count($ids), '?')) . '))';
}

/** True if either of these two has blocked the other. Used on the write
 *  paths (starting a conversation, sending a message, following) where a
 *  feed filter is not enough. */
function is_blocked_pair(PDO $pdo, string $a, string $b): bool {
    if ($a === '' || $b === '' || $a === $b) return false;
    ensure_block_schema($pdo);
    try {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM blocked_users
             WHERE (blocker_id = ? AND blocked_id = ?) OR (blocker_id = ? AND blocked_id = ?)
             LIMIT 1'
        );
        $stmt->execute([$a, $b, $b, $a]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) { error_log('is_blocked_pair: ' . $e->getMessage()); return false; }
}

/* =========================================================================
 * Acquisition funnel: where installs come from, and whether they convert.
 *
 * Apple publishes NO click data for a TestFlight public link — you can see
 * who joined, never how many people looked. Google Play is the same. So the
 * link people are given is one of ours (/ios, /android), which records the
 * hit and then redirects; the store's own numbers pick up from there.
 *
 * Deliberately coarse. There is no cookie, no per-person id and no attempt
 * to join a click to the account it may later become: the IP is kept only
 * so the same phone reloading a page twice can be discounted, and a click
 * and a signup are counted as populations, not as a chain. That is enough
 * to answer "is the link working" without building a tracker.
 * ========================================================================= */
function ensure_funnel_schema(PDO $pdo): void {
    static $done = false; if ($done) return; $done = true;
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS link_clicks (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                slug VARCHAR(32) NOT NULL,
                created_at BIGINT NOT NULL,
                referrer VARCHAR(255) DEFAULT NULL,
                source VARCHAR(64) DEFAULT NULL,
                user_agent VARCHAR(255) DEFAULT NULL,
                platform VARCHAR(16) DEFAULT NULL,
                ip VARCHAR(45) DEFAULT NULL,
                KEY idx_slug_time (slug, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        // Which platform an account signed up from. The client has always
        // sent this on login; signup threw it away, so there was no way to
        // ask "how many of these came from the iOS beta".
        $pdo->exec('ALTER TABLE accounts ADD COLUMN IF NOT EXISTS signup_device VARCHAR(16) NULL');
    } catch (Throwable $e) { error_log('ensure_funnel_schema: ' . $e->getMessage()); }
}

/** "ios" | "android" | "web" from whatever the client claimed, or null.
 *  Never trusted for anything that matters — it only labels a row. */
function normalize_device(?string $raw): ?string {
    $raw = strtolower(trim((string)$raw));
    if ($raw === 'ios' || $raw === 'android' || $raw === 'web') return $raw;
    return null;
}
