<?php
/**
 * POST /api/posts/:id/report — { visitorId } — records this visitor's
 * report of a post. A post is only hidden once REPORT_HIDE_THRESHOLD
 * distinct people have reported it (see helpers.php), not on the very
 * first report — previously this hid a post immediately and permanently,
 * with no review path at all (see deployment-status.md's audit notes).
 * Reviewing/undoing a hide is api/admin_moderation.php.
 */
require_once __DIR__ . '/../includes/helpers.php';
require_method('POST');

$postId = (string)($_GET['id'] ?? '');
$body = request_json();
$visitorId = (string)($body['visitorId'] ?? '');

if (!$visitorId) error_response('Missing visitorId.', 400);

$pdo = db();

// Ownership check — a report only counts from a real, logged-in account,
// so hitting the hide threshold takes that many distinct real people, not
// just that many made-up ids in a request body.
require_owner($pdo, $visitorId, (string)($body['authToken'] ?? ''));

$check = $pdo->prepare('SELECT id FROM posts WHERE id = ?');
$check->execute([$postId]);
if (!$check->fetch()) error_response('Post not found.', 404);

// INSERT IGNORE: reporting the same post twice from the same account is a
// no-op, not a second vote.
$pdo->prepare('INSERT IGNORE INTO reports (post_id, visitor_id, created_at) VALUES (?, ?, ?)')
    ->execute([$postId, $visitorId, current_time_ms()]);

$countStmt = $pdo->prepare('SELECT COUNT(*) AS c FROM reports WHERE post_id = ?');
$countStmt->execute([$postId]);
$reportCount = (int)$countStmt->fetch()['c'];

$hidden = $reportCount >= REPORT_HIDE_THRESHOLD ? 1 : 0;
$pdo->prepare('UPDATE posts SET report_count = ?, hidden = ? WHERE id = ?')
    ->execute([$reportCount, $hidden, $postId]);

json_response(['ok' => true]);
