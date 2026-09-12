<?php
/**
 * POST /api/stories/{id}/view {visitorId, authToken} — marks a story as
 * viewed by this visitor (idempotent — viewing it again just no-ops).
 */
require_once __DIR__ . '/../includes/helpers.php';
require_method('POST');

$storyId = (string)($_GET['id'] ?? '');
$body = request_json();
$visitorId = (string)($body['visitorId'] ?? '');

$pdo = db();
require_owner($pdo, $visitorId, (string)($body['authToken'] ?? ''));

$check = $pdo->prepare('SELECT id FROM stories WHERE id = ?');
$check->execute([$storyId]);
if (!$check->fetch()) error_response('Story not found.', 404);

$stmt = $pdo->prepare(
    'INSERT INTO story_views (story_id, visitor_id, viewed_at) VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE viewed_at = VALUES(viewed_at)'
);
$stmt->execute([$storyId, $visitorId, current_time_ms()]);

json_response(['ok' => true]);
