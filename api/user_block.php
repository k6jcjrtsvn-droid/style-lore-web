<?php
/**
 * Blocking another member. Required by App Store Review Guideline 1.2 for
 * any app carrying user-generated content; see the block section in
 * includes/helpers.php for why a block hides in both directions.
 *
 * GET  /api/user_block?visitorId=&authToken=
 *      → [{ id, name, avatarUrl, createdAt }] — who this account has
 *        blocked, newest first. Drives the "Blocked accounts" list in
 *        Settings, which is the only place a block can be undone.
 *
 * POST /api/user_block { visitorId, authToken, targetId, action }
 *      action = "block" | "unblock".
 *
 * Blocking also tears down any existing relationship between the two:
 * follows in both directions go, and so do notifications already sitting
 * in the blocker's list from that person. Leaving those behind would mean
 * you blocked someone and still had their name in your notifications.
 */
require_once __DIR__ . '/../includes/helpers.php';

$pdo = db();
ensure_block_schema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $visitorId = (string)($_GET['visitorId'] ?? '');
    require_owner($pdo, $visitorId, bearer_token());

    $stmt = $pdo->prepare(
        'SELECT b.blocked_id, b.created_at, p.name, p.avatar_url
         FROM blocked_users b
         LEFT JOIN profiles p ON p.id = b.blocked_id
         WHERE b.blocker_id = ?
         ORDER BY b.created_at DESC
         LIMIT 500'
    );
    $stmt->execute([$visitorId]);

    json_response(array_map(function ($r) {
        return [
            'id' => $r['blocked_id'],
            // A blocked account that has since deleted its profile still
            // needs a row here, or it could never be unblocked.
            'name' => $r['name'] ?: 'Someone',
            'avatarUrl' => $r['avatar_url'],
            'createdAt' => (int)$r['created_at'],
        ];
    }, $stmt->fetchAll()));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = request_json();
    $visitorId = (string)($body['visitorId'] ?? '');
    $targetId = (string)($body['targetId'] ?? '');
    $action = (string)($body['action'] ?? 'block');

    if (!$targetId) error_response('Missing targetId.', 400);
    if ($targetId === $visitorId) error_response('You cannot block yourself.', 400);

    require_owner($pdo, $visitorId, (string)($body['authToken'] ?? ''));

    if ($action === 'unblock') {
        $pdo->prepare('DELETE FROM blocked_users WHERE blocker_id = ? AND blocked_id = ?')
            ->execute([$visitorId, $targetId]);
        json_response(['ok' => true, 'blocked' => false]);
    }

    $exists = $pdo->prepare('SELECT id FROM accounts WHERE id = ?');
    $exists->execute([$targetId]);
    if (!$exists->fetch()) error_response('That account no longer exists.', 404);

    // A rate limit here is not about abuse of the feature so much as about
    // a runaway client: blocking is idempotent, so a loop would otherwise
    // hammer the follows/notifications deletes below.
    rate_limit($pdo, 'block:' . $visitorId, 60, 3600);

    $pdo->prepare('INSERT IGNORE INTO blocked_users (blocker_id, blocked_id, created_at) VALUES (?, ?, ?)')
        ->execute([$visitorId, $targetId, current_time_ms()]);

    // Follows, both ways.
    try {
        $pdo->prepare('DELETE FROM follows WHERE (follower_id = ? AND following_id = ?) OR (follower_id = ? AND following_id = ?)')
            ->execute([$visitorId, $targetId, $targetId, $visitorId]);
    } catch (Throwable $e) { error_log('user_block follows: ' . $e->getMessage()); }

    // Notifications the blocked person generated for the blocker. The
    // reverse is deliberately left alone — the blocked account is not told
    // anything happened.
    try {
        $pdo->prepare('DELETE FROM notifications WHERE recipient_id = ? AND actor_id = ?')
            ->execute([$visitorId, $targetId]);
    } catch (Throwable $e) { error_log('user_block notifications: ' . $e->getMessage()); }

    json_response(['ok' => true, 'blocked' => true]);
}

error_response('Method not allowed.', 405);
