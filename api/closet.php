<?php
/**
 * GET  /api/closet?ownerId=X — a bare array of this account's Public closet
 *   items (newest first), excluding deleted ones. Hidden items are never
 *   returned here, even to the owner: the owner reads their own full closet
 *   from api/closet_mine.php, and this endpoint stays identical whether the
 *   caller is the owner's own profile page or a stranger's view of it.
 *
 * POST /api/closet — sync. TWO protocols live here:
 *
 *   syncVersion 2 (current)
 *     {visitorId, authToken, visitorName, syncVersion: 2,
 *      items:   [{id, description, photo, createdAt, updatedAt, visibility}],
 *      deleted: [{id, deletedAt}]}
 *     Upsert on (account_id, client_id), last-write-wins by updatedAt, and
 *     soft-delete whatever the client says it removed. Items the payload
 *     doesn't mention are LEFT ALONE — that's the whole point. Two devices
 *     can each hold a partial closet and converge instead of clobbering.
 *
 *   legacy (no syncVersion — app 1.3/1.4, which bundle their own copy of the
 *   frontend and can't be updated from the server)
 *     {visitorId, authToken, visitorName, items:[...]}
 *     Keeps the old "this list is the whole closet" meaning, because that's
 *     what those clients believe. Anything absent is soft-deleted rather
 *     than hard-deleted, so a stale device can no longer destroy data —
 *     only hide it, recoverably, until a v2 client resurrects or confirms.
 *
 * History: until 2026-09-15 this endpoint DELETEd every row for the account
 * and re-INSERTed the payload with a fresh uuidv4() per row. Three separate
 * data bugs came out of that in one evening — closets duplicating on every
 * sign-in, an empty client wiping a full closet, and removals appearing not
 * to work. The table now has a real per-item identity (see
 * ensure_closet_columns) and this endpoint no longer destroys anything.
 */
require_once __DIR__ . '/../includes/helpers.php';

$pdo = db();
ensure_closet_columns($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $ownerId = (string)($_GET['ownerId'] ?? '');
    if ($ownerId === '') error_response('Missing ownerId.', 400);

    $stmt = $pdo->prepare(
        "SELECT id, account_id, client_id, description, photo_data, created_at, visibility, author_name
         FROM closet_items
         WHERE account_id = ? AND visibility = 'public' AND deleted_at IS NULL
         ORDER BY created_at DESC"
    );
    $stmt->execute([$ownerId]);
    json_response(array_map('closet_item_to_public', $stmt->fetchAll()));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_response('Method not allowed.', 405);
}

$body = request_json();
$visitorId = (string)($body['visitorId'] ?? '');
require_owner($pdo, $visitorId, (string)($body['authToken'] ?? ''));

$visitorName = mb_substr(trim((string)($body['visitorName'] ?? '')), 0, 60);
$syncVersion = (int)($body['syncVersion'] ?? 1);
$now = current_time_ms();

$items = is_array($body['items'] ?? null) ? $body['items'] : [];
// Cap it — a personal capsule wardrobe, not unbounded storage, and it keeps
// one sync call bounded in size.
$items = array_slice($items, 0, 300);
$deleted = is_array($body['deleted'] ?? null) ? array_slice($body['deleted'], 0, 300) : [];

/** Normalise one incoming item, or null if it isn't usable. */
$normalise = function ($item) use ($now) {
    if (!is_array($item)) return null;
    $clientId = (string)($item['id'] ?? '');
    if ($clientId === '' || strlen($clientId) > 64 || !preg_match('/^[A-Za-z0-9_-]+$/', $clientId)) return null;
    $description = mb_substr(trim((string)($item['description'] ?? '')), 0, 200);
    $photo = isset($item['photo']) && is_string($item['photo']) ? $item['photo'] : null;
    $createdAt = isset($item['createdAt']) ? (int)$item['createdAt'] : $now;
    $updatedAt = isset($item['updatedAt']) ? (int)$item['updatedAt'] : $createdAt;
    return [
        'client_id'   => $clientId,
        'description' => $description,
        'photo'       => $photo,
        'created_at'  => $createdAt,
        'updated_at'  => $updatedAt,
        'visibility'  => ($item['visibility'] ?? '') === 'public' ? 'public' : 'hidden',
        'fingerprint' => closet_fingerprint($description, $photo),
    ];
};

$incoming = [];
$seenFingerprints = [];
foreach ($items as $raw) {
    $n = $normalise($raw);
    if ($n === null) continue;
    // Within a single payload the same garment twice is a client-side
    // duplicate; keep the first and drop the rest.
    if (isset($seenFingerprints[$n['fingerprint']])) continue;
    $seenFingerprints[$n['fingerprint']] = $n['client_id'];
    $incoming[$n['client_id']] = $n;
}

$stats = ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'deleted' => 0, 'adopted' => 0];

