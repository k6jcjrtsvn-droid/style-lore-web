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
// Reporting one comment rather than the whole post. Guideline 1.2 wants a
// report path on the objectionable thing itself, and "the post is fine but
// this reply is not" was previously unreportable — the only option was to
// report the post, punishing its author for someone else's comment.
$commentId = isset($body['commentId']) ? (int)$body['commentId'] : 0;

if (!$visitorId) error_response('Missing visitorId.', 400);

$pdo = db();

// Ownership check — a report only counts from a real, logged-in account,
// so hitting the hide threshold takes that many distinct real people, not
// just that many made-up ids in a request body.
require_owner($pdo, $visitorId, (string)($body['authToken'] ?? ''));

ensure_block_schema($pdo);

if ($commentId > 0) {
    $c = $pdo->prepare('SELECT id, post_id FROM comments WHERE id = ?');
    $c->execute([$commentId]);
    $commentRow = $c->fetch();
    if (!$commentRow) error_response('Comment not found.', 404);

    $pdo->prepare('INSERT IGNORE INTO comment_reports (comment_id, visitor_id, created_at) VALUES (?, ?, ?)')
        ->execute([$commentId, $visitorId, current_time_ms()]);

    $cc = $pdo->prepare('SELECT COUNT(*) AS c FROM comment_reports WHERE comment_id = ?');
    $cc->execute([$commentId]);
    $commentReports = (int)$cc->fetch()['c'];

    // Same threshold as a post, and for the same reason: one person should
    // not be able to delete someone else's words on their own. See
    // REPORT_HIDE_THRESHOLD in helpers.php.
    $commentHidden = $commentReports >= REPORT_HIDE_THRESHOLD ? 1 : 0;
    $pdo->prepare('UPDATE comments SET report_count = ?, hidden = ? WHERE id = ?')
        ->execute([$commentReports, $commentHidden, $commentId]);

    json_response(['ok' => true, 'hidden' => (bool)$commentHidden]);
}

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
