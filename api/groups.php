<?php
/**
 * GET /api/groups — list all groups, newest first (optionally pass
 * ?visitorId= to also get an `isMember` flag per group).
 * GET /api/groups/{id} (routed here with $_GET['id'] set) — one group's
 * detail, same shape plus isMember when ?visitorId= is given.
 *
 * POST /api/groups {name, description, topicKibbe, topicStyle, creatorId,
 * creatorName, authToken} — creates a new group with the creator as its
 * first member.
 */
require_once __DIR__ . '/../includes/helpers.php';

$pdo = db();
$id = (string)($_GET['id'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $visitorId = (string)($_GET['visitorId'] ?? '');

    if ($id !== '') {
        $stmt = $pdo->prepare('SELECT * FROM interest_groups WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) error_response('Group not found.', 404);
        $isMember = null;
        if ($visitorId !== '') {
            $m = $pdo->prepare('SELECT 1 FROM interest_group_members WHERE group_id = ? AND account_id = ?');
            $m->execute([$id, $visitorId]);
            $isMember = (bool)$m->fetch();
        }
        json_response(group_to_public($row, $isMember));
    }

    $stmt = $pdo->prepare('SELECT * FROM interest_groups ORDER BY created_at DESC LIMIT 100');
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $memberSet = [];
    if ($visitorId !== '' && $rows) {
        $groupIds = array_column($rows, 'id');
        $ph = implode(',', array_fill(0, count($groupIds), '?'));
        $m = $pdo->prepare("SELECT group_id FROM interest_group_members WHERE account_id = ? AND group_id IN ($ph)");
        $m->execute(array_merge([$visitorId], $groupIds));
        $memberSet = array_flip(array_column($m->fetchAll(), 'group_id'));
    }

    json_response(array_map(
        fn($r) => group_to_public($r, $visitorId !== '' ? isset($memberSet[$r['id']]) : null),
        $rows
    ));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = request_json();
    $name = mb_substr(trim((string)($body['name'] ?? '')), 0, 60);
    $description = mb_substr(trim((string)($body['description'] ?? '')), 0, 280);
    $topicKibbe = trim((string)($body['topicKibbe'] ?? '')) ?: null;
    $topicStyle = trim((string)($body['topicStyle'] ?? '')) ?: null;
    $creatorId = (string)($body['creatorId'] ?? '');
    $creatorName = mb_substr(trim((string)($body['creatorName'] ?? '')), 0, 60);

    if (!$name) error_response('Give the group a name.', 400);
    if (!$creatorId) error_response('Missing creatorId.', 400);
    require_owner($pdo, $creatorId, (string)($body['authToken'] ?? ''));

    $newId = uuidv4();
    $now = current_time_ms();
    $pdo->prepare(
        'INSERT INTO interest_groups (id, name, description, topic_kibbe, topic_style, creator_id, creator_name, member_count, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)'
    )->execute([$newId, $name, $description, $topicKibbe, $topicStyle, $creatorId, $creatorName, $now]);
    $pdo->prepare('INSERT INTO interest_group_members (group_id, account_id, account_name, joined_at) VALUES (?, ?, ?, ?)')
        ->execute([$newId, $creatorId, $creatorName, $now]);

    $rowStmt = $pdo->prepare('SELECT * FROM interest_groups WHERE id = ?');
    $rowStmt->execute([$newId]);
    json_response(group_to_public($rowStmt->fetch(), true), 201);
}

error_response('Method not allowed.', 405);
