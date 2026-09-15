<?php
require_once __DIR__ . '/../includes/helpers.php';
require_method('POST');

$body = request_json();
$email = normalize_email($body['email'] ?? '');
$password = (string)($body['password'] ?? '');

try {
    $pdo = db();
    rate_limit($pdo, 'login:ip:' . client_ip(), 20, 300);
    rate_limit($pdo, 'login:email:' . $email, 10, 300);
    $stmt = $pdo->prepare('SELECT id, email, password_hash, email_verified FROM accounts WHERE email = ?');
    $stmt->execute([$email]);
    $account = $stmt->fetch();

    // Deliberately identical error whether the email doesn't exist or the
    // password is wrong, so this endpoint can't be used to discover which
    // emails are registered.
    if (!$account || !password_verify($password, $account['password_hash'])) {
        error_response('Incorrect email or password.', 401);
    }

    // A fresh token for THIS device. Other devices stay signed in — see
    // issue_auth_token() in helpers.php (multi-device sessions).
    $authToken = issue_auth_token($pdo, $account['id'], (string)($body['device'] ?? ''));

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
