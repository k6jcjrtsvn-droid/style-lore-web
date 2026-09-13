<?php
require_once __DIR__ . '/../includes/helpers.php';
require_method('POST');

$postId = (string)($_GET['id'] ?? '');
$body = request_json();
$visitorId = (string)($body['visitorId'] ?? '');
$text = trim((string)($body['text'] ?? ''));

if (!$visitorId || !$text) {
    error_response('Missing visitorId or text.', 400);
}

$pdo = db();
// Ownership check — only a logged-in account can comment, and the name
// shown is the one on their profile, not whatever the client sent.
require_owner($pdo, $visitorId, (string)($body['authToken'] ?? ''));
rate_limit($pdo, 'comment:' . $visitorId, 30, 60);

$profStmt = $pdo->prepare('SELECT name, avatar_url FROM profiles WHERE id = ?');
$profStmt->execute([$visitorId]);
$prof = $profStmt->fetch();
$authorName = mb_substr(trim((string)($prof['name'] ?? '')), 0, 60);
if ($authorName === '') $authorName = 'Style-LORE member';

$check = $pdo->prepare('SELECT id, author_id FROM posts WHERE id = ?');
$check->execute([$postId]);
$post = $check->fetch();
if (!$post) error_response('Post not found.', 404);

try {
    $ins = $pdo->prepare('INSERT INTO comments (post_id, author_id, author_name, text, created_at) VALUES (?, ?, ?, ?, ?)');
    $ins->execute([$postId, $visitorId, $authorName, mb_substr($text, 0, 180), current_time_ms()]);
} catch (PDOException $e) {
    // MIGRATE-2026-09-17.sql (comments.author_id) not run yet — keep
    // comments working, just without the author id, and say so in the log.
    error_log('comments.author_id missing? run MIGRATE-2026-09-17.sql — ' . $e->getMessage());
    $ins = $pdo->prepare('INSERT INTO comments (post_id, author_name, text, created_at) VALUES (?, ?, ?, ?)');
    $ins->execute([$postId, $authorName, mb_substr($text, 0, 180), current_time_ms()]);
}

if ($post['author_id'] !== $visitorId) {
    create_notification(
        $pdo, $post['author_id'], $visitorId, $authorName, $prof['avatar_url'] ?? null,
        'comment', $authorName . ' commented: "' . mb_substr($text, 0, 80) . (mb_strlen($text) > 80 ? '…' : '') . '"',
        ['postId' => $postId]
    );
}

$rowStmt = $pdo->prepare('SELECT * FROM posts WHERE id = ?');
$rowStmt->execute([$postId]);
json_response(post_to_public($pdo, $rowStmt->fetch()));