$pdo->beginTransaction();
try {
    // Everything currently on file for this account, including tombstones —
    // a tombstoned row must be recognised so a re-add updates it in place
    // rather than colliding with the unique key.
    $existingStmt = $pdo->prepare(
        'SELECT id, client_id, description, photo_data, created_at, updated_at, visibility, deleted_at
         FROM closet_items WHERE account_id = ?'
    );
    $existingStmt->execute([$visitorId]);

    $byClientId = [];
    $byFingerprint = [];
    foreach ($existingStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['fingerprint'] = closet_fingerprint((string)$row['description'], $row['photo_data']);
        $byClientId[(string)$row['client_id']] = $row;
        // First writer wins the fingerprint slot; ties are resolved by
        // whichever row the unique key already accepted.
        if (!isset($byFingerprint[$row['fingerprint']])) $byFingerprint[$row['fingerprint']] = $row;
    }

    $insert = $pdo->prepare(
        'INSERT INTO closet_items
           (id, account_id, client_id, description, photo_data, created_at, updated_at, visibility, author_name, deleted_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)'
    );
    $update = $pdo->prepare(
        'UPDATE closet_items
            SET description = ?, photo_data = ?, created_at = ?, updated_at = ?, visibility = ?,
                author_name = ?, deleted_at = NULL
          WHERE id = ?'
    );
    // Adoption: an old row whose client_id was backfilled from the server id
    // can never be matched by a real device, so the first v2 sync claims it
    // by content instead of inserting a second copy of the same garment.
    $adopt = $pdo->prepare(
        'UPDATE closet_items
            SET client_id = ?, description = ?, photo_data = ?, created_at = ?, updated_at = ?,
                visibility = ?, author_name = ?, deleted_at = NULL
          WHERE id = ?'
    );

    foreach ($incoming as $clientId => $n) {
        $existing = $byClientId[$clientId] ?? null;

        if ($existing === null && isset($byFingerprint[$n['fingerprint']])) {
            $candidate = $byFingerprint[$n['fingerprint']];
            // Only adopt a row no incoming item claims by id, or we'd steal
            // a row that legitimately belongs to another item.
            if (!isset($incoming[(string)$candidate['client_id']])) {
                $adopt->execute([
                    $clientId, $n['description'], $n['photo'], $n['created_at'], $n['updated_at'],
                    $n['visibility'], $visitorName, $candidate['id'],
                ]);
                unset($byFingerprint[$n['fingerprint']]);
                $byClientId[$clientId] = $candidate;
                $stats['adopted']++;
                continue;
            }
        }

        if ($existing === null) {
            $insert->execute([
                uuidv4(), $visitorId, $clientId, $n['description'], $n['photo'],
                $n['created_at'], $n['updated_at'], $n['visibility'], $visitorName,
            ]);
            $stats['inserted']++;
            continue;
        }

        // Last-write-wins. An equal timestamp still writes, so a client that
        // doesn't track updated_at (legacy) can still correct a row.
        $theirs = (int)$n['updated_at'];
        $ours = (int)($existing['updated_at'] ?? $existing['created_at']);
        if ($theirs < $ours) { $stats['skipped']++; continue; }

        $update->execute([
            $n['description'], $n['photo'], $n['created_at'], $n['updated_at'],
            $n['visibility'], $visitorName, $existing['id'],
        ]);
        $stats['updated']++;
    }

    // ---- deletions ----------------------------------------------------
    $tombstone = $pdo->prepare(
        // photo_data is dropped with the tombstone: it's the only large
        // column, and a deleted item never needs to render again.
        'UPDATE closet_items SET deleted_at = ?, updated_at = ?, photo_data = NULL
          WHERE id = ? AND deleted_at IS NULL'
    );

    if ($syncVersion >= 2) {
        foreach ($deleted as $d) {
            $clientId = is_array($d) ? (string)($d['id'] ?? '') : (string)$d;
            if ($clientId === '' || isset($incoming[$clientId])) continue; // re-added wins
            $row = $byClientId[$clientId] ?? null;
            if ($row === null || $row['deleted_at'] !== null) continue;
            $deletedAt = (is_array($d) && isset($d['deletedAt'])) ? (int)$d['deletedAt'] : $now;
            $tombstone->execute([$deletedAt, $deletedAt, $row['id']]);
            $stats['deleted']++;
        }
    } else {
        // Legacy client: its list is the whole closet, so anything missing
        // from it was removed on that device. Soft-delete rather than DROP,
        // so this is recoverable and a v2 device can still win with a newer
        // updated_at.
        foreach ($byClientId as $clientId => $row) {
            if (isset($incoming[$clientId]) || $row['deleted_at'] !== null) continue;
            $tombstone->execute([$now, $now, $row['id']]);
            $stats['deleted']++;
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

$live = $pdo->prepare('SELECT COUNT(*) FROM closet_items WHERE account_id = ? AND deleted_at IS NULL');
$live->execute([$visitorId]);

json_response([
    'ok' => true,
    'count' => (int)$live->fetchColumn(),
    'syncVersion' => $syncVersion,
    'stats' => $stats,
]);
