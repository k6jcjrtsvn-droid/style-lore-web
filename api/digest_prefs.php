<?php
/**
 * POST /api/digest_prefs.php {visitorId, authToken, optOut: 0|1} — turns the
 * weekly email on or off for the signed-in account.
 */
require_once __DIR__ . '/../includes/helpers.php';
require_method('POST');
$body = request_json();
$visitorId = (string)($body['visitorId'] ?? '');
$authToken = (string)($body['authToken'] ?? '');
if ($visitorId === '' || $authToken === '') error_response('You need to be signed in.', 401);
$pdo = db();
ensure_digest_columns($pdo);
require_owner($pdo, $visitorId, $authToken);
// Either field may be sent alone: {optOut} for the weekly email,
// {morningOptOut} for the 8 AM outfit push.
if (array_key_exists('morningOptOut', $body)) {
    $m = !empty($body['morningOptOut']) ? 1 : 0;
    $pdo->prepare('UPDATE accounts SET morning_push_opt_out = ? WHERE id = ?')->execute([$m, $visitorId]);
    json_response(['ok' => true, 'morningPushOptOut' => $m]);
}
$optOut = !empty($body['optOut']) ? 1 : 0;
$pdo->prepare('UPDATE accounts SET digest_opt_out = ? WHERE id = ?')->execute([$optOut, $visitorId]);
json_response(['ok' => true, 'digestOptOut' => $optOut]);
