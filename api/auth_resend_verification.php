<?php
/**
 * POST /api/auth/resend-verification — { email } — same anti-enumeration
 * shape as auth_forgot.php: always the same generic response.
 */
require_once __DIR__ . '/../includes/helpers.php';
require_method('POST');

$body = request_json();
$email = normalize_email($body['email'] ?? '');

if (is_valid_email($email)) {
    try {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT a.id, a.email, p.name FROM accounts a JOIN profiles p ON p.id = a.id WHERE a.email = ? AND a.email_verified = 0');
        $stmt->execute([$email]);
        $account = $stmt->fetch();

        if ($account) {
            $verifyToken = new_auth_token();
            $expires = current_time_ms() + (48 * 60 * 60 * 1000);
            $pdo->prepare('UPDATE accounts SET verify_token_hash = ?, verify_token_expires = ? WHERE id = ?')
                ->execute([hash_token($verifyToken), $expires, $account['id']]);

            $verifyUrl = SITE_BASE_URL . '/?verify=' . $verifyToken;
            send_app_email(
                $account['email'],
                'Verify your Style-LORE email',
                "Hi {$account['name']},\n\nOne click to verify your email on Style-LORE:\n\n$verifyUrl\n\nThis link works for 48 hours.\n"
            );
        }
    } catch (Throwable $e) {
        error_log('Resend verification failed: ' . $e->getMessage());
    }
}

json_response(['ok' => true]);
