<?php
/**
 * Weekly "your type this week" email. Triggered by a cPanel cron job:
 *
 *   curl -s "https://style-lore.com/api/cron_weekly_digest.php?key=CRON_KEY"
 *
 * Protected by CRON_KEY in config.php (a random string; not an admin
 * key). Sends one email per verified account that has a Kibbe type on
 * its profile and hasn't opted out (digest_opt_out = 0), at most once
 * every 6 days per account (digest_sent_at), in batches of 200 per run so
 * a shared host never spends more than a few seconds here.
 *
 * Content rotates by ISO week from includes/digest_tips.php (generated
 * from the same data as the public type guides), so every week's email is
 * different and links back to the person's type guide and the app.
 */
require_once __DIR__ . '/../includes/helpers.php';

$key = (string)($_GET['key'] ?? '');
if (!defined('CRON_KEY') || CRON_KEY === '' || !hash_equals(CRON_KEY, $key)) {
    error_response('Not authorized.', 401);
}
if (!defined('RESEND_API_KEY') || RESEND_API_KEY === '') {
    error_response('Mail is not configured.', 503);
}

$pdo = db();
ensure_digest_columns($pdo);
$tips = require __DIR__ . '/../includes/digest_tips.php';
$week = (int)date('W');
$now = current_time_ms();
$sixDaysAgo = $now - 6 * 86400 * 1000;
$limit = min(500, max(1, (int)($_GET['limit'] ?? 200)));
$dry = isset($_GET['dry']);

$stmt = $pdo->prepare(
    'SELECT a.id, a.email, p.name, p.kibbe_type_name
       FROM accounts a
       JOIN profiles p ON p.id = a.id
      WHERE a.email_verified = 1
        AND COALESCE(a.digest_opt_out, 0) = 0
        AND (a.digest_sent_at IS NULL OR a.digest_sent_at < ?)
        AND p.kibbe_type_name IS NOT NULL AND p.kibbe_type_name <> ""
      ORDER BY a.digest_sent_at IS NULL DESC, a.digest_sent_at ASC
      LIMIT ' . $limit
);
$stmt->execute([$sixDaysAgo]);
$rows = $stmt->fetchAll();

$byName = [];
foreach ($tips as $id => $t) $byName[strtolower($t['name'])] = ['id' => $id] + $t;

$sent = 0; $skipped = 0; $failed = 0;
foreach ($rows as $r) {
    $type = $byName[strtolower(trim((string)$r['kibbe_type_name']))] ?? null;
    if (!$type) { $skipped++; continue; }
    $tipList = $type['tips'];
    $tip = $tipList[$week % count($tipList)];
    $outfit = $type['outfits'][$week % count($type['outfits'])];
    $first = trim(explode(' ', (string)$r['name'])[0] ?? '');
    $unsub = SITE_BASE_URL . '/api/digest_unsubscribe.php?id=' . rawurlencode($r['id']) . '&t=' . digest_unsub_token($r['id']);
    $guide = SITE_BASE_URL . '/types/' . $type['id'] . '.html';

    $heading = ($first ? $first . ', ' : '') . 'this week for your ' . $type['name'] . ' lines';
    $body = '<p style="margin:0 0 14px;">One thing to try this week:</p>'
          . '<p style="margin:0 0 18px; padding:14px 16px; background:#F7D9E5; border-radius:12px;"><b>' . htmlspecialchars($tip) . '</b></p>'
          . '<p style="margin:0 0 6px;"><b>An outfit to start from</b></p>'
          . '<p style="margin:0 0 18px;">' . htmlspecialchars($outfit) . '</p>'
          . '<p style="margin:0;">Thinking about buying something? Describe it or snap it in the Checker and get a verdict against your type before you spend.</p>';
    $footer = 'You get this once a week because you took the Style-LORE quiz. <a href="' . htmlspecialchars($guide) . '" style="color:#C92C69;">Read the full ' . htmlspecialchars($type['name']) . ' guide</a> · <a href="' . htmlspecialchars($unsub) . '" style="color:#6C4C56;">Unsubscribe</a>';
    $html = style_lore_email_html($heading, $body, 'Open Style-LORE', SITE_BASE_URL . '/', $footer);
    $text = $heading . "\n\nOne thing to try this week: " . $tip . "\n\nAn outfit to start from: " . $outfit . "\n\nOpen Style-LORE: " . SITE_BASE_URL . "/\nFull guide: " . $guide . "\nUnsubscribe: " . $unsub . "\n";

    if ($dry) { $sent++; continue; }
    if (send_mail_tracked($r['email'], 'This week for your ' . $type['name'] . ' lines', $html, $text)) {
        $pdo->prepare('UPDATE accounts SET digest_sent_at = ? WHERE id = ?')->execute([$now, $r['id']]);
        $sent++;
    } else {
        $failed++;
    }
}

json_response(['ok' => true, 'week' => $week, 'candidates' => count($rows), 'sent' => $sent, 'skipped' => $skipped, 'failed' => $failed, 'dry' => $dry]);
