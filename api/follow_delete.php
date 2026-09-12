<?php
require_once __DIR__ . '/../includes/helpers.php';
require_method('DELETE');

$followerId = (string)($_GET['followerId'] ?? '');
$followingId = (string)($_GET['followingId'] ?? '');
$body = request_json();

$pdo = db();

// Ownership check — only the follower themself can remove the edge.
require_owner($pdo, $followerId, (string)($body['authToken'] ?? ''));

$stmt = $pdo->prepare('DELETE FROM follows WHERE follower_id = ? AND following_id = ?');
$stmt->execute([$followerId, $followingId]);

json_response(['ok' => true]);
