<?php
/**
 * api/admin_beta_testers — Kenneth's control panel for the beta sign-up
 * pipeline (see beta.html / beta-admin.html). Gated by the same ADMIN_KEY
 * shared secret already used by admin_broadcast.php.
 *
 * Google gives no API to add someone to a Play Console closed-testing
 * tester list, so this can't be fully automatic. The flow is:
 *   1. beta_signup.php records new sign-ups here with status 'pending'.
 *   2. GET here to see pending/synced/sent counts and the tester list
 *      (beta-admin.html renders this with a "copy pending emails" button).
 *   3. Kenneth pastes those emails into Play Console's tester email list
 *      by hand, then POSTs {action:'mark_synced'} here once he's confirmed
 *      they're actually added -- this moves every 'pending' row to 'synced'.
 *   4. POST {action:'send_links'} emails every 'synced' tester their real
 *      Play Store testing link and marks them 'sent'.
 */
require_once __DIR__ . '/../includes/helpers.php';

// Kenneth: this is the link from Play Console > Testing > Closed testing >
// your track > "How testers join" > copy link. Update this if the testing
// track or package name ever changes.
const PLAY_STORE_TESTING_URL = 'https://play.google.com/apps/testing/com.stylelore.app';

function require_admin_key(): void {
    $sent = (string)($_GET['key'] ?? '');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $body = request_json();
        if ($body['key'] ?? null) $sent = (string)$body['key'];
    }
    if (!defined('ADMIN_KEY') || ADMIN_KEY === '' || !hash_equals(ADMIN_KEY, $sent)) {
        error_response('Not authorized.', 401);
    }
}

require_admin_key();
$pdo = db();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $rows = $pdo->query('SELECT email, name, status, created_at FROM beta_testers ORDER BY created_at DESC')->fetchAll();
    $counts = ['pending' => 0, 'synced' => 0, 'sent' => 0];
    foreach ($rows as $r) {
        if (isset($counts[$r['status']])) $counts[$r['status']]++;
    }
    json_response([
        'ok' => true,
        'counts' => $counts,
        'testers' => array_map(function ($r) {
            return [
                'email' => $r['email'],
                'name' => $r['name'],
                'status' => $r['status'],
                'createdAt' => (int)$r['created_at'],
            ];
        }, $rows),
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $body = request_json();
    $action = (string)($body['action'] ?? '');

    if ($action === 'mark_synced') {
        $stmt = $pdo->prepare("UPDATE beta_testers SET status = 'synced', synced_at = ? WHERE status = 'pending'");
        $stmt->execute([current_time_ms()]);
        json_response(['ok' => true, 'marked' => $stmt->rowCount()]);
    }

    if ($action === 'send_links') {
        $testers = $pdo->query("SELECT id, email, name FROM beta_testers WHERE status = 'synced'")->fetchAll();
        $sent = 0;
        $failed = 0;
        $skipped = 0;
        $errors = [];
        // Budget the loop so a slow mail() can't eat the whole request. If one
        // send blocks for a long time, stop before PHP's execution limit kills
        // us mid-loop with no response at all; unsent testers stay 'synced'
        // and are picked up next time the button is pressed.
        $started = microtime(true);
        $budgetSeconds = 20;
        foreach ($testers as $t) {
            if (microtime(true) - $started > $budgetSeconds) { $skipped++; continue; }
            $safeName = htmlspecialchars($t['name'] !== '' ? $t['name'] : 'there');
            $html = style_lore_email_html(
                "You're in! Download Style-LORE",
                "<p style=\"margin:0 0 16px;\">Hi $safeName,</p><p style=\"margin:0;\">You're approved as a Style-LORE beta tester. Tap the button below, accept as a tester on the Google Play page that opens, then install the app from the Play Store link that appears there.</p>",
                'Get the beta',
                PLAY_STORE_TESTING_URL,
                "This link only works with the email address you signed up with. Trouble installing? Just reply to this email."
            );
            $ok = send_app_html_email(
                $t['email'],
                "You're in! Download the Style-LORE beta",
                $html,
                "Hi " . ($t['name'] !== '' ? $t['name'] : 'there') . ",\n\nYou're approved as a Style-LORE beta tester. Open this link, accept as a tester, then install from the Play Store page that appears:\n\n" . PLAY_STORE_TESTING_URL . "\n\nThis link only works with the email address you signed up with.\n"
            );
            if ($ok) {
                $pdo->prepare("UPDATE beta_testers SET status = 'sent', sent_at = ? WHERE id = ?")->execute([current_time_ms(), $t['id']]);
                $sent++;
            } else {
                $failed++;
                // Hand the real reason back to the admin page. Without this a
                // failure is just a count, which is exactly how a broken send
                // went undiagnosed before.
                $errors[] = $t['email'] . ': ' . ($GLOBALS['last_mail_error'] ?? 'unknown error');
            }
        }
        json_response(['ok' => true, 'sent' => $sent, 'failed' => $failed, 'skipped' => $skipped, 'errors' => $errors]);
    }

    // Remove a tester outright -- test rows, typos, someone asking to be taken
    // off. Previously this needed a throwaway PHP script deployed to the server
    // and then deleted again, which is far too much ceremony for deleting a row.
    if ($action === 'delete_tester') {
        $email = normalize_email($body['email'] ?? '');
        if ($email === '') error_response('No email given.', 400);
        $stmt = $pdo->prepare('DELETE FROM beta_testers WHERE email = ?');
        $stmt->execute([$email]);
        json_response(['ok' => true, 'deleted' => $stmt->rowCount()]);
    }

    error_response('Unknown action.', 400);
}

error_response('Method not allowed.', 405);
