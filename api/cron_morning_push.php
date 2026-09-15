<?php
/**
 * 8 AM "Today's outfit" push. Run HOURLY by a cPanel cron job:
 *
 *   5 * * * * /usr/local/bin/php /home/<cpanel-user>/public_html/api/cron_morning_push.php
 *
 * (CLI runs need no key; an HTTP call needs ?key=CRON_KEY.)
 *
 * Every hour it finds the devices whose *local* clock (device_tokens
 * .tz_offset, sent by the app when it registers its push token) is in the
 * 8 o'clock hour, that haven't had today's push yet, whose account has at
 * least two closet items synced (otherwise there is no outfit to show)
 * and hasn't opted out (accounts.morning_push_opt_out, Home → settings).
 * One push per account per local day. Never emails, never throws.
 *
 * Add ?dry=1 (or --dry on the CLI) to list who would get one without
 * sending.
 */
require_once __DIR__ . '/../includes/helpers.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    $key = (string)($_GET['key'] ?? '');
    if (!defined('CRON_KEY') || CRON_KEY === '' || !hash_equals(CRON_KEY, $key)) {
        error_response('Not authorized.', 401);
    }
}
$dry = $isCli ? in_array('--dry', $argv ?? [], true) : isset($_GET['dry']);

$pdo = db();
ensure_digest_columns($pdo);
ensure_device_token_columns($pdo);

$nowUtc = time();
$rows = $pdo->query('SELECT token, account_id, tz_offset, morning_push_day FROM device_tokens')->fetchAll(PDO::FETCH_ASSOC);

$due = [];        // account_id => local day
$tokensByAcct = [];
foreach ($rows as $r) {
    $local = $nowUtc + 60 * (int)$r['tz_offset'];
    $hour = (int)gmdate('G', $local);
    $day = gmdate('Y-m-d', $local);
    $tokensByAcct[$r['account_id']][] = $r['token'];
    if ($hour !== 8) continue;
    if ($r['morning_push_day'] === $day) continue;
    $due[$r['account_id']] = $day;
}

$sent = 0; $skipped = 0; $list = [];
$closetStmt = $pdo->prepare('SELECT COUNT(*) FROM closet_items WHERE account_id = ?');
$optStmt = $pdo->prepare('SELECT morning_push_opt_out FROM accounts WHERE id = ?');
$markStmt = $pdo->prepare('UPDATE device_tokens SET morning_push_day = ? WHERE account_id = ?');

$bodies = [
    "Built from your closet, for your lines. Tap to see it.",
    "One less decision this morning — your outfit is on Home.",
    "Your pieces, put together for today. Open Style-LORE.",
];

foreach ($due as $acct => $day) {
    try {
        $optStmt->execute([$acct]);
        if ((int)$optStmt->fetchColumn() === 1) { $skipped++; continue; }
        $closetStmt->execute([$acct]);
        if ((int)$closetStmt->fetchColumn() < 2) { $skipped++; continue; }
        $list[] = $acct;
        if ($dry) continue;
        // Mark first so a slow FCM call can't double-send on the next hour.
        $markStmt->execute([$day, $acct]);
        $body = $bodies[crc32($acct . $day) % count($bodies)];
        send_push_notification($pdo, $acct, "Today's outfit is ready", $body, ['screen' => 'home', 'kind' => 'morning_outfit']);
        $sent++;
    } catch (Throwable $e) {
        error_log('cron_morning_push: ' . $e->getMessage());
    }
}

$out = ['ok' => true, 'dry' => $dry, 'dueAccounts' => count($due), 'sent' => $sent, 'skipped' => $skipped, 'accounts' => $dry ? $list : count($list), 'utc' => gmdate('c', $nowUtc)];
if ($isCli) { echo json_encode($out), "\n"; exit; }
json_response($out);
