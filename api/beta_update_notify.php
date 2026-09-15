<?php
/**
 * CLI-only: tell every beta tester that a new app build is out and how to
 * update. Emails everyone in beta_testers whose status is 'synced' or 'sent'
 * (i.e. people who were actually given access), plus any extra addresses
 * passed on the command line.
 *
 *   /usr/local/bin/php api/beta_update_notify.php 1.2 "What changed in one or two sentences." [extra@example.com ...]
 *
 * Also update /app-versions.json so the app itself shows the "Update
 * available" card on Home — this email is the nudge, that card is the
 * reminder for anyone who missed the email.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/helpers.php';

const STORE_URL = 'https://play.google.com/store/apps/details?id=com.stylelore.app';
const TESTING_URL = 'https://play.google.com/apps/testing/com.stylelore.app';

$version = trim((string)($argv[1] ?? ''));
$notes = trim((string)($argv[2] ?? ''));
if ($version === '') { fwrite(STDERR, "usage: beta_update_notify.php <version> \"<what changed>\" [extra emails...]\n"); exit(1); }
$extra = array_slice($argv, 3);

$pdo = db();
$rows = $pdo->query("SELECT email, name FROM beta_testers WHERE status IN ('synced','sent')")->fetchAll();
$targets = [];
foreach ($rows as $r) $targets[normalize_email($r['email'])] = (string)($r['name'] ?? '');
foreach ($extra as $e) { $e = normalize_email($e); if ($e !== '') $targets[$e] = $targets[$e] ?? ''; }

$sent = 0; $failed = 0;
foreach ($targets as $email => $name) {
    $first = trim(explode(' ', $name)[0] ?? '');
    $safeName = htmlspecialchars($first !== '' ? $first : 'there');
    $safeNotes = htmlspecialchars($notes);
    $html = style_lore_email_html(
        'Style-LORE ' . htmlspecialchars($version) . ' is ready — please update',
        "<p style=\"margin:0 0 14px;\">Hi $safeName,</p>"
        . "<p style=\"margin:0 0 14px;\">A new build of the Style-LORE beta is out (version " . htmlspecialchars($version) . ")." . ($safeNotes !== '' ? " $safeNotes" : '') . "</p>"
        . "<p style=\"margin:0 0 6px;\"><b>To update (on your Android phone):</b></p>"
        . "<ol style=\"margin:0 0 14px 20px;padding:0;\">"
        . "<li style=\"margin:0 0 6px;\">Tap <b>Update Style-LORE</b> below — it opens the app's page in the Play Store.</li>"
        . "<li style=\"margin:0 0 6px;\">Tap <b>Update</b>. If the button says Open instead, the update hasn't reached your phone yet — give it an hour and check again.</li>"
        . "</ol>"
        . "<p style=\"margin:0;\">Make sure you're signed in to the Play Store with the email this was sent to. If you never installed the beta, use this link first: <a href=\"" . TESTING_URL . "\" style=\"color:#6C4C56;\">" . TESTING_URL . "</a></p>",
        'Update Style-LORE',
        STORE_URL,
        'You\'re getting this because you\'re a Style-LORE beta tester. Trouble updating? Just reply to this email.'
    );
    $text = "Hi " . ($first !== '' ? $first : 'there') . ",\n\nA new build of the Style-LORE beta is out (version $version)." . ($notes !== '' ? " $notes" : '') . "\n\nTo update, on your Android phone open:\n" . STORE_URL . "\nand tap Update. If it says Open instead, the update hasn't reached your phone yet - give it an hour and check again.\n\nMake sure you're signed in to the Play Store with the email this was sent to. Never installed the beta? Use this link first: " . TESTING_URL . "\n";
    $ok = send_app_html_email($email, 'Style-LORE ' . $version . ' is ready — please update', $html, $text);
    if ($ok) $sent++; else $failed++;
    echo date('c'), ' ', $email, ' ', ($ok ? 'sent' : 'FAILED: ' . ($GLOBALS['last_mail_error'] ?? 'unknown')), "\n";
}
echo "done: $sent sent, $failed failed\n";
