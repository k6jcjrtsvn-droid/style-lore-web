<?php
/**
 * POST /api/stripe_webhook.php — Stripe → us. Verifies the Stripe-Signature
 * header against STRIPE_WEBHOOK_SECRET, then keeps the `subscriptions`
 * table (the same one RevenueCat's webhook writes) in sync:
 *
 *   checkout.session.completed        → premium on, remember the customer
 *   customer.subscription.created/updated → premium follows status + period end
 *   customer.subscription.deleted     → premium off
 *   invoice.paid                      → extend expires_at to the new period end
 *
 * Events for the endpoint in the Stripe dashboard: those five. Anything
 * else is acknowledged and ignored. Always returns 200 once the signature
 * checks out so Stripe doesn't retry forever on a row we chose to skip.
 */
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/stripe.php';
require_method('POST');

$payload = file_get_contents('php://input') ?: '';
$sig = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
if (!defined('STRIPE_WEBHOOK_SECRET') || STRIPE_WEBHOOK_SECRET === '' || !stripe_verify_signature($payload, $sig, STRIPE_WEBHOOK_SECRET)) {
    error_response('Bad signature.', 400);
}
$event = json_decode($payload, true);
if (!is_array($event) || empty($event['type'])) error_response('Bad payload.', 400);

$pdo = db();
ensure_stripe_columns($pdo);
$type = $event['type'];
$obj = $event['data']['object'] ?? [];

function apply_subscription(PDO $pdo, array $sub): string {
    $accountId = (string)($sub['metadata']['account_id'] ?? '');
    $customer = is_array($sub['customer'] ?? null) ? ($sub['customer']['id'] ?? '') : (string)($sub['customer'] ?? '');
    if ($accountId === '' && $customer !== '') $accountId = stripe_account_for_customer($pdo, $customer) ?? '';
    if ($accountId === '') return 'skipped: no account id';
    $status = (string)($sub['status'] ?? '');
    $active = in_array($status, ['active', 'trialing', 'past_due'], true) && empty($sub['ended_at']);
    $periodEnd = (int)($sub['current_period_end'] ?? 0);
    if (!$periodEnd && !empty($sub['items']['data'][0]['current_period_end'])) $periodEnd = (int)$sub['items']['data'][0]['current_period_end'];
    $expiresMs = $periodEnd ? $periodEnd * 1000 : null;
    $priceId = (string)($sub['items']['data'][0]['price']['id'] ?? 'stripe');
    stripe_upsert_subscription($pdo, $accountId, $active ? 1 : 0, 'stripe:' . $priceId, $expiresMs, $customer, (string)($sub['id'] ?? ''));
    return ($active ? 'premium on' : 'premium off') . ' for ' . $accountId;
}

$result = 'ignored';
try {
    if ($type === 'checkout.session.completed') {
        $accountId = (string)($obj['client_reference_id'] ?? ($obj['metadata']['account_id'] ?? ''));
        $customer = (string)($obj['customer'] ?? '');
        $subId = (string)($obj['subscription'] ?? '');
        if ($accountId !== '') {
            // Fetch the subscription so expires_at is right from the first minute.
            $sub = $subId ? stripe_request('GET', '/v1/subscriptions/' . rawurlencode($subId)) : null;
            if (is_array($sub) && !empty($sub['id'])) {
                if (empty($sub['metadata']['account_id'])) $sub['metadata']['account_id'] = $accountId;
                $result = apply_subscription($pdo, $sub);
            } else {
                stripe_upsert_subscription($pdo, $accountId, 1, 'stripe', null, $customer, $subId);
                $result = 'premium on (no period end yet) for ' . $accountId;
            }
        } else $result = 'skipped: no client_reference_id';
    } elseif (in_array($type, ['customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted'], true)) {
        $result = apply_subscription($pdo, $obj);
    } elseif ($type === 'invoice.paid') {
        $subId = (string)($obj['subscription'] ?? ($obj['parent']['subscription_details']['subscription'] ?? ''));
        if ($subId) { $sub = stripe_request('GET', '/v1/subscriptions/' . rawurlencode($subId)); if (is_array($sub) && !empty($sub['id'])) $result = apply_subscription($pdo, $sub); }
    }
} catch (Throwable $e) {
    error_log('Stripe webhook ' . $type . ': ' . $e->getMessage());
    json_response(['ok' => false, 'error' => 'internal'], 500);
}
json_response(['ok' => true, 'type' => $type, 'result' => $result]);
