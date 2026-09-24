<?php
/**
 * One-off "can I ask you three questions?" email, sent once per account a
 * set number of days after they signed up. Triggered by a cPanel cron job,
 * the same way the weekly digest is:
 *
 *   /usr/local/bin/php /home/<cpanel-user>/public_html/api/cron_feedback_survey.php
 *
 * (CLI runs need no key; an HTTP call needs ?key=CRON_KEY.)
 *
 * Deliberately modelled on cron_weekly_digest.php rather than on
 * admin_broadcast.php: the digest is the one mail path here that already
 * gets the audience right — verified addresses only, opt-outs respected,
 * a one-click unsubscribe in every message, a per-account timestamp so
 * nobody is mailed twice, and a batch cap so a shared host is never in
 * here for long.
 *
 * ONE opt-out covers every non-transactional email we send. Someone who
 * unsubscribed from the weekly digest has said they do not want us in
 * their inbox; sending them a survey anyway because it is "a different
 * list" is the thing everyone hates about mailing lists. The unsubscribe
 * link below is the same digest_unsubscribe.php endpoint.
 *
 * The survey asks people to hit reply. That is not laziness — at this size
 * a reply gets a far better response rate than a form, and Resend already
 * sets reply_to to SUPPORT_EMAIL, so the answers arrive somewhere real.
 *
 * Query/CLI options:
 *   ?dry=1        count the audience, send nothing
 *   ?days=N       override the wait after signup (default SURVEY_AFTER_DAYS, else 7)
 *   ?limit=N      batch size, default 200, max 500
 */
require_once __DIR__ . '/../includes/helpers.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    $key = (string)($_GET['key'] ?? '');
    if (!defined('CRON_KEY') || CRON_KEY === '' || !hash_equals(CRON_KEY, $key)) {
        error_response('Not authorized.', 401);
    }
}
if ($isCli) {
    $_GET['limit'] = $argv[1] ?? '200';
    if (in_array('--dry', $argv, true)) $_GET['dry'] = '1';
}
if (!defined('RESEND_API_KEY') || RESEND_API_KEY === '') {
    error_response('Mail is not configured.', 503);
}

$pdo = db();
ensure_digest_columns($pdo);

$now   = current_time_ms();
$days  = (int)($_GET['days'] ?? (defined('SURVEY_AFTER_DAYS') ? SURVEY_AFTER_DAYS : 7));
if ($days < 0) $days = 0;
$cutoff = $now - $days * 86400 * 1000;
$limit = min(500, max(1, (int)($_GET['limit'] ?? 200)));
$dry = isset($_GET['dry']);

// LEFT JOIN, not JOIN: someone can have an account and never have opened
// "Edit profile", and their opinion is worth exactly as much as anyone
// else's — arguably more, since not filling in a profile is itself a
// signal. The digest uses an inner join because it has nothing to say
// without a Kibbe type; this email does.
$stmt = $pdo->prepare(
    'SELECT a.id, a.email, p.name
       FROM accounts a
       LEFT JOIN profiles p ON p.id = a.id
      WHERE a.email_verified = 1
        AND COALESCE(a.digest_opt_out, 0) = 0
        AND a.survey_sent_at IS NULL
        AND a.created_at <= ?
      ORDER BY a.created_at ASC
      LIMIT ' . $limit
);
$stmt->execute([$cutoff]);
$rows = $stmt->fetchAll();

$sent = 0; $failed = 0;
foreach ($rows as $r) {
    $first = trim(explode(' ', trim((string)($r['name'] ?? '')))[0] ?? '');
    $hi = $first !== '' ? $first : 'there';
    $unsub = SITE_BASE_URL . '/api/digest_unsubscribe.php?id=' . rawurlencode($r['id']) . '&t=' . digest_unsub_token($r['id']);

    $heading = 'Can I ask you three questions?';
    $body =
        '<p style="margin:0 0 14px;">Hi ' . htmlspecialchars($hi) . ' — I\'m Jayne, from Style-LORE. You took the quiz a little while back.</p>'
      . '<p style="margin:0 0 18px;">I would like to know what you actually thought. Three questions, and <b>hitting reply is a perfectly good way to answer</b> — a sentence each is plenty.</p>'
      . '<p style="margin:0 0 10px; padding:14px 16px; background:#F7D9E5; border-radius:12px;">'
      . '<b>1.</b> What made you go looking for something like this?<br><br>'
      . '<b>2.</b> What is the one thing that would make it more useful to you?<br><br>'
      . '<b>3.</b> Was anything confusing, wrong, or broken?'
      . '</p>'
      . '<p style="margin:18px 0 0;">Every reply comes straight to me, and I read all of them.</p>';
    $footer = 'You are getting this once, because you made a Style-LORE account. '
            . '<a href="' . htmlspecialchars($unsub) . '" style="color:#6C4C56;">Unsubscribe from Style-LORE emails</a>';
    $html = style_lore_email_html($heading, $body, 'Open Style-LORE', SITE_BASE_URL . '/', $footer);

    $text = "Hi $hi — I'm Jayne, from Style-LORE. You took the quiz a little while back.\n\n"
          . "I'd like to know what you actually thought. Three questions, and just hitting reply is a perfectly good way to answer — a sentence each is plenty.\n\n"
          . "1. What made you go looking for something like this?\n"
          . "2. What's the one thing that would make it more useful to you?\n"
          . "3. Was anything confusing, wrong, or broken?\n\n"
          . "Every reply comes straight to me, and I read all of them.\n\n"
          . "— Jayne, Style-LORE\n"
          . SITE_BASE_URL . "/\n\n"
          . "You're getting this once, because you made a Style-LORE account.\n"
          . "Unsubscribe: $unsub\n";

    if ($dry) { $sent++; continue; }

    // Stamp BEFORE deciding on success is wrong (a transient provider
    // error would silently skip someone forever), and stamping only on
    // success risks a re-send if the run dies mid-loop. Stamping on
    // success is the lesser problem: at worst a failed send is retried
    // next run, which is what you want.
    if (send_mail_tracked($r['email'], 'Can I ask you three questions?', $html, $text)) {
        $pdo->prepare('UPDATE accounts SET survey_sent_at = ? WHERE id = ?')->execute([$now, $r['id']]);
        $sent++;
    } else {
        $failed++;
    }
}

json_response([
    'ok' => true,
    'afterDays' => $days,
    'candidates' => count($rows),
    'sent' => $sent,
    'failed' => $failed,
    'dry' => $dry,
]);
