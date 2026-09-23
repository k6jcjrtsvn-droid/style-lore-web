<?php
/**
 * api/admin/funnel — the acquisition numbers, for admin.html.
 *
 * Gated by the same ADMIN_SECRET / ADMIN_KEY header as
 * api/admin_moderation.php.
 *
 * Returns, per platform: link clicks (total, unique-ish by IP, last 7 days),
 * and accounts created. It deliberately does NOT claim a click became an
 * account — nothing links them — so the two are reported side by side and
 * the ratio is left to a human to read as the soft signal it is.
 */
require_once __DIR__ . '/../includes/helpers.php';
require_method('GET');

$sent = (string)($_SERVER['HTTP_X_ADMIN_SECRET'] ?? ($_SERVER['HTTP_X_ADMIN_KEY'] ?? ''));
$secret = (defined('ADMIN_SECRET') && ADMIN_SECRET !== '') ? ADMIN_SECRET
        : ((defined('ADMIN_KEY') && ADMIN_KEY !== '') ? ADMIN_KEY : '');
if ($secret === '' || $sent === '' || !hash_equals($secret, $sent)) {
    error_response('Not authorized.', 401);
}

$pdo = db();
ensure_funnel_schema($pdo);

$weekAgo = current_time_ms() - 7 * 24 * 60 * 60 * 1000;

function rows(PDO $pdo, string $sql, array $args = []): array {
    try { $s = $pdo->prepare($sql); $s->execute($args); return $s->fetchAll(PDO::FETCH_ASSOC); }
    catch (Throwable $e) { error_log('admin_funnel: ' . $e->getMessage()); return []; }
}

$clicks = rows($pdo,
    'SELECT slug,
            COUNT(*) AS total,
            COUNT(DISTINCT ip) AS unique_ips,
            SUM(created_at >= ?) AS last7,
            SUM(platform = slug) AS right_platform
     FROM link_clicks GROUP BY slug', [$weekAgo]);

$bySource = rows($pdo,
    'SELECT slug, COALESCE(source, "(untagged)") AS source, COUNT(*) AS n
     FROM link_clicks GROUP BY slug, source ORDER BY n DESC LIMIT 50');

$signups = rows($pdo,
    'SELECT COALESCE(signup_device, "(unrecorded)") AS device,
            COUNT(*) AS total,
            SUM(created_at >= ?) AS last7,
            SUM(email_verified = 1) AS verified
     FROM accounts GROUP BY signup_device', [$weekAgo]);

$daily = rows($pdo,
    'SELECT slug, FLOOR(created_at / 86400000) AS day, COUNT(*) AS n
     FROM link_clicks WHERE created_at >= ? GROUP BY slug, day ORDER BY day ASC', [$weekAgo]);

json_response([
    'clicks' => $clicks,
    'clicksBySource' => $bySource,
    'signups' => $signups,
    'daily' => $daily,
    'note' => 'Clicks and signups are separate populations. Nothing links a click to the account it may have become, so read the ratio as a trend, not as attribution.',
]);
