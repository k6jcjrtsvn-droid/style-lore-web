<?php
/**
 * api/admin/broadcast — a one-off tool for Kenneth to email every account
 * about app updates, gated by the existing ADMIN_KEY shared secret (see
 * config.php — the same constant config.sample.php already documents for
 * the moderation endpoint's `key` query-param scheme).
 *
 * GET  /api/admin/broadcast?key=... — dry run. Reports how many accounts
 *      would be emailed and how many of those still need to verify their
 *      email, without sending anything.
 * POST /api/admin/broadcast {key} — actually sends the email to every
 *      account. Verified accounts get a short "here's what's new" note;
 *      unverified accounts get the same note plus a reminder (with a
 *      fresh 48-hour verification link, same as auth_resend_verification.php)
 *      that they still need to verify before they can post in Community.
 *
 * Intentionally not wired into anything recurring or the frontend — this
 * is a manual, one-time broadcast Kenneth triggers himself by visiting the
 * URL / running one request, the same way admin_moderation.php works.
 * Sends via send_app_email() (helpers.php), the same mail() + envelope-
 * sender setup already confirmed working for verification/reset emails.
 */
require_once __DIR__ . '/../includes/helpers.php';

function require_admin_key(): void {
    // Preferred: "X-Admin-Key" header (keeps the key out of URLs/logs);
    // ?key= and a JSON "key" field still work for older callers.
    $sent = (string)($_SERVER['HTTP_X_ADMIN_KEY'] ?? '');
    if ($sent === '') $sent = (string)($_GET['key'] ?? '');
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

// Kenneth: edit this before sending — it's the body of the one-time
// "what's new" email every account will get.
const BROADCAST_BODY = <<<TXT
Style-LORE has a few new things worth a look:

- The photo Checker now runs fully on your device — instant, no limits,
  and your photo never leaves your phone.
- Push notifications: turn them on and you'll get a phone alert the
  moment someone likes, comments, follows, messages, or joins your group
  — tap it to jump straight there.
- A handful of bug fixes across posting, closets, and email verification.

Thanks for testing — reply any time with feedback or bugs.

— Jayne and Kenneth, Style-LORE
TXT;

// Everyone below excludes accounts that unsubscribed. One opt-out covers
// every non-transactional email: someone who turned off the weekly digest
// said they did not want us in their inbox, and a broadcast is not a
// different enough thing to override that.
ensure_digest_columns($pdo);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $total = (int)$pdo->query('SELECT COUNT(*) c FROM accounts WHERE COALESCE(digest_opt_out, 0) = 0')->fetch()['c'];
    $unverified = (int)$pdo->query('SELECT COUNT(*) c FROM accounts WHERE COALESCE(digest_opt_out, 0) = 0 AND email_verified = 0')->fetch()['c'];
    $optedOut = (int)$pdo->query('SELECT COUNT(*) c FROM accounts WHERE COALESCE(digest_opt_out, 0) = 1')->fetch()['c'];
    json_response(['ok' => true, 'dryRun' => true, 'wouldEmail' => $total, 'unverifiedAmongThem' => $unverified, 'skippedOptedOut' => $optedOut]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $stmt = $pdo->query('SELECT a.id, a.email, a.email_verified, p.name FROM accounts a LEFT JOIN profiles p ON p.id = a.id WHERE COALESCE(a.digest_opt_out, 0) = 0');
    $accounts = $stmt->fetchAll();

    $sent = 0;
    $failed = 0;
    foreach ($accounts as $a) {
        $body = "Hi,\n\n" . BROADCAST_BODY;

        if (!$a['email_verified']) {
            $verifyToken = new_auth_token();
            $expires = current_time_ms() + (48 * 60 * 60 * 1000);
            $pdo->prepare('UPDATE accounts SET verify_token_hash = ?, verify_token_expires = ? WHERE id = ?')
                ->execute([hash_token($verifyToken), $expires, $a['id']]);
            $verifyUrl = SITE_BASE_URL . '/?verify=' . $verifyToken;
            $body .= "\n\nOne more thing: you haven't verified your email yet, so you can't post in Community until you do. Verify here (link works for 48 hours):\n$verifyUrl\n";
        }

        // A commercial email has to carry a way out of it. Same one-click
        // HMAC link the weekly digest uses, so there is one unsubscribe
        // mechanism rather than two that can disagree.
        $unsub = SITE_BASE_URL . '/api/digest_unsubscribe.php?id=' . rawurlencode($a['id']) . '&t=' . digest_unsub_token($a['id']);
        $body .= "\n\n--\nYou're getting this because you made a Style-LORE account.\nUnsubscribe from Style-LORE emails: $unsub\n";

        $ok = send_app_email($a['email'], "What's new on Style-LORE", $body);
        if ($ok) $sent++; else $failed++;
    }

    json_response(['ok' => true, 'sent' => $sent, 'failed' => $failed]);
}

error_response('Method not allowed.', 405);
