<?php
/**
 * POST /api/device-tokens {visitorId, authToken, token, platform} —
 * registers (or refreshes) this device's push token, so
 * send_push_notification() (see includes/helpers.php, called from
 * create_notification()) can reach it whenever this account gets a like,
 * comment, follow, message, or group-join. Upserted on the token itself —
 * FCM tokens rotate over the life of an install, and re-registering with a
 * new token should replace the old row, never duplicate it.
 *
 * DELETE /api/device-tokens {visitorId, authToken, token} — unregisters a
 * token (called on sign-out), so a shared/reset device stops receiving
 * this account's pushes once someone signs out of it.
 */
require_once __DIR__ . '/../includes/helpers.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST' && $method !== 'DELETE') {
    error_response('Method not allowed.', 405);
}

$body = request_json();
$visitorId = (string)($body['visitorId'] ?? '');
$token = trim((string)($body['token'] ?? ''));

if (!$visitorId || !$token) error_response('Missing account id or device token.', 400);

$pdo = db();
require_owner($pdo, $visitorId, (string)($body['authToken'] ?? ''));

if ($method === 'DELETE') {
    $pdo->prepare('DELETE FROM device_tokens WHERE token = ? AND account_id = ?')->execute([$token, $visitorId]);
    json_response(['ok' => true]);
}

$platform = in_array($body['platform'] ?? '', ['android', 'ios'], true) ? $body['platform'] : 'android';
// Phone's UTC offset in minutes (JS: -new Date().getTimezoneOffset()), so
// the 8 AM "Today's outfit" push lands at 8 AM *there*.
$tzOffset = (int)($body['tzOffset'] ?? 0);
if ($tzOffset < -840 || $tzOffset > 840) $tzOffset = 0;
ensure_device_token_columns($pdo);

$pdo->prepare(
    'INSERT INTO device_tokens (token, account_id, platform, updated_at, tz_offset) VALUES (?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE account_id = VALUES(account_id), platform = VALUES(platform), updated_at = VALUES(updated_at), tz_offset = VALUES(tz_offset)'
)->execute([$token, $visitorId, $platform, current_time_ms(), $tzOffset]);

// Keep at most 10 tokens per account (newest win) so a reinstalling
// device can't pile up stale tokens forever.
$stale = $pdo->prepare('SELECT token FROM device_tokens WHERE account_id = ? ORDER BY updated_at DESC LIMIT 10, 1000');
$stale->execute([$visitorId]);
foreach ($stale->fetchAll() as $row) {
    $pdo->prepare('DELETE FROM device_tokens WHERE token = ?')->execute([$row['token']]);
}

json_response(['ok' => true]);
