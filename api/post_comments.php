<?php
require_once __DIR__ . '/../includes/helpers.php';
require_method('POST');

$postId = (string)($_GET['id'] ?? '');
$body = request_json();
$visitorId = (string)($body['visitorId'] ?? '');
$text = trim((string)($body['text'] ?? ''));
// Replying to a comment rather than to the post itself. 0/absent = a new
// top-level comment, which is what every client sent before threading.
$parentId = isset($body['parentId']) ? (int)$body['parentId'] : 0;

if (!$visitorId || !$text) {
    error_response('Missing visitorId or text.', 400);
}

$pdo = db();
ensure_comment_threads_schema($pdo);
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

// A reply must belong to the same post, and threads stay one level deep:
// replying to a reply attaches to that reply's root, so the UI never has to
// indent twice on a phone screen. An unknown or foreign parent id is treated
// as a plain top-level comment rather than an error — the comment is worth
// more than the thread position.
$parentRow = null;
if ($parentId > 0) {
    try {
        $pStmt = $pdo->prepare('SELECT id, parent_id, author_id, author_name FROM comments WHERE id = ? AND post_id = ?');
        $pStmt->execute([$parentId, $postId]);
        $parentRow = $pStmt->fetch() ?: null;
    } catch (PDOException $e) { $parentRow = null; }
    if ($parentRow && $parentRow['parent_id']) {
        $rootStmt = $pdo->prepare('SELECT id, parent_id, author_id, author_name FROM comments WHERE id = ? AND post_id = ?');
        $rootStmt->execute([(int)$parentRow['parent_id'], $postId]);
        $root = $rootStmt->fetch();
        if ($root) $parentRow = $root;
    }
}
$storedParentId = $parentRow ? (int)$parentRow['id'] : null;

$newCommentId = null;
try {
    $ins = $pdo->prepare('INSERT INTO comments (post_id, parent_id, author_id, author_name, text, created_at) VALUES (?, ?, ?, ?, ?, ?)');
    $ins->execute([$postId, $storedParentId, $visitorId, $authorName, mb_substr($text, 0, 180), current_time_ms()]);
    $newCommentId = (int)$pdo->lastInsertId();
} catch (PDOException $e) {
    // comments.parent_id / author_id missing (ensure_comment_threads_schema
    // could not add them) — keep comments working, just unthreaded.
    error_log('comments parent_id/author_id missing — ' . $e->getMessage());
    $ins = $pdo->prepare('INSERT INTO comments (post_id, author_name, text, created_at) VALUES (?, ?, ?, ?)');
    $ins->execute([$postId, $authorName, mb_substr($text, 0, 180), current_time_ms()]);
    $newCommentId = (int)$pdo->lastInsertId();
    $storedParentId = null;
    $parentRow = null;
}

$snippet = mb_substr($text, 0, 80) . (mb_strlen($text) > 80 ? '…' : '');
// Who hears about this: a reply pings the person being replied to, a
// top-level comment pings the post's author. `commentId` rides along so the
// tap lands on the comment itself, not just somewhere on the post.
$notified = [];
if ($parentRow && !empty($parentRow['author_id']) && $parentRow['author_id'] !== $visitorId) {
    create_notification(
        $pdo, (string)$parentRow['author_id'], $visitorId, $authorName, $prof['avatar_url'] ?? null,
        'comment', $authorName . ' replied: "' . $snippet . '"',
        ['postId' => $postId, 'commentId' => $newCommentId]
    );
    $notified[] = (string)$parentRow['author_id'];
}
if ($post['author_id'] !== $visitorId && !in_array((string)$post['author_id'], $notified, true)) {
    create_notification(
        $pdo, $post['author_id'], $visitorId, $authorName, $prof['avatar_url'] ?? null,
        'comment',
        $parentRow
            ? $authorName . ' replied on your post: "' . $snippet . '"'
            : $authorName . ' commented: "' . $snippet . '"',
        ['postId' => $postId, 'commentId' => $newCommentId]
    );
}

$rowStmt = $pdo->prepare('SELECT * FROM posts WHERE id = ?');
$rowStmt->execute([$postId]);
$out = post_to_public($pdo, $rowStmt->fetch());
// So the client can scroll to / highlight the comment it just wrote.
$out['newCommentId'] = $newCommentId;
json_response($out);
