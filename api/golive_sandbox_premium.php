<?php
/**
 * One-time go-live step: convert every Premium subscription that was
 * created against the Stripe *sandbox* (nobody's card was ever charged)
 * into a free thank-you period, and tell each of those people by email so
 * they don't think they're being asked to pay twice.
 *
 * What it does for each sandbox row in `subscriptions`:
 *   - keeps Premium on, with expires_at = now + DAYS (default 30)
 *   - marks product_id 'stripe:sandbox-gift' (the app reads this to show
 *     the in-app notice and to hide "Manage subscription", which would
 *     otherwise try to open a test-mode customer with the live key)
 *   - clears the test-mode Stripe customer/subscription ids
 *   - emails the account: "your card was never charged; Premium is on us
 *     until <date>; subscribe before then to keep it — first and only charge"
 *
 * Run once, right after the live Stripe keys are in config.php:
 *   CLI:  /usr/local/bin/php api/golive_sandbox_premium.php [days] [--dry]
 *   HTTP: /api/golive_sandbox_premium.php?key=CRON_KEY&days=30[&dry=1]
 * Safe to re-run: rows already converted are skipped.
 */
require_once __DIR__ . '/../includes/helpers.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    $key = (string)($_GET['key'] ?? '');
    if (!defined('CRON_KEY') || CRON_KEY === '' || !hash_equals(CRON_KEY, $key)) error_response('Forbidden.', 403);
} else {
    foreach ($argv as $i => $a) { if ($i === 0) continue; if ($a === '--dry') $_GET['dry'] = '1'; elseif (ctype_digit($a)) $_GET['days'] = $a; }
}
$days = max(1, min(400, (int)($_GET['days'] ?? 30)));
$dry = !empty($_GET['dry']);

// The two sandbox prices (Style-LORE sandbox, acct_1UFLKdRvCFX2CAmN).
$sandboxProducts = ['stripe:price_1UFLZDRvCFX2CAmNafA3ZlrZ', 'stripe:price_1UFLZIRvCFX2CAmN1ITynS4n'];
const SANDBOX_GIFT_PRODUCT = 'stripe:sandbox-gift';

$pdo = db();
$in = implode(',', array_fill(0, count($sandboxProducts), '?'));
$rows = $pdo->prepare("SELECT s.account_id, s.product_id, s.expires_at, a.email, p.name
                       FROM subscriptions s
                       JOIN accounts a ON a.id = s.account_id
                       LEFT JOIN profiles p ON p.id = s.account_id
                       WHERE s.product_id IN ($in)");
$rows->execute($sandboxProducts);
$rows = $rows->fetchAll();

$now = current_time_ms();
$until = $now + $days * 86400 * 1000;
$untilText = date('F j, Y', (int)($until / 1000));

$converted = 0; $emailed = 0; $failed = 0;
foreach ($rows as $r) {
    $first = trim(explode(' ', (string)($r['name'] ?? ''))[0] ?? '');
    $plan = $r['product_id'] === $sandboxProducts[1] ? 'yearly' : 'monthly';

    $heading = ($first ? $first . ', ' : '') . 'your Premium is on us — your card was never charged';
    $body = '<p style="margin:0 0 14px;">Thank you for being one of the first people to try Style-LORE Premium. When you upgraded, our payment system was still in test mode, so <b>no charge was ever made to your card</b> — check your statement and you won\'t find one.</p>'
          . '<p style="margin:0 0 14px;">Payments are now live. To say thanks for testing it first, <b>Premium stays on for you, free, until ' . htmlspecialchars($untilText) . '</b>. Nothing to do.</p>'
          . '<p style="margin:0 0 14px;">If you\'d like to keep Premium after that, open Style-LORE and tap <b>Upgrade to Premium</b> any time before ' . htmlspecialchars($untilText) . '. That will be your first — and only — charge (' . ($plan === 'yearly' ? '$39.99 a year' : '$4.99 a month') . ', cancel any time). You are not being asked to pay twice.</p>'
          . '<p style="margin:0;">Questions? Just reply to this email.</p>';
    $footer = 'You\'re getting this because you upgraded to Premium during our test period. <a href="' . SITE_BASE_URL . '/terms.html" style="color:#6C4C56;">Terms</a>';
    $html = style_lore_email_html($heading, $body, 'Open Style-LORE', SITE_BASE_URL . '/', $footer);
    $text = $heading . "\n\nThank you for being one of the first to try Style-LORE Premium. When you upgraded, our payment system was still in test mode, so no charge was ever made to your card.\n\nPayments are now live. As a thank-you, Premium stays on for you, free, until " . $untilText . ". Nothing to do.\n\nTo keep Premium after that, open Style-LORE and tap Upgrade to Premium before " . $untilText . " — that will be your first and only charge. You are not being asked to pay twice.\n\nOpen Style-LORE: " . SITE_BASE_URL . "/\n";

    if ($dry) { $converted++; $emailed++; continue; }

    $pdo->prepare('UPDATE subscriptions SET is_premium = 1, product_id = ?, expires_at = ?, updated_at = ?, stripe_customer_id = NULL, stripe_subscription_id = NULL WHERE account_id = ?')
        ->execute([SANDBOX_GIFT_PRODUCT, $until, $now, $r['account_id']]);
    $converted++;

    if (!empty($r['email']) && send_mail_tracked($r['email'], 'Your Style-LORE Premium is on us — your card was never charged', $html, $text)) $emailed++;
    else $failed++;
}

json_response(['ok' => true, 'dry' => $dry, 'days' => $days, 'until' => $untilText, 'found' => count($rows), 'converted' => $converted, 'emailed' => $emailed, 'emailFailed' => $failed]);
