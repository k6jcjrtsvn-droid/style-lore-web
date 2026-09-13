<?php
/**
 * POST /api/auth/forgot — { email } — starts a password reset.
 *
 * Anti-enumeration: this always returns the same success message whether
 * or not that email has an account, and never errors just because the
 * email wasn't found. Only the presence of a reset email in their inbox
 * tells them anything.
 */
require_once __DIR__ . '/../includes/helpers.php';
require_method('POST');

$body = request_json();
$email = normalize_email($body['email'] ?? '');

if (is_valid_email($email)) {
    try {
        $pdo = db();
        rate_limit($pdo, 'forgot:ip:' . client_ip(), 10, 3600);
        rate_limit($pdo, 'forgot:email:' . $email, 3, 3600);
        $stmt = $pdo->prepare('SELECT id, email FROM accounts WHERE email = ?');
        $stmt->execute([$email]);
        $account = $stmt->fetch();

        if ($account) {
            $resetToken = new_auth_token();
            $expires = current_time_ms() + (60 * 60 * 1000); // 1 hour
            $pdo->prepare('UPDATE accounts SET reset_token_hash = ?, reset_token_expires = ? WHERE id = ?')
                ->execute([hash_token($resetToken), $expires, $account['id']]);

            $resetUrl = SITE_BASE_URL . '/?reset=' . $resetToken;
            send_app_email(
                $account['email'],
                'Reset your Style-LORE password',
                "We got a request to reset your Style-LORE password.\n\n$resetUrl\n\nThis link works for 1 hour. If you didn't ask for this, you can ignore this email — your password hasn't changed.\n"
            );
        }
    } catch (Throwable $e) {
        error_log('Forgot-password failed: ' . $e->getMessage());
        // Fall through to the same generic response either way.
    }
}

json_response(['ok' => true, 'message' => "If that email has an account, we've sent a reset link."]);
