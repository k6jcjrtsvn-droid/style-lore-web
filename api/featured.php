<?php
/**
 * api/featured — what the Home screen leads with this week.
 *
 * Three things, each chosen server-side so every device agrees:
 *
 *   tip          this ISO week's tip for the viewer's Kibbe type — the SAME
 *                tip the weekly email sends (weekly_tip_for_type() in
 *                helpers.php, over includes/digest_tips.php), so the app and
 *                the inbox never contradict each other. Signed out, or no
 *                quiz result yet: the thirteen types take turns week by week,
 *                so there is always a tip and it still changes weekly.
 *   post         the newest visible Community post by somebody other than
 *                the viewer, or null when there is nothing to show. The demo
 *                seed account (Ava, review@style-lore.com — see
 *                claude/demo-seed-artwork-and-plan-2026-09-24.md) is left out
 *                by its email: the standing rule is no seeded members, and a
 *                Home screen that keeps featuring the store-screenshot account
 *                would be exactly that.
 *   seasonGuide  the viewer's own /colors/<season>.html page when they are
 *                signed in and have a colour result; null otherwise.
 *
 * GET, optional auth: ?visitorId=<id> plus "Authorization: Bearer <token>"
 * (or ?authToken=). A missing or wrong token is not an error — the response
 * is simply the signed-out one — because this is a read that must never
 * block Home from rendering. Cached five minutes: everything in it changes
 * at most weekly except the post, and a five-minute-old post is fine.
 *
 * Never 500s on an empty database: every query is guarded and its part of
 * the response falls back to null.
 */
require_once __DIR__ . '/../includes/helpers.php';
require_method('GET');

$pdo = db();

// Who is asking, if anyone. Proven or ignored — never taken on trust.
$viewerId = null;
$claimed = trim((string)($_GET['visitorId'] ?? ($_GET['accountId'] ?? '')));
if ($claimed !== '' && preg_match('/^[0-9a-f-]{36}$/i', $claimed)) {
    try {
        if (owner_token_valid($pdo, $claimed, bearer_token())) $viewerId = $claimed;
    } catch (Throwable $e) { error_log('featured auth: ' . $e->getMessage()); }
}

$week = (int)date('W');
$weekLabel = date('o') . '-W' . date('W');

/* The viewer's quiz results, when signed in. */
$kibbeName = '';
$seasonId = null;
if ($viewerId !== null) {
    try {
        ensure_color_result_column($pdo);
        $st = $pdo->prepare('SELECT kibbe_type_name, color_result_json FROM profiles WHERE id = ?');
        $st->execute([$viewerId]);
        $p = $st->fetch();
        if ($p) {
            $kibbeName = trim((string)($p['kibbe_type_name'] ?? ''));
            $decoded = json_decode((string)($p['color_result_json'] ?? ''), true);
            $sid = is_array($decoded) ? (string)($decoded['seasonId'] ?? '') : '';
            if (in_array($sid, style_lore_season_ids(), true)) $seasonId = $sid;
        }
    } catch (Throwable $e) { error_log('featured profile: ' . $e->getMessage()); }
}

/* tip */
$tips = require __DIR__ . '/../includes/digest_tips.php';
$picked = $kibbeName !== '' ? weekly_tip_for_type($tips, $kibbeName, $week) : null;
if (!$picked) {
    // No type known: rotate through the types so the card still changes weekly.
    $ids = array_keys($tips);
    $picked = weekly_tip_for_type($tips, (string)$tips[$ids[$week % count($ids)]]['name'], $week);
}
$tip = [
    'title' => 'This week for ' . $picked['name'] . ' lines',
    'body'  => $picked['tip'],
];

/* post — members only. Community posts are promised to "other members", so a
   guest (no proven viewer) never gets one here, whatever posts.php's GET does. */
$post = null;
if ($viewerId !== null) try {
    ensure_poll_schema($pdo);
    ensure_post_season_column($pdo);
    ensure_block_schema($pdo);
    $blocked = blocked_ids($pdo, $viewerId);
    $blockSql = blocked_filter_sql($blocked, 'p.author_id');
    $params = [];
    $viewerSql = '';
    if ($viewerId !== null) { $viewerSql = ' AND p.author_id <> ?'; $params[] = $viewerId; }
    $st = $pdo->prepare(
        'SELECT p.id, p.author_id, p.author_name, p.caption, p.photo_url, p.kibbe_tag, p.season_tag, p.is_poll, p.created_at
           FROM posts p
          WHERE p.hidden = 0 AND p.group_id IS NULL' . $viewerSql . $blockSql . '
            AND p.author_id NOT IN (SELECT id FROM accounts WHERE email = ?)
          ORDER BY p.created_at DESC
          LIMIT 1'
    );
    $st->execute(array_merge($params, $blocked, ['review@style-lore.com']));
    $row = $st->fetch();
    if ($row) {
        $post = [
            'id'          => (string)$row['id'],
            'author_name' => display_name($pdo, $row['author_id'], (string)$row['author_name']),
            'caption'     => (string)$row['caption'],
            'photo_url'   => $row['photo_url'] !== null ? (string)$row['photo_url'] : null,
            'kibbe_tag'   => $row['kibbe_tag'] !== null ? (string)$row['kibbe_tag'] : null,
            'season_tag'  => $row['season_tag'] !== null ? (string)$row['season_tag'] : null,
            'is_poll'     => !empty($row['is_poll']),
            'created_at'  => (int)$row['created_at'],
        ];
    }
} catch (Throwable $e) {
    // An empty or not-yet-migrated posts table is a null post, not an error.
    error_log('featured post: ' . $e->getMessage());
    $post = null;
}

/* seasonGuide */
$seasonGuide = null;
if ($seasonId !== null) {
    $seasonGuide = [
        'url'   => '/colors/' . $seasonId . '.html',
        'label' => ucwords(str_replace('-', ' ', $seasonId)),
    ];
}

http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
// json_response() sends no-store; this one is deliberately cacheable — see
// the header comment. Private, because a signed-in response is personal.
header('Cache-Control: private, max-age=300');
echo json_encode([
    'ok'          => true,
    'week'        => $weekLabel,
    'tip'         => $tip,
    'post'        => $post,
    'seasonGuide' => $seasonGuide,
]);
exit;
