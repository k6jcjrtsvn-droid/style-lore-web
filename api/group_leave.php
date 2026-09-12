<?php
/**
 * POST /api/groups/{id}/leave {visitorId, authToken} — leaves a group
 * (idempotent — not a member just returns the current count).
 */
require_once __DIR__ . '/../includes/helpers.php';
require_method('POST');

$groupId = (string)($_GET['id'] ?? '');
$body = request_json();
$visitorId = (string)($body['visitorId'] ?? '');

$pdo = db();
require_owner($pdo, $visitorId, (string)($body['authToken'] ?? ''));

$groupStmt = $pdo->prepare('SELECT id FROM interest_groups WHERE id = ?');
$groupStmt->execute([$groupId]);
if (!$groupStmt->fetch()) error_response('Group not found.', 404);

$del = $pdo->prepare('DELETE FROM interest_group_members WHERE group_id = ? AND account_id = ?');
$del->execute([$groupId, $visitorId]);
if ($del->rowCount() > 0) {
    $pdo->prepare('UPDATE interest_groups SET member_count = GREATEST(0, member_count - 1) WHERE id = ?')->execute([$groupId]);
}

$countStmt = $pdo->prepare('SELECT member_count FROM interest_groups WHERE id = ?');
$countStmt->execute([$groupId]);
json_response(['ok' => true, 'memberCount' => (int)$countStmt->fetch()['member_count']]);
