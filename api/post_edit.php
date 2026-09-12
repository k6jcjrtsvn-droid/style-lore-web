<?php
/**
 * PUT /api/posts/:id — { authorId, authToken, caption?, styleTags?,
 * kibbeTag? } — edits a post's text fields. Only the post's own author
 * can edit it (same require_owner() + explicit author_id match pattern as
 * post_delete.php). Deliberately text-only: the photo/video attached to a
 * post can't be swapped out this way — matching how most apps handle post
 * edits (delete and re-post with new media, rather than replacing it in
 * place). Any field left out of the request body keeps its current value.
 */
require_once __DIR__ . '/../includes/helpers.php';
require_method('PUT');

$postId = (string)($_GET['id'] ?? '');
$body = request_json();
$authorId = trim((string)($body['authorId'] ?? ''));

if (!$postId || !$authorId) error_response('Missing post id or author id.', 400);

$pdo = db();

require_owner($pdo, $authorId, (string)($body['authToken'] ?? ''));

$postStmt = $pdo->prepare('SELECT * FROM posts WHERE id = ?');
$postStmt->execute([$postId]);
$post = $postStmt->fetch();

if (!$post) error_response('Post not found.', 404);
if ($post['author_id'] !== $authorId) error_response('You can only edit your own posts.', 403);

$caption = array_key_exists('caption', $body) ? mb_substr(trim((string)$body['caption']), 0, 280) : $post['caption'];
$kibbeTag = array_key_exists('kibbeTag', $body) ? trim((string)$body['kibbeTag']) : $post['kibbe_tag'];

$styleTags = json_decode($post['style_tags'] ?: '[]', true);
if (!is_array($styleTags)) $styleTags = [];
if (array_key_exists('styleTags', $body) && is_array($body['styleTags'])) {
    $styleTags = array_values(array_slice(array_filter($body['styleTags'], 'is_string'), 0, 3));
}

if (!$kibbeTag) error_response('Missing Kibbe tag.', 400);
if (!$caption && !$post['photo_url'] && !$post['video_file_url'] && !$post['video_url']) {
    error_response('A post needs a caption, photo, or video.', 400);
}

$pdo->prepare('UPDATE posts SET caption = ?, kibbe_tag = ?, style_tags = ? WHERE id = ?')
    ->execute([$caption, $kibbeTag, json_encode($styleTags), $postId]);

$rowStmt = $pdo->prepare('SELECT * FROM posts WHERE id = ?');
$rowStmt->execute([$postId]);
json_response(post_to_public($pdo, $rowStmt->fetch()));
