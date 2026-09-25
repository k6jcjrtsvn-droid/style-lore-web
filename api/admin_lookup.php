<?php
/**
 * GET /api/admin/lookup?q=<text> — "is this person in here, and how far did
 * they get?" Answers the question Kenneth actually keeps asking, which no
 * other endpoint could: the broadcast endpoint returns counts, the funnel
 * returns aggregates, and neither can tell you whether one named human ever
 * made an account.
 *
 * Authenticates with the same X-Admin-Secret header admin.html already
 * sends (ADMIN_SECRET, falling back to ADMIN_KEY), so the Find tab in
 * admin.html works with the secret already unlocked there.
 *
 * READ-ONLY BY CONSTRUCTION. It runs SELECTs and nothing else. There is no
 * POST handler, no DELETE, no way to change a row from here — if you want
 * to act on what you find, that is a separate, deliberate step somewhere
 * else.
 *
 * WHAT IT DELIBERATELY DOES NOT RETURN. No password hash, no auth token, no
 * verification or reset token, no closet photos, no message content, no IP.
 * Nothing here is a credential and nothing here is private content. It is
 * the shape of an account, not its contents: who, when, verified, platform,
 * premium, and how many things they have made. That is enough to answer a
 * support question or honour a deletion request, and stopping there means
 * an admin page that leaks nothing worth stealing if the secret ever does.
 *
 * Matching is a case-insensitive substring across the account email, the
 * profile name, and the beta sign-up list — "bev", "henegar" and a full
 * address all work. A query under 2 characters is refused rather than
 * returning the whole table by accident.
 *
 * ?segment=quiz-no-post returns a NAMED list instead of a search: everyone
 * who finished the quiz and has never posted. That is the single most
 * actionable list in the product — they wanted a result enough to answer
 * every question, then found nothing worth staying for — and it is the
 * people you would write to by hand. It is a defined segment, deliberately,
 * not a wildcard: there is still no way to ask this endpoint for "everyone".
 */
require_once __DIR__ . '/../includes/helpers.php';

function require_admin_secret_lookup(): void {
    $sent = (string)($_SERVER['HTTP_X_ADMIN_SECRET'] ?? '');
    if ($sent === '') $sent = (string)($_GET['secret'] ?? '');
    $secret = (defined('ADMIN_SECRET') && ADMIN_SECRET !== '') ? ADMIN_SECRET
            : ((defined('ADMIN_KEY') && ADMIN_KEY !== '') ? ADMIN_KEY : '');
    if ($secret === '' || !hash_equals($secret, $sent)) {
        error_response('Not authorized.', 401);
    }
}

require_method('GET');
require_admin_secret_lookup();

/* The named segment. Handled before the search so it needs no query. */
$segment = (string)($_GET['segment'] ?? '');
if ($segment !== '') {
    if ($segment !== 'quiz-no-post') {
        error_response('Unknown segment.', 400);
    }
    $pdo = db();
    $rows = $pdo->query('
      SELECT a.id, a.email, a.created_at, a.email_verified, a.signup_device,
             p.name, p.kibbe_type_name,
             (SELECT COUNT(*) FROM closet_items WHERE account_id = a.id) AS closet
        FROM accounts a
        JOIN profiles p ON p.id = a.id
       WHERE COALESCE(p.kibbe_type_name, "") <> ""
         AND NOT EXISTS (SELECT 1 FROM posts WHERE author_id = a.id)
       ORDER BY p.kibbe_type_name ASC, a.created_at ASC
       LIMIT 200')->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'           => $r['id'],
            'email'        => $r['email'],
            'name'         => (string)($r['name'] ?? ''),
            'kibbeType'    => $r['kibbe_type_name'],
            'createdAt'    => (int)$r['created_at'],
            'verified'     => (int)$r['email_verified'] === 1,
            'signupDevice' => $r['signup_device'] ?: '(unrecorded)',
            'closetItems'  => (int)$r['closet'],
        ];
    }
    json_response([
        'ok'      => true,
        'segment' => $segment,
        'people'  => $out,
        'note'    => 'Finished the quiz, never posted. Ordered by type, oldest account first.',
    ]);
}

