<?php
/**
 * POST /api/stripe_checkout.php {visitorId, authToken, plan: "monthly"|"yearly"}
 * → {url}. Creates a Stripe Checkout Session (subscription mode) for the
 * signed-in account and returns Stripe's hosted checkout URL. Card details
 * never touch this server. Web only — the native apps bill through the
 * stores (and Apple's rules forbid pointing iOS users at outside checkout).
 *
 * Needs in config.php: STRIPE_SECRET_KEY, STRIPE_PRICE_MONTHLY,
 * STRIPE_PRICE_YEARLY. The account id rides along as client_reference_id
 * and as subscription metadata so the webhook can map events back.
 */
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/stripe.php';
require_method('POST');

$body = request_json();
$visitorId = (string)($body['visitorId'] ?? '');
$authToken = (string)($body['authToken'] ?? '');
if ($visitorId === '' || $authToken === '') error_response('Sign in to upgrade.', 401);
$pdo = db();
require_owner($pdo, $visitorId, $authToken);
rate_limit($pdo, 'stripe_checkout:' . $visitorId, 10, 3600, 'Too many checkout attempts — try again in an hour.');

if (!stripe_configured()) error_response("Card payments aren't set up yet — check back soon.", 503);

$plan = ($body['plan'] ?? 'monthly') === 'yearly' ? 'yearly' : 'monthly';
$price = $plan === 'yearly' ? STRIPE_PRICE_YEARLY : STRIPE_PRICE_MONTHLY;

$acct = $pdo->prepare('SELECT email FROM accounts WHERE id = ?');
$acct->execute([$visitorId]);
$email = (string)($acct->fetchColumn() ?: '');

// Reuse the Stripe customer if this account already has one (so the portal
// and the subscription line up), otherwise let Checkout create it.
$existingCustomer = stripe_customer_for_account($pdo, $visitorId);

$params = [
    'mode' => 'subscription',
    'line_items[0][price]' => $price,
    'line_items[0][quantity]' => 1,
    'client_reference_id' => $visitorId,
    'success_url' => SITE_BASE_URL . '/?upgraded=1',
    'cancel_url' => SITE_BASE_URL . '/?upgraded=0',
    'allow_promotion_codes' => 'true',
    'subscription_data[metadata][account_id]' => $visitorId,
    'metadata[account_id]' => $visitorId,
];
if ($existingCustomer) $params['customer'] = $existingCustomer;
elseif ($email !== '') $params['customer_email'] = $email;

$session = stripe_request('POST', '/v1/checkout/sessions', $params);
if (empty($session['url'])) {
    error_log('Stripe checkout: ' . json_encode($session));
    error_response("Couldn't start checkout right now — try again in a moment.", 502);
}
json_response(['url' => $session['url']]);
