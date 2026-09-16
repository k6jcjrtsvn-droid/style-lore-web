<?php
/**
 * Saved outfits — combinations of closet pieces, optionally scheduled to a day.
 *
 * GET  /api/outfits?visitorId=X&authToken=Y
 *        → {outfits:[{id, name, itemIds, wearDate, createdAt, updatedAt}],
 *           deleted:[{id, deletedAt}], serverTime}
 *
 * POST /api/outfits
 *        {visitorId, authToken, syncVersion: 2,
 *         outfits: [{id, name, itemIds, wearDate, createdAt, updatedAt}],
 *         deleted: [{id, deletedAt}]}
 *
 * The sync protocol is deliberately the same shape as api/closet.php's
 * syncVersion 2, and for the same reason: upsert on (account_id, client_id),
 * last-write-wins on updated_at, soft-delete what the client says it removed,
 * and LEAVE ALONE anything the payload doesn't mention. The closet learned
 * that lesson expensively on 2026-09-15 (a full-mirror sync let an empty
 * device wipe a full closet); outfits start out knowing it.
 *
 * Outfits are private. There is no public read of this table at all — no
 * ownerId GET, nothing in the community feed — so unlike closet items there
 * is no visibility flag to get wrong.
 *
 * `itemIds` are CLIENT item ids (the same ids api/closet_mine.php hands back),
 * not server row ids. They are stored as a JSON array and deliberately NOT
 * foreign-keyed: an outfit referencing a piece you've since removed is not a
 * database error, it is just an outfit with a missing piece, and the client
 * renders it as such rather than losing the whole outfit.
 */
require_once __DIR__ . '/../includes/helpers.php';

$pdo = db();
ensure_outfits_table($pdo);

$isGet = $_SERVER['REQUEST_METHOD'] === 'GET';
$body = $isGet ? [] : request_json();
$visitorId = (string)($isGet ? ($_GET['visitorId'] ?? '') : ($body['visitorId'] ?? ''));
$authToken = (string)($isGet ? ($_GET['authToken'] ?? '') : ($body['authToken'] ?? ''));
require_owner($pdo, $visitorId, $authToken);

if ($isGet) {
    $stmt = $pdo->prepare(
        'SELECT client_id, name, item_ids, wear_date, created_at, updated_at
           FROM outfits WHERE account_id = ? AND deleted_at IS NULL
          ORDER BY created_at DESC'
    );
    $stmt->execute([$visitorId]);
    $outfits = array_map('outfit_to_public', $stmt->fetchAll(PDO::FETCH_ASSOC));

    $del = $pdo->prepare('SELECT client_id, deleted_at FROM outfits WHERE account_id = ? AND deleted_at IS NOT NULL');
    $del->execute([$visitorId]);
    $deleted = [];
    foreach ($del->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $deleted[] = ['id' => (string)$row['client_id'], 'deletedAt' => (int)$row['deleted_at']];
    }

    header('Cache-Control: private, no-store');
    json_response(['outfits' => $outfits, 'deleted' => $deleted, 'serverTime' => current_time_ms()]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') error_response('Method not allowed.', 405);

$now = current_time_ms();
// A wardrobe's worth of outfits, not unbounded storage — and it keeps one
// sync call small (these rows carry no photos, only ids).
$incomingRaw = is_array($body['outfits'] ?? null) ? array_slice($body['outfits'], 0, 200) : [];
$deletedRaw  = is_array($body['deleted'] ?? null) ? array_slice($body['deleted'], 0, 200) : [];

$incoming = [];
foreach ($incomingRaw as $raw) {
    if (!is_array($raw)) continue;
    $clientId = (string)($raw['id'] ?? '');
    if ($clientId === '' || strlen($clientId) > 64 || !preg_match('/^[A-Za-z0-9_-]+$/', $clientId)) continue;

    $itemIds = [];
    if (is_array($raw['itemIds'] ?? null)) {
        foreach (array_slice($raw['itemIds'], 0, 24) as $id) {
            $id = (string)$id;
            if ($id !== '' && strlen($id) <= 64 && preg_match('/^[A-Za-z0-9_-]+$/', $id)) $itemIds[] = $id;
        }
    }
    $itemIds = array_values(array_unique($itemIds));

    // A plain calendar day, or nothing. Anything else is dropped rather than
    // stored, so the planner can never be handed a date it can't parse.
    $wearDate = null;
    $d = (string)($raw['wearDate'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) $wearDate = $d;

    $createdAt = isset($raw['createdAt']) ? (int)$raw['createdAt'] : $now;
    $incoming[$clientId] = [
        'client_id'  => $clientId,
        'name'       => mb_substr(trim((string)($raw['name'] ?? '')), 0, 80),
        'item_ids'   => json_encode($itemIds),
        'wear_date'  => $wearDate,
        'created_at' => $createdAt,
        'updated_at' => isset($raw['updatedAt']) ? (int)$raw['updatedAt'] : $createdAt,
    ];
}

$stats = ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'deleted' => 0];

$pdo->beginTransaction();
try {
    // Tombstoned rows included: a re-created outfit must update its row in
    // place rather than collide with the unique key.
    $existingStmt = $pdo->prepare('SELECT id, client_id, updated_at, created_at, deleted_at FROM outfits WHERE account_id = ?');
    $existingStmt->execute([$visitorId]);
    $existing = [];
    foreach ($existingStmt->fetchAll(PDO::FETCH_ASSOC) as $row) $existing[(string)$row['client_id']] = $row;

    $insert = $pdo->prepare(
        'INSERT INTO outfits (id, account_id, client_id, name, item_ids, wear_date, created_at, updated_at, deleted_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL)'
    );
    $update = $pdo->prepare(
        'UPDATE outfits SET name = ?, item_ids = ?, wear_date = ?, created_at = ?, updated_at = ?, deleted_at = NULL
          WHERE id = ?'
    );

    foreach ($incoming as $clientId => $n) {
        $row = $existing[$clientId] ?? null;
        if ($row === null) {
            $insert->execute([uuidv4(), $visitorId, $clientId, $n['name'], $n['item_ids'], $n['wear_date'], $n['created_at'], $n['updated_at']]);
            $stats['inserted']++;
            continue;
        }
        // Equal timestamps still write, so a client that doesn't track
        // updated_at can still correct a row.
        if ((int)$n['updated_at'] < (int)($row['updated_at'] ?? $row['created_at'])) { $stats['skipped']++; continue; }
        $update->execute([$n['name'], $n['item_ids'], $n['wear_date'], $n['created_at'], $n['updated_at'], $row['id']]);
        $stats['updated']++;
    }

    $tombstone = $pdo->prepare('UPDATE outfits SET deleted_at = ?, updated_at = ? WHERE id = ? AND deleted_at IS NULL');
    foreach ($deletedRaw as $d) {
        $clientId = is_array($d) ? (string)($d['id'] ?? '') : (string)$d;
        if ($clientId === '' || isset($incoming[$clientId])) continue;   // re-created wins
        $row = $existing[$clientId] ?? null;
        if ($row === null || $row['deleted_at'] !== null) continue;
        $at = (is_array($d) && isset($d['deletedAt'])) ? (int)$d['deletedAt'] : $now;
        $tombstone->execute([$at, $at, $row['id']]);
        $stats['deleted']++;
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

$live = $pdo->prepare('SELECT COUNT(*) FROM outfits WHERE account_id = ? AND deleted_at IS NULL');
$live->execute([$visitorId]);
json_response(['ok' => true, 'count' => (int)$live->fetchColumn(), 'syncVersion' => 2, 'stats' => $stats]);
