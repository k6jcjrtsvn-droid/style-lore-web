<?php
require_once __DIR__ . '/../includes/helpers.php';

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $followerId = (string)($_GET['followerId'] ?? '');
    $followingId = (string)($_GET['followingId'] ?? '');

    if ($followerId !== '') {
        // Who does this person follow?
        $stmt = $pdo->prepare('SELECT following_id FROM follows WHERE follower_id = ?');
        $stmt->execute([$followerId]);
        json_response(array_column($stmt->fetchAll(), 'following_id'));
    }
    if ($followingId !== '') {
        // Who follows this person?
        $stmt = $pdo->prepare('SELECT follower_id FROM follows WHERE following_id = ?');
        $stmt->execute([$followingId]);
        json_response(array_column($stmt->fetchAll(), 'follower_id'));
    }
    json_response([]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = request_json();
    $followerId = (string)($body['followerId'] ?? '');
    $followingId = (string)($body['followingId'] ?? '');

    if (!$followerId || !$followingId || $followerId === $followingId) {
        error_response('Invalid follow request.', 400);
    }

    // Ownership check — only the follower themself can create the edge.
    require_owner($pdo, $followerId, (string)($body['authToken'] ?? ''));

    // Following someone who blocked you (or whom you blocked) would put
    // their posts back in your feed through the follow graph.
    ensure_block_schema($pdo);
    if (is_blocked_pair($pdo, $followerId, $followingId)) {
        error_response('You cannot follow this account.', 403);
    }

    // Names come from the profiles table, never from the client.
    $followerName = profile_identity($pdo, $followerId)['name'];
    $followingName = profile_identity($pdo, $followingId)['name'];

    $id = $followerId . '__' . $followingId;
    $check = $pdo->prepare('SELECT id FROM follows WHERE id = ?');
    $check->execute([$id]);
    if ($check->fetch()) json_response(['ok' => true]);

    $ins = $pdo->prepare(
        'INSERT INTO follows (id, follower_id, follower_name, following_id, following_name, created_at) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $ins->execute([$id, $followerId, $followerName, $followingId, $followingName, current_time_ms()]);

    $avatarStmt = $pdo->prepare('SELECT avatar_url FROM profiles WHERE id = ?');
    $avatarStmt->execute([$followerId]);
    $followerAvatar = ($row = $avatarStmt->fetch()) ? $row['avatar_url'] : null;
    create_notification($pdo, $followingId, $followerId, $followerName, $followerAvatar, 'follow', $followerName . ' started following you.', ['actorId' => $followerId], 86400);

    json_response(['ok' => true], 201);
}

error_response('Method not allowed.', 405);
