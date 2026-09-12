<?php
/**
 * POST /api/closet/visibility {visitorId, authToken, visibility}
 * Sets whether this account's closet is shown on their public profile.
 * visibility must be "public" or "hidden" (defaults to "hidden" for
 * anything else, so a bad value never accidentally makes something public).
 */
require_once __DIR__ . '/../includes/helpers.php';
require_method('POST');

$pdo = db();
$body = request_json();
$visitorId = (string)($body['visitorId'] ?? '');
require_owner($pdo, $visitorId, (string)($body['authToken'] ?? ''));

$visibility = (string)($body['visibility'] ?? '');
$visibility = $visibility === 'public' ? 'public' : 'hidden';

$existing = $pdo->prepare('SELECT id FROM profiles WHERE id = ?');
$existing->execute([$visitorId]);
if ($existing->fetch()) {
    $pdo->prepare('UPDATE profiles SET closet_visibility = ? WHERE id = ?')->execute([$visibility, $visitorId]);
} else {
    // No profile row yet (never opened "Edit profile") — create a minimal
    // one, same fallback pattern api/profile.php's POST uses.
    $pdo->prepare('INSERT INTO profiles (id, closet_visibility, updated_at) VALUES (?, ?, ?)')
        ->execute([$visitorId, $visibility, current_time_ms()]);
}

json_response(['visibility' => $visibility]);
