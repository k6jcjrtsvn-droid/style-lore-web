<?php
/**
 * CLI-only helper: send the beta-tester invite email (same copy as
 * admin_beta_testers.php "send_links") to one or more addresses without
 * touching the beta_testers table. Used for ad-hoc invites Kenneth wants
 * to forward himself.
 *
 *   /usr/local/bin/php api/beta_invite_send.php someone@example.com [more...]
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/helpers.php';

const PLAY_STORE_TESTING_URL = 'https://play.google.com/apps/testing/com.stylelore.app';

$targets = array_slice($argv, 1);
if (!$targets) { fwrite(STDERR, "usage: beta_invite_send.php email [email...]\n"); exit(1); }

foreach ($targets as $email) {
    $email = normalize_email($email);
    if ($email === '') { echo "skip (bad email)\n"; continue; }
    $safeName = 'there';
    $html = style_lore_email_html(
        "You're in! Download Style-LORE",
        "<p style=\"margin:0 0 16px;\">Hi $safeName,</p>"
        . "<p style=\"margin:0 0 14px;\">You're approved as a Style-LORE beta tester. <b>Please open this email on your Android phone</b> — the link only works there, not on a computer.</p>"
        . "<p style=\"margin:0 0 6px;\"><b>Three quick steps (about a minute):</b></p>"
        . "<ol style=\"margin:0 0 14px 20px;padding:0;\">"
        . "<li style=\"margin:0 0 6px;\">On your phone, tap <b>Get the beta</b> below (sign in with the Google account this email was sent to).</li>"
        . "<li style=\"margin:0 0 6px;\">Tap <b>Become a tester</b> on the page that opens.</li>"
        . "<li style=\"margin:0 0 6px;\">Then tap <b>Download it on Google Play</b> and install Style-LORE from the Play Store — you're not done until the app is on your phone.</li>"
        . "</ol>"
        . "<p style=\"margin:0;\">Once it's installed, use it like you normally would and reply to this email with anything confusing, broken, or missing. Thank you for helping us launch!</p>",
        'Get the beta',
        PLAY_STORE_TESTING_URL,
        "This link only works on an Android phone signed in with the email address this was sent to. Trouble installing? Just reply to this email."
    );
    $text = "Hi there,\n\nYou're approved as a Style-LORE beta tester. PLEASE OPEN THIS EMAIL ON YOUR ANDROID PHONE - the link only works there, not on a computer.\n\nThree quick steps:\n1. On your phone, open this link (signed in with the Google account this email was sent to): " . PLAY_STORE_TESTING_URL . "\n2. Tap Become a tester.\n3. Tap Download it on Google Play and install Style-LORE from the Play Store - you're not done until the app is on your phone.\n\nThen use it like you normally would and reply to this email with anything confusing, broken or missing. Thank you for helping us launch!\n";
    $ok = send_app_html_email($email, "You're in! Download the Style-LORE beta", $html, $text);
    echo date('c'), ' ', $email, ' ', ($ok ? 'sent' : 'FAILED: ' . ($GLOBALS['last_mail_error'] ?? 'unknown')), "\n";
}
