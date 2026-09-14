<?php
/**
 * Uptime + "is it actually working" monitor. Runs every 5 minutes from
 * cPanel cron (CLI, no key needed; HTTP needs ?key=CRON_KEY):
 *   /usr/local/bin/php /home/.../public_html/api/healthcheck.php >> ~/backups/health.log 2>&1
 *
 * It calls the live site the way a visitor would and checks that the
 * important endpoints answer the way they should — not just "200 OK" but
 * "login with a wrong password says 401, not 500" (the exact failure that
 * went unnoticed for 14 hours on 2026-09-13). On the first failure it emails
 * SUPPORT_EMAIL (→ Kenneth's inbox); it re-alerts at most once an hour
 * while broken, and sends one "recovered" email when everything passes
 * again. State lives in a tiny JSON file next to the backups.
 */
require_once __DIR__ . '/../includes/helpers.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    $key = (string)($_GET['key'] ?? '');
    if (!defined('CRON_KEY') || CRON_KEY === '' || !hash_equals(CRON_KEY, $key)) error_response('Forbidden.', 403);
}

$base = rtrim(SITE_BASE_URL, '/');
$stateFile = dirname(__DIR__, 2) . '/backups/health-state.json';
if (!is_dir(dirname($stateFile))) $stateFile = sys_get_temp_dir() . '/style-lore-health-state.json';

function hc_request(string $method, string $url, ?string $json = null, array $headers = []): array {
    $ch = curl_init($url);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_USERAGENT => 'StyleLORE-healthcheck/1'];
    if ($json !== null) { $opts[CURLOPT_POSTFIELDS] = $json; $headers[] = 'Content-Type: application/json'; }
    if ($headers) $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => (string)$body, 'err' => $err];
}

$checks = [];
// 1. Homepage serves and carries the app.
$r = hc_request('GET', $base . '/');
$checks['homepage'] = [$r['code'] === 200 && strpos($r['body'], 'id="root"') !== false, "HTTP {$r['code']} {$r['err']}"];
// 2. Status API answers JSON (DB + helpers load).
$r = hc_request('GET', $base . '/api/subscription/status?accountId=healthcheck');
$j = json_decode($r['body'], true);
$checks['status_api'] = [$r['code'] === 200 && is_array($j) && array_key_exists('isPremium', $j), "HTTP {$r['code']}: " . substr($r['body'], 0, 120)];
// 3. Login path runs end to end: a wrong password must be a clean 401, never a 500.
$r = hc_request('POST', $base . '/api/auth/login', json_encode(['email' => 'healthcheck@style-lore.com', 'password' => 'not-a-real-password']));
$checks['login'] = [$r['code'] === 401, "HTTP {$r['code']}: " . substr($r['body'], 0, 120)];
// 4. Stripe checkout endpoint loads (needs auth → 401; a 500/503 means code or config is broken).
$r = hc_request('POST', $base . '/api/stripe_checkout.php', json_encode(['visitorId' => 'healthcheck', 'authToken' => 'x', 'plan' => 'monthly']));
$checks['checkout'] = [$r['code'] === 401, "HTTP {$r['code']}: " . substr($r['body'], 0, 120)];
// 5. Stripe webhook rejects unsigned posts (proves the secret is configured and the file parses).
$r = hc_request('POST', $base . '/api/stripe_webhook.php', '{}', ['Stripe-Signature: t=1,v1=bad']);
$checks['webhook'] = [$r['code'] === 400, "HTTP {$r['code']}: " . substr($r['body'], 0, 120)];
// 6. AI Stylist endpoint loads (unauthenticated → 401).
$r = hc_request('POST', $base . '/api/checker/photo', json_encode(['visitorId' => 'healthcheck', 'authToken' => 'x']));
$checks['ai_stylist'] = [$r['code'] === 401, "HTTP {$r['code']}: " . substr($r['body'], 0, 120)];
// 7. Anthropic balance / key: only checkable by a real read, so we watch the error log instead.
$log = dirname(__DIR__) . '/api/error_log';
if (!is_file($log)) $log = __DIR__ . '/error_log';
$recentAnthropic = false;
if (is_file($log) && filemtime($log) > time() - 600) {
    $tail = @file_get_contents($log, false, null, max(0, filesize($log) - 20000));
    if ($tail && preg_match('/credit balance is too low|authentication_error/i', $tail)) $recentAnthropic = true;
}
$checks['anthropic'] = [!$recentAnthropic, $recentAnthropic ? 'error_log shows an Anthropic credit/key error in the last 10 minutes' : 'ok'];

$failed = array_filter($checks, fn($c) => !$c[0]);
$now = time();
$state = is_file($stateFile) ? (json_decode((string)file_get_contents($stateFile), true) ?: []) : [];
$wasDown = !empty($state['down']);
$lastAlert = (int)($state['lastAlert'] ?? 0);

$summary = [];
foreach ($checks as $name => $c) $summary[] = ($c[0] ? 'ok   ' : 'FAIL ') . $name . ($c[0] ? '' : ' — ' . $c[1]);
$line = date('c') . ' ' . (count($failed) ? 'DOWN(' . implode(',', array_keys($failed)) . ')' : 'ok');

if (count($failed)) {
    if (!$wasDown || $now - $lastAlert > 3600) {
        $subject = 'Style-LORE: ' . count($failed) . ' check' . (count($failed) === 1 ? '' : 's') . ' failing — ' . implode(', ', array_keys($failed));
        $text = "The health monitor found a problem at " . date('Y-m-d H:i T') . ":\n\n" . implode("\n", $summary) . "\n\nOpen the site: $base/\nServer error log: cPanel → File Manager → public_html/api/error_log\n\nYou'll get one more email when everything passes again.";
        send_mail_tracked(SUPPORT_EMAIL, $subject, null, $text);
        $state['lastAlert'] = $now;
    }
    $state['down'] = true;
} else {
    if ($wasDown) {
        send_mail_tracked(SUPPORT_EMAIL, 'Style-LORE: all checks passing again', "Everything is back to normal as of " . date('Y-m-d H:i T') . ".\n\n" . implode("\n", $summary) . "\n");
    }
    $state['down'] = false;
}
$state['lastRun'] = $now;
@file_put_contents($stateFile, json_encode($state));

if ($isCli) { echo $line, "\n"; }
else json_response(['ok' => !count($failed), 'checks' => array_map(fn($c) => $c[0] ? 'ok' : $c[1], $checks)]);
