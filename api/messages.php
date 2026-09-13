<?php
/**
 * GET /api/messages?conversationId=&visitorId=&authToken= — every message
 * in a conversation, oldest first, and marks it read for this member
 * (updates their own last_read_at). 403s if the visitor isn't actually a
 * member of that conversation.
 *
 * POST /api/messages {conversationId, visitorId, authToken, text} — sends
 * a message, updates the conversation's last-message preview, and
 * notifies the other member.
 */
require_once __DIR__ . '/../includes/helpers.php';

$pdo = db();

function require_conversation_member(PDO $pdo, string $conversationId, string $visitorId): void {
    $stmt = $pdo->prepare('SELECT 1 FROM conversation_members WHERE conversation_id = ? AND account_id = ?');
    $stmt->execute([$conversationId, $visitorId]);
    if (!$stmt->fetch()) error_response('Not a member of this conversation.', 403);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $conversationId = (string)($_GET['conversationId'] ?? '');
    $visitorId = (string)($_GET['visitorId'] ?? '');
    require_owner($pdo, $visitorId, bearer_token());
    if (!$conversationId) error_response('Missing conversationId.', 400);
    require_conversation_member($pdo, $conversationId, $visitorId);

    $stmt = $pdo->prepare('SELECT * FROM (SELECT * FROM messages WHERE conversation_id = ? ORDER BY created_at DESC LIMIT 300) AS recent ORDER BY created_at ASC');
    $stmt->execute([$conversationId]);
    $rows = $stmt->fetchAll();

    $pdo->prepare('UPDATE conversation_members SET last_read_at = ? WHERE conversation_id = ? AND account_id = ?')
        ->execute([current_time_ms(), $conversationId, $visitorId]);

    json_response(array_map('message_to_public', $rows));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = request_json();
    $conversationId = (string)($body['conversationId'] ?? '');
    $visitorId = (string)($body['visitorId'] ?? '');
    $text = trim((string)($body['text'] ?? ''));

    if (!$conversationId || !$text) error_response('Missing conversationId or text.', 400);
    require_owner($pdo, $visitorId, (string)($body['authToken'] ?? ''));
    require_verified($pdo, $visitorId);
    rate_limit($pdo, 'msg:' . $visitorId, 60, 60);
    require_conversation_member($pdo, $conversationId, $visitorId);

    $senderStmt = $pdo->prepare('SELECT account_name FROM conversation_members WHERE conversation_id = ? AND account_id = ?');
    $senderStmt->execute([$conversationId, $visitorId]);
    $senderRow = $senderStmt->fetch();
    $senderName = $senderRow ? $senderRow['account_name'] : 'Someone';

    $text = mb_substr($text, 0, 2000);
    $id = uuidv4();
    $now = current_time_ms();
    $pdo->prepare('INSERT INTO messages (id, conversation_id, sender_id, sender_name, text, created_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$id, $conversationId, $visitorId, $senderName, $text, $now]);

    $preview = mb_substr($text, 0, 180);
    $pdo->prepare('UPDATE conversations SET last_message_at = ?, last_message_preview = ? WHERE id = ?')
        ->execute([$now, $preview, $conversationId]);
    // The sender has obviously "read" their own message.
    $pdo->prepare('UPDATE conversation_members SET last_read_at = ? WHERE conversation_id = ? AND account_id = ?')
        ->execute([$now, $conversationId, $visitorId]);

    $othersStmt = $pdo->prepare('SELECT account_id FROM conversation_members WHERE conversation_id = ? AND account_id != ?');
    $othersStmt->execute([$conversationId, $visitorId]);
    foreach ($othersStmt->fetchAll() as $other) {
        create_notification(
            $pdo, $other['account_id'], $visitorId, $senderName, null,
            'message', $senderName . ' sent you a message.',
            ['conversationId' => $conversationId, 'otherId' => $visitorId, 'otherName' => $senderName]
        );
    }

    $rowStmt = $pdo->prepare('SELECT * FROM messages WHERE id = ?');
    $rowStmt->execute([$id]);
    json_response(message_to_public($rowStmt->fetch()), 201);
}

error_response('Method not allowed.', 405);
