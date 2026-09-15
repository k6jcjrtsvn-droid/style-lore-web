<?php
/**
 * GET /api/closet/mine?visitorId=X&authToken=Y[&since=MS] — the signed-in
 * owner's OWN closet: every live item, Hidden ones included, photos and all,
 * plus the tombstones of anything they've deleted.
 *
 * Returns {items: [...], deleted: [{id, deletedAt}], serverTime}.
 * (Before syncVersion 2 this returned a bare array. The client accepts both,
 * because app 1.3/1.4 bundle their own frontend and can't be updated from
 * here — see the shape check in Api.getMyCloset.)
 *
 * The tombstones are the point. Without them, deleting an item on your phone
 * and then opening the website — whose local copy still has it — had the
 * website hand the item straight back and sync it up again. Telling every
 * device what was deleted is what makes a removal stick.
 *
 * `since` trims both lists to what changed after that timestamp, so a device
 * that syncs often doesn't re-download every photo it already has. Omit it
 * for a full copy (what a brand-new device wants).
 *
 * Why this is separate from api/closet.php's GET: that one is the public
 * mirror — visibility = 'public' only, identical for the owner and for a
 * stranger, so private items can never leak through a profile page. This one
 * is authenticated and only ever about the caller's own account.
 */
require_once __DIR__ . '/../includes/helpers.php';

require_method('GET');

$visitorId = (string)($_GET['visitorId'] ?? '');
$authToken = (string)($_GET['authToken'] ?? '');
if ($visitorId === '' || $authToken === '') {
    error_response('Sign in to load your closet.', 401);
}

$pdo = db();
require_owner($pdo, $visitorId, $authToken);
ensure_closet_columns($pdo);

$since = isset($_GET['since']) ? (int)$_GET['since'] : 0;
if ($since < 0) $since = 0;

$itemSql =
    'SELECT id, account_id, client_id, description, photo_data, created_at, updated_at, visibility, author_name
       FROM closet_items
      WHERE account_id = ? AND deleted_at IS NULL';
$params = [$visitorId];
if ($since > 0) { $itemSql .= ' AND COALESCE(updated_at, created_at) > ?'; $params[] = $since; }
$itemSql .= ' ORDER BY created_at DESC';

$stmt = $pdo->prepare($itemSql);
$stmt->execute($params);
$items = array_map('closet_item_to_public', $stmt->fetchAll());

$delSql = 'SELECT client_id, id, deleted_at FROM closet_items WHERE account_id = ? AND deleted_at IS NOT NULL';
$delParams = [$visitorId];
if ($since > 0) { $delSql .= ' AND deleted_at > ?'; $delParams[] = $since; }
$delStmt = $pdo->prepare($delSql);
$delStmt->execute($delParams);
$deleted = [];
foreach ($delStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $deleted[] = [
        'id' => (string)($row['client_id'] ?? '') !== '' ? $row['client_id'] : $row['id'],
        'deletedAt' => (int)$row['deleted_at'],
    ];
}

// Never cache a private closet in a shared/browser cache.
header('Cache-Control: private, no-store');
json_response([
    'items' => $items,
    'deleted' => $deleted,
    'serverTime' => current_time_ms(),
]);
