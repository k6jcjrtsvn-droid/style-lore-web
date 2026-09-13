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
require_owner($pdo, $visitorId, $authToken);
$optOut = !empty($body['optOut']) ? 1 : 0;
$pdo->prepare('UPDATE accounts SET digest_opt_out = ? WHERE id = ?')->execute([$optOut, $visitorId]);
json_response(['ok' => true, 'digestOptOut' => $optOut]);
