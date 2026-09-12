<?php
/**
 * POST /api/groups/{id}/join {visitorId, visitorName, authToken} — joins
 * a group (idempotent — already a member just returns the current count).
 * Notifies the group's creator that someone joined.
 */
require_once __DIR__ . '/../includes/helpers.php';
require_method('POST');

$groupId = (string)($_GET['id'] ?? '');
$body = request_json();
$visitorId = (string)($body['visitorId'] ?? '');
$visitorName = mb_substr(trim((string)($body['visitorName'] ?? '')), 0, 60);

$pdo = db();
require_owner($pdo, $visitorId, (string)($body['authToken'] ?? ''));

$groupStmt = $pdo->prepare('SELECT id, name, creator_id FROM interest_groups WHERE id = ?');
$groupStmt->execute([$groupId]);
$group = $groupStmt->fetch();
if (!$group) error_response('Group not found.', 404);

$check = $pdo->prepare('SELECT 1 FROM interest_group_members WHERE group_id = ? AND account_id = ?');
$check->execute([$groupId, $visitorId]);
if (!$check->fetch()) {
    $pdo->prepare('INSERT INTO interest_group_members (group_id, account_id, account_name, joined_at) VALUES (?, ?, ?, ?)')
        ->execute([$groupId, $visitorId, $visitorName, current_time_ms()]);
    $pdo->prepare('UPDATE interest_groups SET member_count = member_count + 1 WHERE id = ?')->execute([$groupId]);

    create_notification(
        $pdo, $group['creator_id'], $visitorId, $visitorName ?: 'Someone', null,
        'group_join', ($visitorName ?: 'Someone') . ' joined ' . $group['name'] . '.',
        ['groupId' => $groupId]
    );
}

$countStmt = $pdo->prepare('SELECT member_count FROM interest_groups WHERE id = ?');
$countStmt->execute([$groupId]);
json_response(['ok' => true, 'memberCount' => (int)$countStmt->fetch()['member_count']]);
