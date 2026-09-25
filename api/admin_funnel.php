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
 *
 * ALSO: "how far do people actually get?" — the question that matters more
 * than acquisition once people are arriving. Of the accounts that exist, how
 * many finished the quiz, logged a single garment, or ever posted. Each step
 * is a plain COUNT over what those accounts have actually created, so it is
 * a fact rather than an inference.
 *
 * It cannot see people who never made an account: the quiz works signed out
 * by design, so someone can take it, read their type and leave without ever
 * touching this database. That is a real blind spot and it is stated in the
 * response rather than papered over.
 *
 * And a per-type room census — accounts of each type against posts of each
 * type — because an empty room is the reason a new member leaves, and until
 * now there was no way to see which rooms were empty without counting by eye.
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

/* How far an account gets. Ordered as the person experiences it. */
$stage = rows($pdo,
    'SELECT
       (SELECT COUNT(*) FROM accounts) AS accounts,
       (SELECT COUNT(*) FROM accounts WHERE email_verified = 1) AS verified,
       (SELECT COUNT(*) FROM profiles WHERE COALESCE(kibbe_type_name, "") <> "") AS tookQuiz,
       (SELECT COUNT(DISTINCT account_id) FROM closet_items) AS loggedCloset,
       (SELECT COUNT(DISTINCT author_id) FROM posts) AS posted,
       (SELECT COUNT(*) FROM subscriptions WHERE is_premium = 1) AS premium');
$stage = $stage ? $stage[0] : [];

/* The room census. A type with accounts and no posts is a room that greets
 * its own members with "be the first" — the single most common reason a new
 * member never comes back. */
$roomAccounts = rows($pdo,
    'SELECT kibbe_type_name AS type, COUNT(*) AS accounts
       FROM profiles WHERE COALESCE(kibbe_type_name, "") <> ""
      GROUP BY kibbe_type_name');
$roomPosts = rows($pdo,
    'SELECT kibbe_tag AS type, COUNT(*) AS posts, COUNT(DISTINCT author_id) AS authors
       FROM posts WHERE COALESCE(hidden, 0) = 0 GROUP BY kibbe_tag');

json_response([
    'stage' => $stage,
    'roomAccounts' => $roomAccounts,
    'roomPosts' => $roomPosts,
    'clicks' => $clicks,
    'clicksBySource' => $bySource,
    'signups' => $signups,
    'daily' => $daily,
    'note' => 'Clicks and signups are separate populations. Nothing links a click to the account it may have become, so read the ratio as a trend, not as attribution. The stage counts cover accounts only — the quiz works signed out, so anyone who took it and left without signing up is invisible here.',
]);
