<?php
/**
 * POST /api/auth/reset — { token, password } — completes a password
 * reset, then logs them straight in (returns a fresh auth token) so
 * ResetPasswordScreen can hand off to the app the same way login does.
 */
require_once __DIR__ . '/../includes/helpers.php';
require_method('POST');

$body = request_json();
$token = (string)($body['token'] ?? '');
$password = (string)($body['password'] ?? '');

if (!$token) error_response('That reset link is invalid or has expired.', 400);
if (strlen($password) < 8) error_response('Password must be at least 8 characters.', 400);

try {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, email, reset_token_hash, reset_token_expires FROM accounts WHERE reset_token_hash = ?');
    $stmt->execute([hash_token($token)]);
    $account = $stmt->fetch();

    if (!$account || (int)$account['reset_token_expires'] < current_time_ms()) {
        error_response('That reset link is invalid or has expired — request a new one.', 400);
    }

    $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
    $authToken = new_auth_token();
    $pdo->prepare(
        'UPDATE accounts SET password_hash = ?, auth_token_hash = ?, reset_token_hash = NULL, reset_token_expires = NULL WHERE id = ?'
    )->execute([$passwordHash, hash_token($authToken), $account['id']]);

    $profileStmt = $pdo->prepare('SELECT name FROM profiles WHERE id = ?');
    $profileStmt->execute([$account['id']]);
    $profile = $profileStmt->fetch();

    json_response([
        'id' => $account['id'],
        'name' => $profile ? $profile['name'] : '',
        'email' => $account['email'],
        'token' => $authToken,
    ]);
} catch (Throwable $e) {
    error_log('Password reset failed: ' . $e->getMessage());
    error_response("Couldn't reset your password — try again.", 500);
}
