<?php
require_once __DIR__ . '/../includes/helpers.php';
require_method('POST');

$postId = (string)($_GET['id'] ?? '');
$body = request_json();
$authorName = (string)($body['authorName'] ?? '');
$text = trim((string)($body['text'] ?? ''));

if (!$authorName || !$text) {
    error_response('Missing authorName or text.', 400);
}

$pdo = db();
$check = $pdo->prepare('SELECT id, author_id FROM posts WHERE id = ?');
$check->execute([$postId]);
$post = $check->fetch();
if (!$post) error_response('Post not found.', 404);

$ins = $pdo->prepare('INSERT INTO comments (post_id, author_name, text, created_at) VALUES (?, ?, ?, ?)');
$ins->execute([$postId, mb_substr($authorName, 0, 60), mb_substr($text, 0, 180), current_time_ms()]);

// No verified account id for the commenter here (comments aren't
// ownership-checked — see helpers.php's post_to_public() comment and
// deployment-status.md's audit notes), so actorId is null: the
// notification shows the name they typed but can't be tapped through to
// a profile. Still worth notifying the post's author that a comment came
// in at all.
create_notification(
    $pdo, $post['author_id'], null, $authorName, null,
    'comment', $authorName . ' commented: "' . mb_substr($text, 0, 80) . (mb_strlen($text) > 80 ? '…' : '') . '"',
    ['postId' => $postId]
);

$rowStmt = $pdo->prepare('SELECT * FROM posts WHERE id = ?');
$rowStmt->execute([$postId]);
json_response(post_to_public($pdo, $rowStmt->fetch()));
