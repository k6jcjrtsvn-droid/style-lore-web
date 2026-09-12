<?php
/**
 * api/beta_signup — public sign-up form endpoint for Play Store closed-
 * testing tester recruitment (see beta.html).
 *
 * Google doesn't offer an API to add someone to a Play Console closed-
 * testing tester list, so this endpoint can't grant Play Store access by
 * itself. It just records the email as "pending" and sends an immediate
 * "you're on the list" confirmation. Kenneth periodically opens
 * beta-admin.html to copy pending emails into Play Console's tester list
 * by hand (see api/admin_beta_testers.php), then sends everyone the real
 * download link once Google actually has them allow-listed.
 */
require_once __DIR__ . '/../includes/helpers.php';
require_method('POST');

$pdo = db();
$body = request_json();
$email = normalize_email($body['email'] ?? '');
$name = mb_substr(trim((string)($body['name'] ?? '')), 0, 60);

if (!is_valid_email($email)) {
    error_response('Please enter a valid email address.', 400);
}

$existing = $pdo->prepare('SELECT id, status FROM beta_testers WHERE email = ?');
$existing->execute([$email]);
$row = $existing->fetch();

if (!$row) {
    $pdo->prepare(
        'INSERT INTO beta_testers (id, email, name, status, created_at) VALUES (?, ?, ?, ?, ?)'
    )->execute([uuidv4(), $email, $name, 'pending', current_time_ms()]);

    $safeName = htmlspecialchars($name !== '' ? $name : 'there');
    $html = style_lore_email_html(
        "You're on the list!",
        "<p style=\"margin:0 0 16px;\">Hi $safeName,</p><p style=\"margin:0;\">Thanks for signing up to beta test Style-LORE! We add new testers to our Google Play testing list in small batches, so keep an eye on your inbox &mdash; you'll get a follow-up email with your direct download link soon.</p>",
        null,
        null,
        "Questions in the meantime? Just reply to this email."
    );
    send_app_html_email(
        $email,
        "You're on the Style-LORE beta list",
        $html,
        "Hi " . ($name !== '' ? $name : 'there') . ",\n\nThanks for signing up to beta test Style-LORE! We add new testers to our Google Play testing list in small batches, so keep an eye on your inbox -- you'll get a follow-up email with your direct download link soon.\n"
    );
}

// Whether they were already signed up or brand new, respond the same way
// so a repeat submission (e.g. double-click) never looks like an error.
json_response(['ok' => true]);
