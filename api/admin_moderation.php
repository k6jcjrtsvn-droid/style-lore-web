<?php
/**
 * api/admin_moderation.php — Kenneth-only moderation, gated by a shared
 * secret (ADMIN_KEY in config.php) rather than any account/session — there
 * is no admin account concept in this app, just its one operator.
 *
 * GET  ?key=<ADMIN_KEY>                     — list currently-hidden posts
 * POST { key, action:"unhide", id:"<postId>" } — restore a hidden post and
 *                                                clear its reports
 *
 * If ADMIN_KEY is blank in config.php (the default), this endpoint refuses
 * every request — set a real key there to use it. See config.sample.php
 * for how to generate one.
 */
require_once __DIR__ . '/../includes/helpers.php';

$pdo = db();

function require_admin_key(): void {
    $key = (string)($_GET['key'] ?? '');
    if ($key === '') {
        $body = request_json();
        $key = (string)($body['key'] ?? '');
    }
    if (!defined('ADMIN_KEY') || ADMIN_KEY === '' || !hash_equals(ADMIN_KEY, $key)) {
        error_response('Not authorized.', 401);
    }
}

require_admin_key();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->query('SELECT id, author_id, author_name, caption, report_count, created_at FROM posts WHERE hidden = 1 ORDER BY created_at DESC LIMIT 200');
    json_response($stmt->fetchAll());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = request_json();
    $action = (string)($body['action'] ?? '');
    $id = (string)($body['id'] ?? '');

    if ($action === 'unhide' && $id) {
        $pdo->prepare('UPDATE posts SET hidden = 0, report_count = 0 WHERE id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM reports WHERE post_id = ?')->execute([$id]);
        json_response(['ok' => true]);
    }

    error_response('Unknown action.', 400);
}

error_response('Method not allowed.', 405);
