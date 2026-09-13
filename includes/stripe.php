<?php
/**
 * Minimal Stripe client for shared hosting: no SDK, just curl against the
 * REST API with the secret key from config.php, plus webhook signature
 * verification and the small bit of local state (which Stripe customer
 * belongs to which account) the portal needs.
 */

function stripe_configured(): bool {
    return defined('STRIPE_SECRET_KEY') && STRIPE_SECRET_KEY !== ''
        && defined('STRIPE_PRICE_MONTHLY') && STRIPE_PRICE_MONTHLY !== ''
        && defined('STRIPE_PRICE_YEARLY') && STRIPE_PRICE_YEARLY !== '';
}

/** Form-encoded request to api.stripe.com; returns the decoded JSON (or ['error'=>...]). */
function stripe_request(string $method, string $path, array $params = []): array {
    $ch = curl_init('https://api.stripe.com' . $path);
    $headers = ['Authorization: Bearer ' . STRIPE_SECRET_KEY, 'Stripe-Version: 2025-08-27.basil'];
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CUSTOMREQUEST => $method];
    if ($method === 'POST') {
        $opts[CURLOPT_POSTFIELDS] = http_build_query($params);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) { error_log('Stripe curl: ' . $err); return ['error' => ['message' => $err]]; }
    $json = json_decode($res, true);
    return is_array($json) ? $json : ['error' => ['message' => 'bad json']];
}

/** Stripe-Signature: t=<ts>,v1=<hmac>[,v1=...]; HMAC-SHA256 of "<ts>.<payload>". */
function stripe_verify_signature(string $payload, string $header, string $secret, int $tolerance = 300): bool {
    $ts = null; $sigs = [];
    foreach (explode(',', $header) as $part) {
        [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
        if ($k === 't') $ts = (int)$v;
        elseif ($k === 'v1') $sigs[] = $v;
    }
    if (!$ts || !$sigs || abs(time() - $ts) > $tolerance) return false;
    $expected = hash_hmac('sha256', $ts . '.' . $payload, $secret);
    foreach ($sigs as $s) if (hash_equals($expected, $s)) return true;
    return false;
}

function ensure_stripe_columns(PDO $pdo): void {
    static $done = false; if ($done) return; $done = true;
    try { $pdo->exec('ALTER TABLE subscriptions ADD COLUMN IF NOT EXISTS stripe_customer_id VARCHAR(64) DEFAULT NULL, ADD COLUMN IF NOT EXISTS stripe_subscription_id VARCHAR(64) DEFAULT NULL, ADD INDEX IF NOT EXISTS idx_stripe_customer (stripe_customer_id)'); }
    catch (Throwable $e) { error_log('ensure_stripe_columns: ' . $e->getMessage()); }
}

function stripe_customer_for_account(PDO $pdo, string $accountId): ?string {
    ensure_stripe_columns($pdo);
    try { $st = $pdo->prepare('SELECT stripe_customer_id FROM subscriptions WHERE account_id = ?'); $st->execute([$accountId]); $v = $st->fetchColumn(); return $v ? (string)$v : null; }
    catch (Throwable $e) { return null; }
}

function stripe_account_for_customer(PDO $pdo, string $customer): ?string {
    ensure_stripe_columns($pdo);
    try { $st = $pdo->prepare('SELECT account_id FROM subscriptions WHERE stripe_customer_id = ?'); $st->execute([$customer]); $v = $st->fetchColumn(); return $v ? (string)$v : null; }
    catch (Throwable $e) { return null; }
}

function stripe_upsert_subscription(PDO $pdo, string $accountId, int $isPremium, string $productId, ?int $expiresMs, string $customer, string $subId): void {
    ensure_stripe_columns($pdo);
    $pdo->prepare(
        'INSERT INTO subscriptions (account_id, is_premium, product_id, expires_at, updated_at, stripe_customer_id, stripe_subscription_id)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE is_premium = VALUES(is_premium), product_id = VALUES(product_id), expires_at = VALUES(expires_at),
           updated_at = VALUES(updated_at), stripe_customer_id = COALESCE(VALUES(stripe_customer_id), stripe_customer_id),
           stripe_subscription_id = COALESCE(VALUES(stripe_subscription_id), stripe_subscription_id)'
    )->execute([$accountId, $isPremium, $productId, $expiresMs, current_time_ms(), $customer !== '' ? $customer : null, $subId !== '' ? $subId : null]);
}
