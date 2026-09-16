<?php
/**
 * POST /api/posts/:id/vote  {visitorId, authToken, choice: "a"|"b"}
 *
 * One vote per account on a "Help me choose" poll. Signed-in only, and the
 * primary key on (post_id, voter_id) is what actually enforces it — a device
 * id would be resettable, and percentages nobody trusts are worse than no
 * percentages at all.
 *
 * Changing your mind is allowed (the upsert overwrites), because a poll
 * where the first tap is final punishes the fat-fingered rather than the
 * dishonest. Only the tallies are ever returned; who voted for what stays
 * private (see post_to_public).
 */
require_once __DIR__ . '/../includes/helpers.php';
require_method('POST');

$postId = (string)($_GET['id'] ?? '');
$body = request_json();
$visitorId = (string)($body['visitorId'] ?? '');
$choice = (string)($body['choice'] ?? '');

if ($postId === '') error_response('Missing post id.', 400);
if ($choice !== 'a' && $choice !== 'b') error_response('Pick one of the two options.', 400);
if ($visitorId === '') error_response('Sign in to vote.', 401);

$pdo = db();
require_owner($pdo, $visitorId, (string)($body['authToken'] ?? ''));
ensure_poll_schema($pdo);

$postStmt = $pdo->prepare('SELECT * FROM posts WHERE id = ? AND hidden = 0');
$postStmt->execute([$postId]);
$post = $postStmt->fetch();
if (!$post) error_response('Post not found.', 404);
if (empty($post['is_poll'])) error_response('That post is not a poll.', 400);

$now = current_time_ms();
$already = $pdo->prepare('SELECT choice FROM poll_votes WHERE post_id = ? AND voter_id = ?');
$already->execute([$postId, $visitorId]);
$previous = $already->fetchColumn() ?: null;

$vote = $pdo->prepare(
    'INSERT INTO poll_votes (post_id, voter_id, choice, created_at) VALUES (?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE choice = VALUES(choice)'
);
$vote->execute([$postId, $visitorId, $choice, $now]);

/* Tell the author their poll is getting answers — but at milestones, not on
 * every vote. A poll that takes off would otherwise buzz someone's phone
 * forty times in an evening, which is how people turn notifications off for
 * good. Changing an existing vote is never a milestone. */
if ($previous === null && $post['author_id'] !== $visitorId) {
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM poll_votes WHERE post_id = ?');
    $countStmt->execute([$postId]);
    $total = (int)$countStmt->fetchColumn();

    if (in_array($total, [1, 3, 10, 25, 50], true)) {
        $message = $total === 1
            ? 'Someone voted on your outfit.'
            : $total . ' people have voted on your outfit.';
        create_notification(
            $pdo, $post['author_id'], null, 'Style-LORE', null,
            'poll', $message, ['postId' => $postId]
        );
    }
}

$rowStmt = $pdo->prepare('SELECT * FROM posts WHERE id = ?');
$rowStmt->execute([$postId]);
json_response(post_to_public($pdo, $rowStmt->fetch(), $visitorId));
