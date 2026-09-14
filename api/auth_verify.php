<?php
/** POST /api/auth/verify — { token } — marks an account's email verified. */
require_once __DIR__ . '/../includes/helpers.php';
require_method('POST');

$body = request_json();
$token = (string)($body['token'] ?? '');

if (!$token) error_response('That verification link is invalid or has expired.', 400);

try {
    $pdo = db();
    rate_limit($pdo, 'verify:ip:' . client_ip(), 20, 3600);
    $stmt = $pdo->prepare('SELECT id, verify_token_hash, verify_token_expires FROM accounts WHERE verify_token_hash = ?');
    $stmt->execute([hash_token($token)]);
    $account = $stmt->fetch();

    if (!$account || (int)$account['verify_token_expires'] < current_time_ms()) {
        error_response('That verification link is invalid or has expired.', 400);
    }

    $pdo->prepare('UPDATE accounts SET email_verified = 1, verify_token_hash = NULL, verify_token_expires = NULL WHERE id = ?')
        ->execute([$account['id']]);
    referral_reward_referrer($pdo, (string)$account['id']);

    json_response(['ok' => true]);
} catch (Throwable $e) {
    error_log('Email verification failed: ' . $e->getMessage());
    error_response("Couldn't verify that right now — try again.", 500);
}
