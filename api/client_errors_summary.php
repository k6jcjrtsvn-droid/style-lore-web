<?php
/**
 * api/client_errors_summary — anonymised roll-up of the automatic bug
 * reports (client_errors) for the last N hours, read by Claude's scheduled
 * watchdog so problems get looked at without anyone reading email.
 *
 *   GET /api/client_errors_summary.php?token=…&hours=24
 *
 * Returns one row per distinct problem (fingerprint): kind, platform, app
 * version, screen, message, stack excerpt, count, first/last seen. No IPs,
 * no account ids, no user agents. Token lives in includes/watchdog_token.php.
 */
require_once __DIR__ . '/../includes/helpers.php';
@include_once __DIR__ . '/../includes/watchdog_token.php';

$token = (string)($_GET['token'] ?? '');
if (!defined('WATCHDOG_READ_TOKEN') || WATCHDOG_READ_TOKEN === '' || !hash_equals(WATCHDOG_READ_TOKEN, $token)) error_response('Not authorized.', 401);

$hours = max(1, min(24 * 14, (int)($_GET['hours'] ?? 24)));
$since = current_time_ms() - $hours * 3600 * 1000;
$pdo = db();
try {
    $stmt = $pdo->prepare('SELECT fingerprint, kind, platform, MAX(app_version) AS app_version, MAX(page) AS page,
                                  MAX(message) AS message, MAX(stack) AS stack, MAX(extra) AS extra,
                                  COUNT(*) AS count, MIN(created_at) AS first_seen, MAX(created_at) AS last_seen,
                                  COUNT(DISTINCT COALESCE(account_id, ip)) AS people
                           FROM client_errors WHERE created_at > ?
                           GROUP BY fingerprint, kind, platform ORDER BY last_seen DESC LIMIT 100');
    $stmt->execute([$since]);
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    $rows = []; // table not created yet = no reports yet
}
$out = [];
foreach ($rows as $r) {
    $out[] = [
        'fingerprint' => substr($r['fingerprint'], 0, 12),
        'kind' => $r['kind'], 'platform' => $r['platform'], 'appVersion' => $r['app_version'], 'screen' => $r['page'],
        'message' => $r['message'], 'stack' => $r['stack'] ? mb_substr($r['stack'], 0, 600) : null,
        'extra' => $r['extra'] ? mb_substr($r['extra'], 0, 400) : null,
        'count' => (int)$r['count'], 'people' => (int)$r['people'],
        'firstSeen' => gmdate('c', (int)($r['first_seen'] / 1000)), 'lastSeen' => gmdate('c', (int)($r['last_seen'] / 1000)),
    ];
}
json_response(['ok' => true, 'hours' => $hours, 'problems' => $out, 'generatedAt' => gmdate('c')]);
