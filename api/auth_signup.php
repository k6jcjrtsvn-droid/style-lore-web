<?php
require_once __DIR__ . '/../includes/helpers.php';
require_method('POST');

$body = request_json();
$name = trim((string)($body['name'] ?? ''));
$name = mb_substr($name, 0, 60);
$email = normalize_email($body['email'] ?? '');
$password = (string)($body['password'] ?? '');
$refCode = (string)($body['ref'] ?? '');

if (mb_strlen($name) < 2) error_response('Enter a display name (at least 2 characters).', 400);
if (!is_valid_email($email)) error_response('Enter a valid email address.', 400);
if (strlen($password) < 8) error_response('Password must be at least 8 characters.', 400);

$pdo = db();
ensure_funnel_schema($pdo);
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
    // 14 days, not 48 hours. The old window assumed people open their email
    // the same day; in practice accounts sat unverified for a week and the
    // link was dead long before anyone went looking for it. A verification
    // link is not a password reset — it proves an address is reachable, and
    // that claim does not go stale in two days.
    $verifyExpires = $now + (14 * 24 * 60 * 60 * 1000); // 14 days

    // MUST stay OUTSIDE the transaction. It runs CREATE TABLE IF NOT EXISTS,
    // and DDL forces an implicit COMMIT in MySQL -- so called from inside the
    // transaction (where this line used to sit, just after the accounts
    // INSERT) it silently ended it. The $pdo->commit() below then threw
    // "There is no active transaction" and this endpoint returned 500, even
    // though the implicit commit had already persisted the rows. The account
    // existed while the person was told sign-up had failed, and their retry
    // hit "An account with that email already exists".
    //
    // Intermittent because of the `static $done` guard inside the function:
    // only the FIRST sign-up handled by each fresh PHP-FPM worker ran the DDL.
    // Recorded in public_html/api/error_log as "Signup failed: There is no
    // active transaction" on 2026-09-22 20:37:43 and 2026-09-23 16:53:16 UTC.
    ensure_auth_tokens_table($pdo);

    $pdo->beginTransaction();
    $ins1 = $pdo->prepare(
        'INSERT INTO accounts (id, email, password_hash, created_at, auth_token_hash, email_verified, verify_token_hash, verify_token_expires, signup_device)
         VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?)'
    );
    // Which platform this account was created on. The client has always sent
    // it; signup threw it away, so "how many of these came from the iOS
    // beta" had no answer. Labels the row and nothing more — never trusted.
    $signupDevice = normalize_device($body['device'] ?? null);
    $ins1->execute([$id, $email, $passwordHash, $now, hash_token($authToken), hash_token($verifyToken), $verifyExpires, $signupDevice]);
    // Multi-device sessions: record this first token so later logins on other devices don't sign this one out.
    try { $pdo->prepare('INSERT IGNORE INTO auth_tokens (token_hash, account_id, created_at, last_used_at, label) VALUES (?,?,?,?,?)')->execute([hash_token($authToken), $id, $now, $now, 'signup']); } catch (Throwable $e) { error_log('signup auth_tokens: ' . $e->getMessage()); }
    $ins2 = $pdo->prepare('INSERT INTO profiles (id, name, bio, avatar_url, updated_at) VALUES (?, ?, ?, NULL, ?)');
    $ins2->execute([$id, $name, '', $now]);
    $pdo->commit();

    // Referral: signed up from a friend's invite link → remember who, and
    // give the new account its free month right away. The friend's month
    // is granted once this email is verified (see auth_verify.php).
    $referredBy = $refCode !== '' ? account_id_for_referral_code($pdo, $refCode) : null;
    $referralUntil = 0;
    if ($referredBy && $referredBy !== $id) {
        try {
            $pdo->prepare('UPDATE accounts SET referred_by = ? WHERE id = ?')->execute([$referredBy, $id]);
            $referralUntil = referral_grant_days($pdo, $id, REFERRAL_DAYS);
        } catch (Throwable $e) { error_log('signup referral: ' . $e->getMessage()); }
    }

    $verifyUrl = SITE_BASE_URL . '/?verify=' . $verifyToken;
    $safeName = htmlspecialchars($name);
    $verifyHtml = style_lore_email_html(
        'Verify your email',
        "<p style=\"margin:0 0 16px;\">Hi $safeName,</p><p style=\"margin:0;\">Thanks for joining Style-LORE! Confirm your email address to finish setting up your account.</p>",
        'Verify my email',
        $verifyUrl,
        "This link works for 14 days. If you didn't create this account, you can safely ignore this email."
    );
    send_app_html_email(
        $email,
        'Verify your Style-LORE email',
        $verifyHtml,
        "Hi $name,\n\nOne click to verify your email on Style-LORE:\n\n$verifyUrl\n\nThis link works for 14 days. If you didn't create this account, you can ignore this email.\n"
    );

    json_response(['id' => $id, 'name' => $name, 'email' => $email, 'token' => $authToken, 'emailVerified' => false, 'referralUntil' => $referralUntil], 201);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Signup failed: ' . $e->getMessage());
    error_response("Couldn't create your account — try again.", 500);
}
