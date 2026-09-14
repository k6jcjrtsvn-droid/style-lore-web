<?php
/**
 * GET /api/subscription/status?accountId=X — returns this account's
 * current Style-LORE Premium entitlement. Kept in sync by RevenueCat's
 * webhook (api/revenuecat_webhook.php) via the `subscriptions` table —
 * see has_premium() in includes/helpers.php for how this is computed.
 *
 * Public read, like a profile — premium status isn't sensitive, and the
 * app needs to check it from multiple screens (paywall, closet, checker)
 * without needing to also carry an auth token around for a plain status
 * read. Right after a purchase completes, the mobile client trusts
 * RevenueCat's own local CustomerInfo immediately (no round trip needed),
 * then this endpoint is the source of truth on every later screen load.
 */
require_once __DIR__ . '/../includes/helpers.php';
require_method('GET');

$accountId = (string)($_GET['accountId'] ?? '');
if ($accountId === '') error_response('Missing accountId.', 400);

$pdo = db();
json_response([
    'isPremium' => has_premium($pdo, $accountId),
    'closetFreeLimit' => CLOSET_FREE_LIMIT,
    'freeAiReadsLeft' => free_ai_reads_left($pdo, $accountId),
    'digestOptOut' => digest_opt_out($pdo, $accountId),
    // Where the subscription came from, so the web can show "Manage" for
    // Stripe subscribers and point store subscribers at their phone.
    'billing' => subscription_billing_source($pdo, $accountId),
    // Free thank-you period (sandbox-era upgrades): the app shows a notice
    // with the end date and a "keep Premium" button instead of "Manage".
    'giftNotice' => subscription_gift_notice($pdo, $accountId),
    // Referral program: this account's invite code, how many friends joined,
    // and when its banked free months run out (0 = none).
    'referralCode' => referral_code_for($pdo, $accountId),
    'referralCount' => (function() use ($pdo, $accountId) { try { $st = $pdo->prepare('SELECT referral_count FROM accounts WHERE id = ?'); $st->execute([$accountId]); return (int)$st->fetchColumn(); } catch (Throwable $e) { return 0; } })(),
    'referralUntil' => referral_premium_until($pdo, $accountId),
    'webCheckout' => defined('STRIPE_SECRET_KEY') && STRIPE_SECRET_KEY !== '' && defined('STRIPE_PRICE_MONTHLY') && STRIPE_PRICE_MONTHLY !== '',
]);
