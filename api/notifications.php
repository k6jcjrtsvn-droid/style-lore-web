<?php
/**
 * GET /api/notifications?visitorId=&authToken= — this account's own
 * notifications (likes, comments, follows, messages, group joins),
 * newest first, plus an unread count for the topbar badge.
 *
 * POST /api/notifications {visitorId, authToken, markAllRead:true} —
 * marks every unread notification for this account as read (called when
 * the Notifications screen is opened).
 */
require_once __DIR__ . '/../includes/helpers.php';

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $visitorId = (string)($_GET['visitorId'] ?? '');
    require_owner($pdo, $visitorId, (string)($_GET['authToken'] ?? ''));

    $stmt = $pdo->prepare('SELECT * FROM notifications WHERE recipient_id = ? ORDER BY created_at DESC LIMIT 50');
    $stmt->execute([$visitorId]);
    $rows = $stmt->fetchAll();

    $countStmt = $pdo->prepare('SELECT COUNT(*) AS c FROM notifications WHERE recipient_id = ? AND is_read = 0');
    $countStmt->execute([$visitorId]);
    $unreadCount = (int)($countStmt->fetch()['c'] ?? 0);

    json_response([
        'notifications' => array_map('notification_to_public', $rows),
        'unreadCount' => $unreadCount,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = request_json();
    $visitorId = (string)($body['visitorId'] ?? '');
    require_owner($pdo, $visitorId, (string)($body['authToken'] ?? ''));

    if (!empty($body['markAllRead'])) {
        $upd = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE recipient_id = ? AND is_read = 0');
        $upd->execute([$visitorId]);
        json_response(['ok' => true]);
    }

    error_response('Nothing to do.', 400);
}

error_response('Method not allowed.', 405);
