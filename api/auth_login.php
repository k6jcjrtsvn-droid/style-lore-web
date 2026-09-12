<?php
require_once __DIR__ . '/../includes/helpers.php';
require_method('POST');

$body = request_json();
$email = normalize_email($body['email'] ?? '');
$password = (string)($body['password'] ?? '');

try {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, email, password_hash, email_verified FROM accounts WHERE email = ?');
    $stmt->execute([$email]);
    $account = $stmt->fetch();

    // Deliberately identical error whether the email doesn't exist or the
    // password is wrong, so this endpoint can't be used to discover which
    // emails are registered.
    if (!$account || !password_verify($password, $account['password_hash'])) {
        error_response('Incorrect email or password.', 401);
    }

    // A fresh token on every login (simple rotation — an old device's
    // token stops working once you log in again elsewhere; there's no
    // multi-device session list, just the one current token per account).
    $authToken = new_auth_token();
    $pdo->prepare('UPDATE accounts SET auth_token_hash = ? WHERE id = ?')->execute([hash_token($authToken), $account['id']]);

    $profileStmt = $pdo->prepare('SELECT name FROM profiles WHERE id = ?');
    $profileStmt->execute([$account['id']]);
    $profile = $profileStmt->fetch();

    json_response([
        'id' => $account['id'],
        'name' => $profile ? $profile['name'] : '',
        'email' => $account['email'],
        'token' => $authToken,
        'emailVerified' => (bool)$account['email_verified'],
    ]);
} catch (Throwable $e) {
    error_log('Login failed: ' . $e->getMessage());
    error_response("Couldn't log you in — try again.", 500);
}
