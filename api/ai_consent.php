<?php
/**
 * GET  /api/ai/consent?visitorId=…&authToken=…  -> { granted, at }
 * POST /api/ai/consent  { visitorId, authToken, granted: true|false }
 *
 * The record of explicit permission to send a photo to a third-party AI
 * (Apple 5.1.2(i); Google Play User Data policy). Withdrawing is the same
 * endpoint with granted:false, and takes effect on the very next request
 * because the three photo endpoints read this column every time rather than
 * trusting anything the client carries.
 *
 * Deliberately NOT bundled into the profile endpoint: a reviewer looking for
 * where consent is captured and withdrawn should find one obvious place.
 */
require_once __DIR__ . '/../includes/helpers.php';

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $visitorId = (string)($_GET['visitorId'] ?? '');
    require_owner($pdo, $visitorId, (string)($_GET['authToken'] ?? ''));
    $at = ai_consent_at($pdo, $visitorId);
    json_response(['granted' => $at !== null, 'at' => $at]);
}

require_method('POST');
$body = request_json();
$visitorId = (string)($body['visitorId'] ?? '');
require_owner($pdo, $visitorId, (string)($body['authToken'] ?? ''));
ensure_ai_consent_column($pdo);

$granted = !empty($body['granted']);
$now = current_time_ms();
try {
    $pdo->prepare('UPDATE accounts SET ai_consent_at = ? WHERE id = ?')
        ->execute([$granted ? $now : null, $visitorId]);
} catch (Throwable $e) {
    error_log('ai_consent update: ' . $e->getMessage());
    error_response("Couldn't save that — try again.", 500);
}

json_response(['granted' => $granted, 'at' => $granted ? $now : null]);
