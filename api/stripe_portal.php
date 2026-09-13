<?php
/**
 * POST /api/stripe_portal.php {visitorId, authToken} → {url}. Opens
 * Stripe's customer portal (cancel, change plan, update card) for an
 * account that subscribed through the website.
 */
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/stripe.php';
require_method('POST');

$body = request_json();
$visitorId = (string)($body['visitorId'] ?? '');
$authToken = (string)($body['authToken'] ?? '');
if ($visitorId === '' || $authToken === '') error_response('Sign in first.', 401);
$pdo = db();
require_owner($pdo, $visitorId, $authToken);
if (!stripe_configured()) error_response("Card payments aren't set up yet.", 503);

$customer = stripe_customer_for_account($pdo, $visitorId);
if (!$customer) error_response('No website subscription found on this account. Subscriptions bought in the app are managed from your phone\'s subscription settings.', 404);

$portal = stripe_request('POST', '/v1/billing_portal/sessions', [
    'customer' => $customer,
    'return_url' => SITE_BASE_URL . '/',
]);
if (empty($portal['url'])) {
    error_log('Stripe portal: ' . json_encode($portal));
    error_response("Couldn't open the billing portal right now.", 502);
}
json_response(['url' => $portal['url']]);
