<?php
/**
 * DELETE /api/posts/:id — { authorId, authToken } in the JSON body (DELETE
 * requests still carry a body here, same idiom as api/follow_delete.php).
 * Permanently deletes a post the caller owns, plus its likes/comments/
 * reports rows and any uploaded photo/video file on disk. Only the post's
 * own author can delete it — require_owner() proves the token belongs to
 * that account, and the explicit author_id match below additionally
 * refuses to let anyone delete a post that isn't theirs even if they
 * somehow guessed someone else's post id.
 */
require_once __DIR__ . '/../includes/helpers.php';
require_method('DELETE');

$postId = (string)($_GET['id'] ?? '');
$body = request_json();
$authorId = trim((string)($body['authorId'] ?? ''));

if (!$postId || !$authorId) error_response('Missing post id or author id.', 400);

$pdo = db();

require_owner($pdo, $authorId, (string)($body['authToken'] ?? ''));

$postStmt = $pdo->prepare('SELECT author_id, photo_url, video_file_url FROM posts WHERE id = ?');
$postStmt->execute([$postId]);
$post = $postStmt->fetch();

if (!$post) error_response('Post not found.', 404);
if ($post['author_id'] !== $authorId) error_response('You can only delete your own posts.', 403);

$pdo->beginTransaction();
try {
    // No foreign keys tie these tables to posts (see schema.sql), so the
    // related rows need an explicit cleanup pass — same reasoning as
    // account_delete.php's own comment about this.
    $pdo->prepare('DELETE FROM likes WHERE post_id = ?')->execute([$postId]);
    $pdo->prepare('DELETE FROM comments WHERE post_id = ?')->execute([$postId]);
    $pdo->prepare('DELETE FROM reports WHERE post_id = ?')->execute([$postId]);
    $pdo->prepare('DELETE FROM posts WHERE id = ?')->execute([$postId]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

// Best-effort file cleanup, same pattern as account_delete.php — a
// failure here shouldn't undo the DB deletion that already succeeded.
foreach ([$post['photo_url'], $post['video_file_url']] as $url) {
    if (!$url) continue;
    $path = __DIR__ . '/../' . ltrim($url, '/');
    $real = realpath($path);
    $uploadsRoot = realpath(__DIR__ . '/../uploads');
    if ($real && $uploadsRoot && strpos($real, $uploadsRoot) === 0 && is_file($real)) {
        @unlink($real);
    }
}

json_response(['ok' => true]);