$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) {
    error_response('Type at least two characters to search for.', 400);
}
$like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';

$pdo = db();
ensure_digest_columns($pdo);

/* Accounts. LEFT JOINs throughout: someone can have an account and no
 * profile, no subscription row and nothing logged, and that is exactly the
 * case worth seeing. */
$sql = '
  SELECT a.id, a.email, a.created_at, a.email_verified,
         a.signup_device, a.digest_opt_out, a.digest_sent_at, a.survey_sent_at,
         p.name, p.kibbe_type_name,
         COALESCE(s.is_premium, 0) AS is_premium, s.product_id, s.expires_at,
         (SELECT COUNT(*) FROM posts        WHERE author_id  = a.id) AS posts,
         (SELECT COUNT(*) FROM closet_items WHERE account_id = a.id) AS closet
    FROM accounts a
    LEFT JOIN profiles p      ON p.id = a.id
    LEFT JOIN subscriptions s ON s.account_id = a.id
   WHERE a.email LIKE ? OR COALESCE(p.name, "") LIKE ?
   ORDER BY a.created_at DESC
   LIMIT 50';
$stmt = $pdo->prepare($sql);
$stmt->execute([$like, $like]);
$accounts = [];
foreach ($stmt->fetchAll() as $r) {
    $accounts[] = [
        'id'          => $r['id'],
        'email'       => $r['email'],
        'name'        => (string)($r['name'] ?? ''),
        'createdAt'   => (int)$r['created_at'],
        'verified'    => (int)$r['email_verified'] === 1,
        'signupDevice'=> $r['signup_device'] ?: '(unrecorded)',
        'kibbeType'   => $r['kibbe_type_name'] ?: null,
        'isPremium'   => (int)$r['is_premium'] === 1,
        'productId'   => $r['product_id'] ?: null,
        'expiresAt'   => $r['expires_at'] !== null ? (int)$r['expires_at'] : null,
        'posts'       => (int)$r['posts'],
        'closetItems' => (int)$r['closet'],
        'emailOptOut' => (int)($r['digest_opt_out'] ?? 0) === 1,
        'digestSentAt'=> $r['digest_sent_at'] !== null ? (int)$r['digest_sent_at'] : null,
        'surveySentAt'=> $r['survey_sent_at'] !== null ? (int)$r['survey_sent_at'] : null,
    ];
}

/* The beta sign-up list is a different thing from an account, and conflating
 * the two is how you end up telling someone they signed up when they only
 * asked for an invite. Reported separately, always. */
$betas = [];
try {
    $bs = $pdo->prepare('SELECT email, name, status, created_at, synced_at, sent_at
                           FROM beta_testers
                          WHERE email LIKE ? OR name LIKE ?
                          ORDER BY created_at DESC LIMIT 50');
    $bs->execute([$like, $like]);
    foreach ($bs->fetchAll() as $r) {
        $betas[] = [
            'email'     => $r['email'],
            'name'      => (string)($r['name'] ?? ''),
            'status'    => $r['status'],
            'createdAt' => (int)$r['created_at'],
            'syncedAt'  => $r['synced_at'] !== null ? (int)$r['synced_at'] : null,
            'sentAt'    => $r['sent_at'] !== null ? (int)$r['sent_at'] : null,
        ];
    }
} catch (PDOException $e) {
    // The table may not exist on an older deploy. Not a reason to fail the
    // account search, which is the part that matters.
    error_log('admin_lookup: beta_testers unavailable: ' . $e->getMessage());
}

$totalAccounts = (int)$pdo->query('SELECT COUNT(*) c FROM accounts')->fetch()['c'];

json_response([
    'ok'            => true,
    'query'         => $q,
    'accounts'      => $accounts,
    'betaSignups'   => $betas,
    'totalAccounts' => $totalAccounts,
    'note'          => 'Accounts and beta sign-ups are separate lists. A beta sign-up asked for an invite; it is not an account.',
]);
