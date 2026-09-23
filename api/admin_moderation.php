<?php
/**
 * api/admin/moderation — Kenneth's existing moderation queue page
 * (public_html/admin.html, predates this round of work) calls this
 * endpoint directly. It authenticates with the ADMIN_SECRET shared
 * secret already in config.php, sent as an "X-Admin-Secret" header — not
 * the ADMIN_KEY/`key` param scheme an earlier version of this file used,
 * which broke admin.html when it was uploaded. Matched to admin.html's
 * actual fetch() calls (verified against the live file), not guessed.
 *
 * GET ?type=comments -> the same queue for COMMENTS (added 2026-09-23
 *         with per-comment reporting; see api/post_report.php). A SEPARATE
 *         query rather than a new key on the existing response on purpose:
 *         admin.html's older deployed copy does `posts.map(...)` straight
 *         off a bare array, so turning the GET body into an object would
 *         have broken the live moderation page the moment this deployed.
 * POST { commentId, action } -> hide/dismiss one comment, same semantics.
 *
 * GET  -> every post with at least one outstanding report (reportCount >
 *         0), most-reported first — both posts already auto-hidden by
 *         REPORT_HIDE_THRESHOLD (see post_report.php) and posts still
 *         under it but reported at least once, so Kenneth can review
 *         before it would auto-hide.
 * POST { postId, action: "hide" | "dismiss" } ->
 *   "hide"    sets hidden = 1 (an explicit moderator call, independent of
 *             the auto-hide threshold).
 *   "dismiss" un-hides it (hidden = 0) -- "I looked at this, it's fine" --
 *             including a post that was auto-hidden.
 *   Either action clears this post's reports (report_count reset to 0,
 *   its `reports` rows deleted) since a decision has been made and it
 *   shouldn't linger in the queue.
 */
require_once __DIR__ . '/../includes/helpers.php';

function require_admin_secret(): void {
    $sent = (string)($_SERVER['HTTP_X_ADMIN_SECRET'] ?? '');
    // ADMIN_SECRET is what admin.html sends; fall back to ADMIN_KEY (the
    // secret every other admin endpoint uses) so a config.php that only
    // defines one of them still works. An empty secret never matches.
    $secret = (defined('ADMIN_SECRET') && ADMIN_SECRET !== '') ? ADMIN_SECRET
            : ((defined('ADMIN_KEY') && ADMIN_KEY !== '') ? ADMIN_KEY : '');
    if ($secret === '' || $sent === '' || !hash_equals($secret, $sent)) {
        error_response('Not authorized.', 401);
    }
}

require_admin_secret();
$pdo = db();
ensure_block_schema($pdo); // comments.hidden / report_count, comment_reports

if ($_SERVER['REQUEST_METHOD'] === 'GET' && (string)($_GET['type'] ?? '') === 'comments') {
    // The post's caption comes along so a one-line comment can be judged in
    // context — "that's disgusting" reads very differently under a photo of
    // a coat than under a photo of a person.
    $stmt = $pdo->query(
        'SELECT c.id, c.post_id, c.author_name, c.text, c.report_count, c.hidden, c.created_at,
                p.caption AS post_caption, p.author_name AS post_author_name
         FROM comments c
         LEFT JOIN posts p ON p.id = c.post_id
         WHERE c.report_count > 0
         ORDER BY c.report_count DESC, c.created_at DESC
         LIMIT 200'
    );
    json_response(array_map(function ($r) {
        return [
            'id' => (int)$r['id'],
            'postId' => $r['post_id'],
            'authorName' => $r['author_name'],
            'text' => $r['text'],
            'reportCount' => (int)$r['report_count'],
            'hidden' => !empty($r['hidden']),
            'createdAt' => (int)$r['created_at'],
            'postCaption' => $r['post_caption'],
            'postAuthorName' => $r['post_author_name'],
        ];
    }, $stmt->fetchAll()));
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->query(
        'SELECT id, author_name, caption, kibbe_tag, photo_url, video_file_url, report_count, created_at
         FROM posts WHERE report_count > 0 ORDER BY report_count DESC, created_at DESC LIMIT 200'
    );
    $rows = $stmt->fetchAll();
    json_response(array_map(function ($r) {
        return [
            'id' => $r['id'],
            'authorName' => $r['author_name'],
            'caption' => $r['caption'],
            'kibbeTag' => $r['kibbe_tag'],
            'photoUrl' => $r['photo_url'],
            'videoFileUrl' => $r['video_file_url'],
            'reportCount' => (int)$r['report_count'],
            'createdAt' => (int)$r['created_at'],
        ];
    }, $rows));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = request_json();
    $postId = (string)($body['postId'] ?? '');
    $commentId = isset($body['commentId']) ? (int)$body['commentId'] : 0;
    $action = (string)($body['action'] ?? '');

    if ($commentId > 0) {
        if (!in_array($action, ['hide', 'dismiss'], true)) {
            error_response('Missing or invalid action.', 400);
        }
        $hidden = $action === 'hide' ? 1 : 0;
        $pdo->prepare('UPDATE comments SET hidden = ?, report_count = 0 WHERE id = ?')->execute([$hidden, $commentId]);
        $pdo->prepare('DELETE FROM comment_reports WHERE comment_id = ?')->execute([$commentId]);
        json_response(['ok' => true]);
    }

    if (!$postId || !in_array($action, ['hide', 'dismiss'], true)) {
        error_response('Missing or invalid postId/action.', 400);
    }

    $hidden = $action === 'hide' ? 1 : 0;
    $pdo->prepare('UPDATE posts SET hidden = ?, report_count = 0 WHERE id = ?')->execute([$hidden, $postId]);
    $pdo->prepare('DELETE FROM reports WHERE post_id = ?')->execute([$postId]);

    json_response(['ok' => true]);
}

error_response('Method not allowed.', 405);
