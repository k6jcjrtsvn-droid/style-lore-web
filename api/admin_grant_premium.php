<?php
/**
 * api/admin_grant_premium — give (or withdraw) Premium on an account
 * without anyone paying.
 *
 *   POST /api/admin_grant_premium.php
 *   header: X-Admin-Secret
 *   body (JSON or form): email=... | accountId=...
 *                        months=12        optional; omit for no end date
 *                        revoke=1         optional; takes Premium back
 *                        note=...         optional; goes in the audit log
 *
 * WHY THIS EXISTS. Premium lives in the `subscriptions` table and was only
 * ever written by two things: RevenueCat's webhook and Stripe's. There was
 * no way to hand someone Premium deliberately — a partner, a tester, an
 * apology — short of editing the database, and phpMyAdmin is broken on this
 * host (GoDaddy's own fault: cPanel's temporary MySQL user is denied).
 *
 * The app already understands a gifted subscription: a `product_id` starting
 * `gift:` makes subscription_gift_notice() return kind "gift", which shows
 * the "Premium · on us" card on Home and hides "Manage subscription" (which
 * would otherwise try to open a Stripe portal for a customer that does not
 * exist). An end date is optional — the card simply omits the "until ..."
 * clause when there isn't one. So this endpoint writes a state the client
 * was already built to render; it invents nothing.
 *
 * WHAT IT CANNOT DO, deliberately. It writes one row in one table. It cannot
 * make anyone an admin, cannot read or change a password, cannot delete an
 * account, cannot touch Stripe or RevenueCat, and cannot create an account
 * that does not already exist. Granting is reversible from here too
 * (`revoke=1`) — an entitlement you can hand out but not take back is not a
 * decision, it is a trapdoor.
 */
require_once __DIR__ . '/../includes/helpers.php';
require_method('POST');

$sent = (string)($_SERVER['HTTP_X_ADMIN_SECRET'] ?? '');
$secret = (defined('ADMIN_SECRET') && ADMIN_SECRET !== '') ? ADMIN_SECRET
        : ((defined('ADMIN_KEY') && ADMIN_KEY !== '') ? ADMIN_KEY : '');
if ($secret === '' || $sent === '' || !hash_equals($secret, $sent)) error_response('Not authorized.', 401);

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) $body = $_POST;
$email     = trim((string)($body['email'] ?? ''));
$accountId = trim((string)($body['accountId'] ?? ''));
$months    = isset($body['months']) && $body['months'] !== '' ? (int)$body['months'] : null;
$revoke    = !empty($body['revoke']);
$note      = mb_substr(trim((string)($body['note'] ?? '')), 0, 200);

if ($email === '' && $accountId === '') error_response('Give an email or an accountId.', 400);
if ($months !== null && ($months < 1 || $months > 120)) error_response('months must be between 1 and 120.', 400);

$pdo = db();

if ($accountId === '') {
    $st = $pdo->prepare('SELECT id FROM accounts WHERE LOWER(email) = LOWER(?)');
    $st->execute([$email]);
    $accountId = (string)($st->fetchColumn() ?: '');
    if ($accountId === '') error_response('No account with that email.', 404);
} else {
    $st = $pdo->prepare('SELECT email FROM accounts WHERE id = ?');
    $st->execute([$accountId]);
    $email = (string)($st->fetchColumn() ?: '');
    if ($email === '') error_response('No account with that id.', 404);
}

$before = [
    'isPremium' => has_premium($pdo, $accountId),
    'gift'      => subscription_gift_notice($pdo, $accountId),
];

$now = current_time_ms();
if ($revoke) {
    // Leave the row in place with is_premium 0 rather than deleting it, so
    // the history of what this account once had is not silently erased.
    $st = $pdo->prepare('UPDATE subscriptions SET is_premium = 0, expires_at = ?, updated_at = ? WHERE account_id = ?');
    $st->execute([$now, $now, $accountId]);
    $productId = null;
} else {
    $productId = 'gift:admin';
    $expires = $months === null ? null : $now + $months * 30 * 86400 * 1000;
    $st = $pdo->prepare(
        'INSERT INTO subscriptions (account_id, is_premium, product_id, expires_at, updated_at)
         VALUES (?, 1, ?, ?, ?)
         ON DUPLICATE KEY UPDATE is_premium = 1, product_id = VALUES(product_id),
           expires_at = VALUES(expires_at), updated_at = VALUES(updated_at)'
    );
    $st->execute([$accountId, $productId, $expires, $now]);
}

// Audit trail. The admin secret is never logged, only what was done to whom.
error_log(sprintf('admin_grant_premium: %s %s (%s)%s%s',
    $revoke ? 'REVOKED' : 'GRANTED',
    $email, $accountId,
    $revoke ? '' : ($months === null ? ' indefinite' : ' for ' . $months . ' months'),
    $note !== '' ? ' note=' . $note : ''));

json_response([
    'ok'        => true,
    'action'    => $revoke ? 'revoked' : 'granted',
    'email'     => $email,
    'accountId' => $accountId,
    'productId' => $productId,
    'months'    => $revoke ? null : $months,
    'before'    => $before,
    'after'     => [
        'isPremium' => has_premium($pdo, $accountId),
        'gift'      => subscription_gift_notice($pdo, $accountId),
        'billing'   => subscription_billing_source($pdo, $accountId),
    ],
]);
