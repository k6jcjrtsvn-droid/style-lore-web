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
$check = $pdo->prepare('SELECT id FROM posts WHERE id = ?');
$check->execute([$postId]);
if (!$check->fetch()) error_response('Post not found.', 404);

$ins = $pdo->prepare('INSERT INTO comments (post_id, author_name, text, created_at) VALUES (?, ?, ?, ?)');
$ins->execute([$postId, mb_substr($authorName, 0, 60), mb_substr($text, 0, 180), current_time_ms()]);

$rowStmt = $pdo->prepare('SELECT * FROM posts WHERE id = ?');
$rowStmt->execute([$postId]);
json_response(post_to_public($pdo, $rowStmt->fetch()));
