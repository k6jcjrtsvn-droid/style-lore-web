<?php
/**
 * One-off cleanup for the closet duplication incident of 2026-09-15.
 *
 * CLI only:
 *   /usr/local/bin/php ~/public_html/api/dedupe_closets.php --dry
 *   /usr/local/bin/php ~/public_html/api/dedupe_closets.php
 *
 * What happened: api/closet.php minted a fresh uuidv4() for every row on
 * every sync, so an item's id changed each time a closet was saved. The
 * sign-in merge added on 2026-09-15 matched items on id, so nothing ever
 * matched and every pull appended the whole closet again — doubling it on
 * each visit. One account's two garments reached 31 rows.
 *
 * Both causes are fixed, and the client now de-duplicates itself on load,
 * so any account that opens the app cleans its own mirror on the next sync.
 * This script is for the accounts that DON'T open it — it collapses the
 * rows that are already in the table.
 *
 * Two rows are the same garment when description + photo match, which is
 * the same rule closetItemKey() uses in the client. The OLDEST row of each
 * group is kept (that's the original add, and it carries the true
 * created_at); its visibility is set to 'public' if ANY copy in the group
 * was public, so a cleanup can never quietly un-share something.
 *
 * Nothing is deleted unless a byte-identical garment survives in the same
 * account. Never touches accounts with no duplicates.
 */
require_once __DIR__ . '/../includes/helpers.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$dry = in_array('--dry', $argv ?? [], true);
$pdo = db();

echo ($dry ? "DRY RUN — nothing will be changed\n" : "LIVE RUN — duplicates will be deleted\n");
echo str_repeat('-', 62), "\n";

$rows = $pdo->query(
    'SELECT id, account_id, description, photo_data, created_at, visibility
     FROM closet_items ORDER BY account_id, created_at ASC, id ASC'
)->fetchAll(PDO::FETCH_ASSOC);

echo 'closet_items rows before: ', count($rows), "\n";

// account_id => fingerprint => [rows...]
$groups = [];
foreach ($rows as $r) {
    $desc = preg_replace('/\s+/u', ' ', mb_strtolower(trim((string)$r['description'])));
    $photo = (string)($r['photo_data'] ?? '');
    $fingerprint = sha1($desc . '|' . ($photo === '' ? '' : sha1($photo)));
    // A row with neither a description nor a photo can't be compared to
    // anything — give it a unique fingerprint so it is always kept.
    if ($desc === '' && $photo === '') $fingerprint = 'unique:' . $r['id'];
    $groups[$r['account_id']][$fingerprint][] = $r;
}

$toDelete = [];
$toPromote = [];   // rows whose visibility must become 'public'
$accountsTouched = 0;
$report = [];

foreach ($groups as $accountId => $byFingerprint) {
    $before = 0; $after = 0; $removed = 0;
    foreach ($byFingerprint as $copies) {
        $before += count($copies);
        $after++;
        if (count($copies) === 1) continue;
        $keep = array_shift($copies);          // oldest, thanks to the ORDER BY
        $anyPublic = ($keep['visibility'] === 'public');
        foreach ($copies as $dupe) {
            if ($dupe['visibility'] === 'public') $anyPublic = true;
            $toDelete[] = $dupe['id'];
            $removed++;
        }
        if ($anyPublic && $keep['visibility'] !== 'public') $toPromote[] = $keep['id'];
    }
    if ($removed > 0) {
        $accountsTouched++;
        $report[] = ['account' => $accountId, 'before' => $before, 'after' => $after, 'removed' => $removed];
    }
}

usort($report, fn($a, $b) => $b['removed'] <=> $a['removed']);
foreach ($report as $r) {
    printf("  %s  %4d -> %3d  (-%d)\n", substr($r['account'], 0, 24), $r['before'], $r['after'], $r['removed']);
}

echo str_repeat('-', 62), "\n";
echo 'accounts with duplicates: ', $accountsTouched, "\n";
echo 'rows to delete:           ', count($toDelete), "\n";
echo 'rows to re-mark public:   ', count($toPromote), "\n";

if ($dry) {
    echo "\nDry run — re-run without --dry to apply.\n";
    exit;
}
if (!$toDelete && !$toPromote) {
    echo "\nNothing to do.\n";
    exit;
}

$pdo->beginTransaction();
try {
    // Chunked so a large IN (...) can't blow past placeholder limits.
    foreach (array_chunk($toPromote, 200) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        $pdo->prepare("UPDATE closet_items SET visibility = 'public' WHERE id IN ($in)")->execute($chunk);
    }
    foreach (array_chunk($toDelete, 200) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        $pdo->prepare("DELETE FROM closet_items WHERE id IN ($in)")->execute($chunk);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "\nFAILED, rolled back: ", $e->getMessage(), "\n";
    exit(1);
}

$after = (int)$pdo->query('SELECT COUNT(*) FROM closet_items')->fetchColumn();
echo "\nDone. closet_items rows after: ", $after, "\n";
