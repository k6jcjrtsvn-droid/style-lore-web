<?php
/**
 * api/client_log — bug reports sent automatically by the app itself.
 *
 * The website and the Android/iOS apps (same index.html) POST here whenever
 * something goes wrong on the user's side: an uncaught JavaScript error, an
 * unhandled promise rejection, a failed request to our API, or a specific
 * feature failure such as the on-device photo analyzer not loading. Each
 * report carries what we need to reproduce it — platform, app version,
 * screen, the error text and stack — and is stored in `client_errors`.
 *
 * Alerting: the first time a given problem (fingerprint = kind + message)
 * shows up in a 24-hour window, Kenneth gets an email at SUPPORT_EMAIL with
 * the details; repeats are only counted, so a bug hitting 200 people sends
 * one email, not 200. No auth (the reporter may be a signed-out visitor), so
 * it is rate-limited per IP and bodies are truncated hard.
 */
require_once __DIR__ . '/../includes/helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') error_response('Method not allowed.', 405);

$pdo = db();
rate_limit($pdo, 'clientlog:' . client_ip(), 30, 3600, 'Too many reports.');

function ensure_client_errors_table(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec('CREATE TABLE IF NOT EXISTS client_errors (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        created_at BIGINT NOT NULL,
        fingerprint CHAR(40) NOT NULL,
        kind VARCHAR(40) NOT NULL,
        message VARCHAR(500) NOT NULL,
        stack TEXT NULL,
        page VARCHAR(120) NULL,
        platform VARCHAR(20) NOT NULL,
        app_version VARCHAR(20) NULL,
        user_agent VARCHAR(255) NULL,
        account_id CHAR(36) NULL,
        ip VARCHAR(45) NULL,
        extra TEXT NULL,
        INDEX idx_fp_time (fingerprint, created_at),
        INDEX idx_time (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

$body = request_json();
$kind = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($body['kind'] ?? 'error')));
if ($kind === '') $kind = 'error';
$message = trim((string)($body['message'] ?? ''));
if ($message === '') error_response('No message.', 400);
$message = mb_substr($message, 0, 500);
$stack = mb_substr((string)($body['stack'] ?? ''), 0, 4000);
$page = mb_substr((string)($body['page'] ?? ''), 0, 120);
$platform = preg_replace('/[^a-z]/', '', strtolower((string)($body['platform'] ?? 'web'))) ?: 'web';
$appVersion = mb_substr((string)($body['appVersion'] ?? ''), 0, 20);
$ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
$accountId = (string)($body['accountId'] ?? '');
if (!preg_match('/^[0-9a-f-]{36}$/i', $accountId)) $accountId = null;
$extra = $body['extra'] ?? null;
$extraJson = $extra === null ? null : mb_substr(json_encode($extra, JSON_UNESCAPED_SLASHES), 0, 4000);

// Same bug from many people should count as one problem: fingerprint on the
// kind plus the message with numbers/ids stripped.
$normalized = preg_replace('/[0-9a-f]{8}-[0-9a-f-]{27}|\d+/i', '#', $message);
$fingerprint = sha1($kind . '|' . $platform . '|' . mb_substr($normalized, 0, 200));

ensure_client_errors_table($pdo);
$now = current_time_ms();
$pdo->prepare('INSERT INTO client_errors (created_at, fingerprint, kind, message, stack, page, platform, app_version, user_agent, account_id, ip, extra)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
    ->execute([$now, $fingerprint, $kind, $message, $stack ?: null, $page ?: null, $platform, $appVersion ?: null, $ua ?: null, $accountId, client_ip(), $extraJson]);

// First sighting in 24h -> email. Everything else is just counted.
$dayAgo = $now - 86400 * 1000;
$stmt = $pdo->prepare('SELECT COUNT(*) FROM client_errors WHERE fingerprint = ? AND created_at > ?');
$stmt->execute([$fingerprint, $dayAgo]);
$count = (int)$stmt->fetchColumn();

if ($count === 1) {
    $subject = 'Style-LORE bug report (' . $platform . ($appVersion ? ' ' . $appVersion : '') . '): ' . mb_substr($message, 0, 80);
    $text = "The app reported a problem at " . date('Y-m-d H:i T') . ".\n\n"
        . "Kind: $kind\nPlatform: $platform" . ($appVersion ? " (app $appVersion)" : '') . "\nScreen: " . ($page ?: '-') . "\n"
        . "Account: " . ($accountId ?: 'signed out') . "\nDevice: " . ($ua ?: '-') . "\n\n"
        . "Message:\n$message\n\n"
        . ($stack ? "Stack:\n$stack\n\n" : '')
        . ($extraJson ? "Details:\n$extraJson\n\n" : '')
        . "You'll only get one email per distinct problem per day; repeats are counted in the client_errors table.\n";
    send_mail_tracked(SUPPORT_EMAIL, $subject, null, $text);
}

json_response(['ok' => true, 'first' => $count === 1]);
