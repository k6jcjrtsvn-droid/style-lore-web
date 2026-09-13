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
        rate_limit($pdo, 'resend:ip:' . client_ip(), 10, 3600);
        rate_limit($pdo, 'resend:email:' . $email, 3, 3600);
        $stmt = $pdo->prepare('SELECT a.id, a.email, p.name FROM accounts a JOIN profiles p ON p.id = a.id WHERE a.email = ? AND a.email_verified = 0');
        $stmt->execute([$email]);
        $account = $stmt->fetch();

        if ($account) {
            $verifyToken = new_auth_token();
            $expires = current_time_ms() + (48 * 60 * 60 * 1000);
            $pdo->prepare('UPDATE accounts SET verify_token_hash = ?, verify_token_expires = ? WHERE id = ?')
                ->execute([hash_token($verifyToken), $expires, $account['id']]);

            $verifyUrl = SITE_BASE_URL . '/?verify=' . $verifyToken;
            $safeName = htmlspecialchars($account['name']);
            $verifyHtml = style_lore_email_html(
                'Verify your email',
                "<p style=\"margin:0 0 16px;\">Hi $safeName,</p><p style=\"margin:0;\">Here's your new verification link for Style-LORE.</p>",
                'Verify my email',
                $verifyUrl,
                'This link works for 48 hours.'
            );
            send_app_html_email(
                $account['email'],
                'Verify your Style-LORE email',
                $verifyHtml,
                "Hi {$account['name']},\n\nOne click to verify your email on Style-LORE:\n\n$verifyUrl\n\nThis link works for 48 hours.\n"
            );
        }
    } catch (Throwable $e) {
        error_log('Resend verification failed: ' . $e->getMessage());
    }
}

json_response(['ok' => true]);
