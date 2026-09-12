<?php
/**
 * GET /api/conversations?visitorId=&authToken= — this account's DM
 * inbox: every conversation they're a member of, newest activity first,
 * with the other member's name/avatar and whether there's anything
 * unread. 1:1 only for now — see the `conversations` table's schema
 * comment for why.
 *
 * POST /api/conversations {visitorId, visitorName, authToken, otherId,
 * otherName} — get-or-create the 1:1 conversation between these two
 * accounts (never creates a duplicate — reuses the existing one if the
 * two have already messaged before). Used by the "Message" button on
 * someone's profile.
 */
require_once __DIR__ . '/../includes/helpers.php';

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $visitorId = (string)($_GET['visitorId'] ?? '');
    require_owner($pdo, $visitorId, (string)($_GET['authToken'] ?? ''));

    $stmt = $pdo->prepare(
        'SELECT c.id, c.last_message_at, c.last_message_preview, cm.last_read_at,
                other.account_id AS other_id, other.account_name AS other_name, p.avatar_url AS other_avatar_url
         FROM conversations c
         JOIN conversation_members cm ON cm.conversation_id = c.id AND cm.account_id = ?
         JOIN conversation_members other ON other.conversation_id = c.id AND other.account_id != ?
         LEFT JOIN profiles p ON p.id = other.account_id
         ORDER BY c.last_message_at DESC
         LIMIT 100'
    );
    $stmt->execute([$visitorId, $visitorId]);
    $rows = $stmt->fetchAll();

    json_response(array_map(function ($r) {
        return [
            'id' => $r['id'],
            'otherId' => $r['other_id'],
            'otherName' => $r['other_name'],
            'otherAvatarUrl' => $r['other_avatar_url'],
            'lastMessagePreview' => $r['last_message_preview'],
            'lastMessageAt' => (int)$r['last_message_at'],
            'unread' => (int)$r['last_message_at'] > (int)$r['last_read_at'],
        ];
    }, $rows));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = request_json();
    $visitorId = (string)($body['visitorId'] ?? '');
    $visitorName = mb_substr(trim((string)($body['visitorName'] ?? '')), 0, 60);
    $otherId = (string)($body['otherId'] ?? '');
    $otherName = mb_substr(trim((string)($body['otherName'] ?? '')), 0, 60);

    if (!$otherId || $otherId === $visitorId) error_response('Invalid conversation request.', 400);
    require_owner($pdo, $visitorId, (string)($body['authToken'] ?? ''));

    // Reuse an existing 1:1 conversation between these two accounts if
    // one already exists, rather than creating a second thread.
    $find = $pdo->prepare(
        'SELECT cm1.conversation_id FROM conversation_members cm1
         JOIN conversation_members cm2 ON cm1.conversation_id = cm2.conversation_id
         WHERE cm1.account_id = ? AND cm2.account_id = ?
         LIMIT 1'
    );
    $find->execute([$visitorId, $otherId]);
    $existing = $find->fetch();
    if ($existing) json_response(['id' => $existing['conversation_id']]);

    $id = uuidv4();
    $now = current_time_ms();
    $pdo->prepare('INSERT INTO conversations (id, created_at, last_message_at, last_message_preview) VALUES (?, ?, ?, ?)')
        ->execute([$id, $now, $now, '']);
    $memberIns = $pdo->prepare('INSERT INTO conversation_members (conversation_id, account_id, account_name, last_read_at) VALUES (?, ?, ?, ?)');
    $memberIns->execute([$id, $visitorId, $visitorName, $now]);
    $memberIns->execute([$id, $otherId, $otherName, 0]);

    json_response(['id' => $id], 201);
}

error_response('Method not allowed.', 405);
