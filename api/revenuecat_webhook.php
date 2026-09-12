<?php
/**
 * POST /api/revenuecat/webhook — receives subscription lifecycle events
 * from RevenueCat (https://www.revenuecat.com/docs/integrations/webhooks)
 * and keeps the `subscriptions` table in sync, so has_premium() (see
 * includes/helpers.php) can check entitlement locally on every request
 * without calling RevenueCat's API each time.
 *
 * Auth: RevenueCat resends, verbatim, whatever string is configured as
 * this webhook's "Authorization header value" in the RevenueCat dashboard
 * (Project settings > Integrations > Webhooks) as the request's
 * Authorization header. Set the exact same value here as
 * REVENUECAT_WEBHOOK_SECRET in config.php — a request with a missing or
 * mismatched value is rejected before touching the database.
 *
 * The mobile app configures the RevenueCat SDK with this account's own id
 * as the "app user id" (Purchases.configure({ apiKey, appUserID:
 * account.id }) in index.html), so event.app_user_id here always equals
 * accounts.id directly — no separate id-mapping table needed.
 */
require_once __DIR__ . '/../includes/helpers.php';
require_method('POST');

$configuredSecret = defined('REVENUECAT_WEBHOOK_SECRET') ? trim((string)REVENUECAT_WEBHOOK_SECRET) : '';
$sentAuth = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
if ($configuredSecret === '' || !hash_equals($configuredSecret, $sentAuth)) {
    error_response('Not authorized.', 401);
}

$body = request_json();
$event = $body['event'] ?? null;
if (!is_array($event)) error_response('Malformed webhook payload.', 400);

$accountId = (string)($event['app_user_id'] ?? '');
$type = (string)($event['type'] ?? '');

// A purchase made before the app ever attached a real signed-in account id
// would carry RevenueCat's own anonymous id instead — shouldn't happen
// (the paywall only ever appears once signed in), but never let an
// anonymous id create or touch a row keyed by our own account ids.
if ($accountId === '' || strpos($accountId, '$RCAnonymousID') === 0) {
    json_response(['ok' => true, 'skipped' => 'anonymous or missing app_user_id']);
}

$pdo = db();

// EXPIRATION means access has genuinely ended (grace period/billing retry
// exhausted, or the subscription simply lapsed) — every other event type
// RevenueCat sends (INITIAL_PURCHASE, RENEWAL, PRODUCT_CHANGE,
// UNCANCELLATION, ...) means the account has, or still has mid-
// cancellation, active access through expiration_at_ms.
$isPremium = ($type === 'EXPIRATION') ? 0 : 1;
$expiresAtMs = isset($event['expiration_at_ms']) ? (int)$event['expiration_at_ms'] : null;
$productId = isset($event['product_id']) ? mb_substr((string)$event['product_id'], 0, 120) : null;

$stmt = $pdo->prepare(
    'INSERT INTO subscriptions (account_id, is_premium, product_id, expires_at, updated_at)
     VALUES (?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE is_premium = VALUES(is_premium), product_id = VALUES(product_id),
       expires_at = VALUES(expires_at), updated_at = VALUES(updated_at)'
);
$stmt->execute([$accountId, $isPremium, $productId, $expiresAtMs, current_time_ms()]);

json_response(['ok' => true]);
