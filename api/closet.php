<?php
/**
 * GET  /api/closet?ownerId=X&viewerId=Y — returns { visibility, items }.
 *   items is only populated when the owner's closet is public, OR the
 *   requester is asking about their own closet (viewerId === ownerId).
 *   viewerId is not proof of identity (no authToken here) — it doesn't
 *   need to be: ownerId === viewerId is only ever true when someone is
 *   asking about themselves, and that's not privileged information.
 *
 * POST /api/closet {visitorId, authToken, items:[{id, description, photo,
 *   createdAt}, ...]} — wholesale replace of this account's closet mirror.
 *   The client's local closet array (see App()'s "closet" state) is the
 *   source of truth; this endpoint just mirrors it server-side so it can
 *   be shown on the profile when closet_visibility is 'public'. Simpler
 *   and more robust than per-item add/remove endpoints given the client
 *   already keeps the authoritative array locally.
 */
require_once __DIR__ . '/../includes/helpers.php';

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $ownerId = (string)($_GET['ownerId'] ?? '');
    $viewerId = (string)($_GET['viewerId'] ?? '');
    if ($ownerId === '') error_response('Missing ownerId.', 400);

    $visStmt = $pdo->prepare('SELECT closet_visibility FROM profiles WHERE id = ?');
    $visStmt->execute([$ownerId]);
    $visRow = $visStmt->fetch();
    $visibility = $visRow ? $visRow['closet_visibility'] : 'hidden';

    $isOwner = $viewerId !== '' && $viewerId === $ownerId;
    if ($visibility !== 'public' && !$isOwner) {
        json_response(['visibility' => $visibility, 'items' => []]);
    }

    $stmt = $pdo->prepare('SELECT id, description, photo_data, created_at FROM closet_items WHERE account_id = ? ORDER BY created_at DESC');
    $stmt->execute([$ownerId]);
    json_response(['visibility' => $visibility, 'items' => array_map('closet_item_to_public', $stmt->fetchAll())]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = request_json();
    $visitorId = (string)($body['visitorId'] ?? '');
    require_owner($pdo, $visitorId, (string)($body['authToken'] ?? ''));

    $items = is_array($body['items'] ?? null) ? $body['items'] : [];
    // Cap it — this is a mirror of a personal capsule wardrobe, not
    // unbounded storage, and keeps one sync call bounded in size.
    $items = array_slice($items, 0, 300);

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM closet_items WHERE account_id = ?')->execute([$visitorId]);
        $ins = $pdo->prepare(
            'INSERT INTO closet_items (id, account_id, description, photo_data, created_at) VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $description = mb_substr(trim((string)($item['description'] ?? '')), 0, 200);
            $photo = isset($item['photo']) && is_string($item['photo']) ? $item['photo'] : null;
            $createdAt = isset($item['createdAt']) ? (int)$item['createdAt'] : current_time_ms();
            $ins->execute([uuidv4(), $visitorId, $description, $photo, $createdAt]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    json_response(['ok' => true, 'count' => count($items)]);
}

error_response('Method not allowed.', 405);
