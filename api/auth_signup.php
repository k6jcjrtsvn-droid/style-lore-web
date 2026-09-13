<?php
require_once __DIR__ . '/../includes/helpers.php';
require_method('POST');

$body = request_json();
$name = trim((string)($body['name'] ?? ''));
$name = mb_substr($name, 0, 60);
$email = normalize_email($body['email'] ?? '');
$password = (string)($body['password'] ?? '');

if (mb_strlen($name) < 2) error_response('Enter a display name (at least 2 characters).', 400);
if (!is_valid_email($email)) error_response('Enter a valid email address.', 400);
if (strlen($password) < 8) error_response('Password must be at least 8 characters.', 400);

$pdo = db();
rate_limit($pdo, 'signup:ip:' . client_ip(), 5, 3600, 'Too many sign-ups from this connection — please try again later.');

$check = $pdo->prepare('SELECT id FROM accounts WHERE email = ?');
$check->execute([$email]);
if ($check->fetch()) {
    error_response('An account with that email already exists — log in instead.', 409);
}

try {
    $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
    $id = uuidv4();
    $now = current_time_ms();

    $authToken = new_auth_token();
    $verifyToken = new_auth_token();
    $verifyExpires = $now + (48 * 60 * 60 * 1000); // 48 hours

    $pdo->beginTransaction();
    $ins1 = $pdo->prepare(
        'INSERT INTO accounts (id, email, password_hash, created_at, auth_token_hash, email_verified, verify_token_hash, verify_token_expires)
         VALUES (?, ?, ?, ?, ?, 0, ?, ?)'
    );
    $ins1->execute([$id, $email, $passwordHash, $now, hash_token($authToken), hash_token($verifyToken), $verifyExpires]);
    $ins2 = $pdo->prepare('INSERT INTO profiles (id, name, bio, avatar_url, updated_at) VALUES (?, ?, ?, NULL, ?)');
    $ins2->execute([$id, $name, '', $now]);
    $pdo->commit();

    $verifyUrl = SITE_BASE_URL . '/?verify=' . $verifyToken;
    $safeName = htmlspecialchars($name);
    $verifyHtml = style_lore_email_html(
        'Verify your email',
        "<p style=\"margin:0 0 16px;\">Hi $safeName,</p><p style=\"margin:0;\">Thanks for joining Style-LORE! Confirm your email address to finish setting up your account.</p>",
        'Verify my email',
        $verifyUrl,
        "This link works for 48 hours. If you didn't create this account, you can safely ignore this email."
    );
    send_app_html_email(
        $email,
        'Verify your Style-LORE email',
        $verifyHtml,
        "Hi $name,\n\nOne click to verify your email on Style-LORE:\n\n$verifyUrl\n\nThis link works for 48 hours. If you didn't create this account, you can ignore this email.\n"
    );

    json_response(['id' => $id, 'name' => $name, 'email' => $email, 'token' => $authToken, 'emailVerified' => false], 201);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Signup failed: ' . $e->getMessage());
    error_response("Couldn't create your account — try again.", 500);
}
