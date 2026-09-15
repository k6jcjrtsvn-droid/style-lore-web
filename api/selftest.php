<?php
/**
 * api/selftest — read-only health self-test for Claude's watchdog.
 *
 *   GET /api/selftest.php?token=…
 *
 * Reports what the backend can see about itself: PHP/MySQL versions, which
 * tables and columns the code expects vs. what the database actually has,
 * whether the mail/secret constants are configured (yes/no only — never the
 * values), simple row counts, and when the weekly digest last ran. No
 * personal data, no writes. Token lives in includes/watchdog_token.php.
 */
require_once __DIR__ . '/../includes/helpers.php';
@include_once __DIR__ . '/../includes/watchdog_token.php';

$token = (string)($_GET['token'] ?? '');
if (!defined('WATCHDOG_READ_TOKEN') || WATCHDOG_READ_TOKEN === '' || !hash_equals(WATCHDOG_READ_TOKEN, $token)) error_response('Not authorized.', 401);

$out = ['ok' => true, 'php' => PHP_VERSION, 'generatedAt' => date('c'), 'problems' => []];
try {
    $pdo = db();
    $out['mysql'] = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
    $db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();

    // What the code relies on (table => columns). Missing entries are the
    // classic cause of a silent 500 on one feature (e.g. a settings toggle
    // that "won't stick").
    $expect = [
        'accounts' => ['id', 'email', 'auth_token_hash', 'digest_opt_out', 'digest_sent_at', 'referral_code', 'referred_by', 'referral_until', 'email_verified_at'],
        'profiles' => ['color_result_json'],
        'subscriptions' => ['account_id', 'product_id'],
        'ai_reads' => ['account_id', 'used'],
        'client_errors' => ['fingerprint', 'created_at'],
        'closet_items' => ['account_id'],
        'posts' => ['id'],
        'beta_testers' => ['email', 'status'],
        'rate_limits' => [],
        'device_tokens' => ['account_id'],
    ];
    $st = $pdo->prepare('SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ?');
    $st->execute([$db]);
    $have = [];
    foreach ($st->fetchAll() as $r) $have[$r['TABLE_NAME']][] = $r['COLUMN_NAME'];
    $schema = [];
    foreach ($expect as $t => $cols) {
        if (!isset($have[$t])) { $schema[$t] = 'MISSING TABLE'; $out['problems'][] = "table $t missing"; continue; }
        $missing = array_values(array_diff($cols, $have[$t]));
        $schema[$t] = $missing ? ['missingColumns' => $missing] : 'ok';
        foreach ($missing as $c) $out['problems'][] = "column $t.$c missing";
    }
    $out['schema'] = $schema;

    // Row counts (no personal data).
    $counts = [];
    foreach (['accounts', 'profiles', 'closet_items', 'posts', 'subscriptions', 'beta_testers', 'client_errors'] as $t) {
        if (!isset($have[$t])) continue;
        try { $counts[$t] = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(); } catch (Throwable $e) { $counts[$t] = 'error'; }
    }
    $out['counts'] = $counts;

    // Weekly email: when did it last go out, how many opted out.
    if (isset($have['accounts']) && in_array('digest_sent_at', $have['accounts'], true)) {
        try {
            $last = $pdo->query('SELECT MAX(digest_sent_at) FROM accounts')->fetchColumn();
            $out['digest'] = ['lastSentAt' => $last ? date('c', (int)($last / 1000)) : null,
                              'optedOut' => (int)$pdo->query('SELECT COUNT(*) FROM accounts WHERE digest_opt_out = 1')->fetchColumn()];
        } catch (Throwable $e) { $out['digest'] = 'error: ' . $e->getMessage(); }
    }
} catch (Throwable $e) {
    $out['ok'] = false; $out['problems'][] = 'db: ' . $e->getMessage();
}

// Configuration presence only — never values.
$out['config'] = [
    'supportEmail' => defined('SUPPORT_EMAIL') && SUPPORT_EMAIL !== '',
    'mailFrom' => defined('MAIL_FROM') && MAIL_FROM !== '',
    'resend' => defined('RESEND_API_KEY') && RESEND_API_KEY !== '',
    'anthropic' => defined('ANTHROPIC_API_KEY') && ANTHROPIC_API_KEY !== '',
    'stripe' => defined('STRIPE_SECRET_KEY') && STRIPE_SECRET_KEY !== '',
    'stripeWebhook' => defined('STRIPE_WEBHOOK_SECRET') && STRIPE_WEBHOOK_SECRET !== '',
    'stripePrices' => defined('STRIPE_PRICE_MONTHLY') && STRIPE_PRICE_MONTHLY !== '' && defined('STRIPE_PRICE_YEARLY') && STRIPE_PRICE_YEARLY !== '',
    'revenuecatWebhook' => defined('REVENUECAT_WEBHOOK_SECRET') && REVENUECAT_WEBHOOK_SECRET !== '',
    'cronKey' => defined('CRON_KEY') && CRON_KEY !== '',
    'firebasePush' => defined('FIREBASE_SERVICE_ACCOUNT_PATH') && FIREBASE_SERVICE_ACCOUNT_PATH !== '' && is_readable(FIREBASE_SERVICE_ACCOUNT_PATH),
];
$out['uploadsWritable'] = is_writable(__DIR__ . '/../uploads');
if ($out['problems']) $out['ok'] = false;
json_response($out);
