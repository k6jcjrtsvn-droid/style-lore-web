<?php
require_once __DIR__ . '/../includes/helpers.php';
require_method('POST');

$postId = (string)($_GET['id'] ?? '');
$body = request_json();
$visitorId = (string)($body['visitorId'] ?? '');
$liked = !empty($body['liked']);

if (!$visitorId) error_response('Missing visitorId.', 400);

$pdo = db();

// Ownership check — only the visitor themself can record their own like.
require_owner($pdo, $visitorId, (string)($body['authToken'] ?? ''));

$postCheck = $pdo->prepare('SELECT id, author_id, author_name FROM posts WHERE id = ?');
$postCheck->execute([$postId]);
$post = $postCheck->fetch();
if (!$post) error_response('Post not found.', 404);

$now = current_time_ms();
$stmt = $pdo->prepare(
    'INSERT INTO likes (post_id, visitor_id, liked, updated_at) VALUES (?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE liked = VALUES(liked), updated_at = VALUES(updated_at)'
);
$stmt->execute([$postId, $visitorId, $liked ? 1 : 0, $now]);

if ($liked && $post['author_id'] !== $visitorId) {
    $visitorStmt = $pdo->prepare('SELECT name, avatar_url FROM profiles WHERE id = ?');
    $visitorStmt->execute([$visitorId]);
    $visitorProfile = $visitorStmt->fetch();
    $visitorName = ($visitorProfile && $visitorProfile['name']) ? $visitorProfile['name'] : 'Someone';
    create_notification(
        $pdo, $post['author_id'], $visitorId, $visitorName, $visitorProfile ? $visitorProfile['avatar_url'] : null,
        'like', $visitorName . ' liked your post.', ['actorId' => $visitorId, 'postId' => $postId]
    );
}

$rowStmt = $pdo->prepare('SELECT * FROM posts WHERE id = ?');
$rowStmt->execute([$postId]);
json_response(post_to_public($pdo, $rowStmt->fetch()));
