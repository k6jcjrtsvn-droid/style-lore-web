<?php
/**
 * api/beta_signup — public sign-up endpoint for Play Store closed-testing
 * tester recruitment (see beta.html).
 *
 * Fully self-serve since 2026-09-15: the Beta track's tester list is the
 * Google Group style-lore-testers@googlegroups.com, and that group is set
 * to "Anyone on the web can join". So nobody has to be added by hand any
 * more — the person joins the group themselves with one tap (signed in to
 * the Google account they use on their phone), then opens the Play opt-in
 * link and installs. This endpoint records the sign-up, marks it
 * 'sent' straight away, and emails the three steps with buttons. The
 * same steps are shown on beta.html the moment they submit.
 *
 * (Google still offers no API to add someone to a tester list; the open
 * group is what makes this automatic.)
 */
require_once __DIR__ . '/../includes/helpers.php';

const ADMIN_NOTIFY_EMAIL = 'support@style-lore.com';
const TESTER_GROUP_URL = 'https://groups.google.com/g/style-lore-testers';
const PLAY_OPT_IN_URL = 'https://play.google.com/apps/testing/com.stylelore.app';
const PLAY_LISTING_URL = 'https://play.google.com/store/apps/details?id=com.stylelore.app';

require_method('POST');

$pdo = db();
rate_limit($pdo, 'beta:ip:' . client_ip(), 5, 3600, 'Too many sign-ups from this connection — please try again later.');
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
    )->execute([uuidv4(), $email, $name, 'sent', current_time_ms()]);
} elseif ($row['status'] === 'pending') {
    $pdo->prepare('UPDATE beta_testers SET status = ? WHERE id = ?')->execute(['sent', $row['id']]);
}

// Same email whether new or repeat — a repeat submission is usually
// someone who lost the first email.
$safeName = htmlspecialchars($name !== '' ? $name : 'there');
$step = function (int $n, string $title, string $body, string $btn, string $url): string {
    return '<tr><td style="padding:0 0 18px;vertical-align:top;">'
        . '<div style="font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:#C92C69;margin:0 0 4px;">Step ' . $n . '</div>'
        . '<div style="font-size:16px;font-weight:700;margin:0 0 6px;">' . $title . '</div>'
        . '<div style="font-size:14px;line-height:1.5;margin:0 0 10px;">' . $body . '</div>'
        . '<a href="' . htmlspecialchars($url) . '" style="display:inline-block;background:#C92C69;color:#fff;text-decoration:none;font-weight:700;font-size:14px;padding:10px 18px;border-radius:999px;">' . $btn . '</a>'
        . '</td></tr>';
};
$stepsHtml = '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin-top:6px;">'
    . $step(1, 'Join the tester group', 'Tap Join, signed in with the <b>same Google account you use on your Android phone</b>. That is what makes you eligible — it is instant.', 'Join the tester group', TESTER_GROUP_URL)
    . $step(2, 'Become a tester on Google Play', 'On your phone, open this link and tap <b>Become a tester</b>.', 'Open the opt-in page', PLAY_OPT_IN_URL)
    . $step(3, 'Install Style-LORE', 'Then install from Google Play like any other app. Updates arrive automatically.', 'Download on Google Play', PLAY_LISTING_URL)
    . '</table>';
$html = style_lore_email_html(
    "You're in — here's your download",
    "<p style=\"margin:0 0 16px;\">Hi $safeName,</p><p style=\"margin:0 0 18px;\">Thanks for testing Style-LORE. Three taps and you're in:</p>" . $stepsHtml
    . "<p style=\"margin:18px 0 0;padding:14px 16px;background:#F7D9E5;border-radius:10px;font-size:14px;line-height:1.5;\">"
    . "<b>No Android phone, or want to start right now?</b> Style-LORE works in any browser at "
    . "<a href=\"https://style-lore.com/\" style=\"color:#C92C69;font-weight:700;\">style-lore.com</a> &mdash; "
    . "take the quiz, get your colours and build your closet today. It is the same account either way, so anything "
    . "you do on the website is already there when the app installs.</p>"
    . "<p style=\"margin:12px 0 0;font-size:13px;color:#6C4C56;\">Step 1 not working? Make sure you're signed in to Google as the account your phone uses, then try again. If Google Play says the app isn't available, give it a few minutes after joining the group.</p>",
    null,
    null,
    "Questions or feedback? Just reply to this email — it goes straight to us."
);
send_app_html_email(
    $email,
    "Your Style-LORE beta download — 3 quick steps",
    $html,
    "Hi " . ($name !== '' ? $name : 'there') . ",\n\nThanks for testing Style-LORE. Three quick steps:\n\n1) Join the tester group (signed in as the Google account your phone uses): " . TESTER_GROUP_URL . "\n2) On your phone, become a tester: " . PLAY_OPT_IN_URL . "\n3) Install from Google Play: " . PLAY_LISTING_URL . "\n\nNo Android phone, or want to start right now? Style-LORE works in any browser at https://style-lore.com/ - same account either way, so anything you do on the website is already there when the app installs.\n\nReply to this email with any questions.\n"
);

if (!$row) {
    // FYI only — nothing to do by hand any more.
    $adminName = $name !== '' ? $name : '(no name given)';
    $safeAdminName = htmlspecialchars($adminName);
    $safeEmail = htmlspecialchars($email);
    $total = (int)$pdo->query("SELECT COUNT(*) FROM beta_testers")->fetchColumn();
    $adminHtml = style_lore_email_html(
        'New beta tester signed up',
        "<p style=\"margin:0 0 16px;\"><strong>$safeAdminName</strong><br>$safeEmail</p><p style=\"margin:0;\">They've been sent the join-group / opt-in / install steps automatically. $total sign-ups so far.</p>",
        'Open tester list',
        SITE_BASE_URL . '/beta-admin.html',
        'You are receiving this because you are the Style-LORE admin.'
    );
    send_app_html_email(
        ADMIN_NOTIFY_EMAIL,
        'New Style-LORE beta tester: ' . ($name !== '' ? $name : $email),
        $adminHtml,
        "New beta tester signed up (steps emailed automatically).\n\nName: $adminName\nEmail: $email\nTotal sign-ups: $total\n"
    );
}

// Whether they were already signed up or brand new, respond the same way
// so a repeat submission (e.g. double-click) never looks like an error.
json_response(['ok' => true, 'groupUrl' => TESTER_GROUP_URL, 'optInUrl' => PLAY_OPT_IN_URL, 'storeUrl' => PLAY_LISTING_URL]);
