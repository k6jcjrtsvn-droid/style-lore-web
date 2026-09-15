<?php
/**
 * GET  /api/closet?ownerId=X — returns a bare array of this account's
 *   Public closet items (newest first). Hidden items are never returned
 *   here, even to the owner themself — the owner manages their full closet
 *   (Public and Hidden) entirely client-side in local storage; this
 *   endpoint only mirrors what's visible to other people, so it stays
 *   consistent whether the caller is the owner's own profile page or
 *   someone else's.
 *
 * POST /api/closet {visitorId, visitorName, authToken, items:[{id,
 *   description, photo, createdAt, visibility}, ...]} — wholesale replace
 *   of this account's closet mirror. The client's local closet array (see
 *   App()'s "closet" state) is the source of truth, including each item's
 *   own visibility flag; this endpoint just mirrors it server-side,
 *   per-item, so Public items can be shown on the owner's profile and in
 *   Community's Closets feed. visitorName is denormalized onto every row
 *   (author_name) so the community-wide feed (api/closet_feed.php) can
 *   show who an item belongs to without joining back to profiles for
 *   every account that's ever synced a closet.
 */
require_once __DIR__ . '/../includes/helpers.php';

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $ownerId = (string)($_GET['ownerId'] ?? '');
    if ($ownerId === '') error_response('Missing ownerId.', 400);

    $stmt = $pdo->prepare(
        "SELECT id, account_id, description, photo_data, created_at, visibility, author_name
         FROM closet_items WHERE account_id = ? AND visibility = 'public' ORDER BY created_at DESC"
    );
    $stmt->execute([$ownerId]);
    json_response(array_map('closet_item_to_public', $stmt->fetchAll()));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = request_json();
    $visitorId = (string)($body['visitorId'] ?? '');
    require_owner($pdo, $visitorId, (string)($body['authToken'] ?? ''));

    $visitorName = mb_substr(trim((string)($body['visitorName'] ?? '')), 0, 60);

    $items = is_array($body['items'] ?? null) ? $body['items'] : [];
    // Cap it — this is a mirror of a personal capsule wardrobe, not
    // unbounded storage, and keeps one sync call bounded in size.
    $items = array_slice($items, 0, 300);

    $usedIds = [];
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM closet_items WHERE account_id = ?')->execute([$visitorId]);
        $ins = $pdo->prepare(
            'INSERT INTO closet_items (id, account_id, description, photo_data, created_at, visibility, author_name) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        // De-duplicate within the payload itself: same description + same
        // photo is the same garment, whatever ids the client attached. A
        // client that arrives already duplicated is cleaned by syncing.
        $seen = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $description = mb_substr(trim((string)($item['description'] ?? '')), 0, 200);
            $photo = isset($item['photo']) && is_string($item['photo']) ? $item['photo'] : null;
            $createdAt = isset($item['createdAt']) ? (int)$item['createdAt'] : current_time_ms();
            $visibility = ($item['visibility'] ?? '') === 'public' ? 'public' : 'hidden';

            $fingerprint = sha1(mb_strtolower($description) . '|' . ($photo === null ? '' : sha1($photo)));
            if (isset($seen[$fingerprint])) continue;
            $seen[$fingerprint] = true;

            // Keep the client's own id when it sent one. Minting a fresh
            // uuidv4() on every sync — as this did until 2026-09-15 — meant
            // an item's id changed every time the closet was saved, so the
            // client could never recognise its own items coming back from
            // /api/closet/mine and appended the whole closet again on every
            // sign-in. That doubled closets on each pull (Jayne's two items
            // reached 31 rows). Ids are client-generated, so validate the
            // shape and scope uniqueness to this account.
            $id = (string)($item['id'] ?? '');
            if ($id === '' || strlen($id) > 64 || !preg_match('/^[A-Za-z0-9_-]+$/', $id) || isset($usedIds[$id])) {
                $id = uuidv4();
            }
            $usedIds[$id] = true;

            $ins->execute([$id, $visitorId, $description, $photo, $createdAt, $visibility, $visitorName]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    json_response(['ok' => true, 'count' => count($seen)]);
}

error_response('Method not allowed.', 405);
