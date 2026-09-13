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

    // Name comes from the profiles table, never from the client.
    $visitorName = profile_identity($pdo, $visitorId)['name'];

    // Free accounts are capped at CLOSET_FREE_LIMIT items; Style-LORE
    // Premium (has_premium()) gets the full 300-item sync ceiling. This is
    // a server-side backstop -- the frontend should also stop letting a
    // free account add past the limit client-side, using the
    // closetFreeLimit/isPremium this endpoint (and api/subscription_status.php)
    // report back, rather than relying on a silent server-side truncation.
    $isPremium = has_premium($pdo, $visitorId);
    $maxItems = $isPremium ? 300 : CLOSET_FREE_LIMIT;

    $items = is_array($body['items'] ?? null) ? $body['items'] : [];
    $items = array_slice($items, 0, $maxItems);

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM closet_items WHERE account_id = ?')->execute([$visitorId]);
        $ins = $pdo->prepare(
            'INSERT INTO closet_items (id, account_id, description, photo_data, created_at, visibility, author_name) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $description = mb_substr(trim((string)($item['description'] ?? '')), 0, 200);
            // Photos are inline data: URLs produced by the app's canvas
            // resize. Only accept a real base64 image, capped at 2MB, since
            // this string is rendered as an <img src> in other people's
            // closet feeds.
            $photo = isset($item['photo']) && is_string($item['photo']) ? $item['photo'] : null;
            if ($photo !== null && (strlen($photo) > 2 * 1024 * 1024
                || !preg_match('#^data:image/(jpeg|png|webp|gif);base64,[A-Za-z0-9+/]+=*$#', $photo))) {
                $photo = null;
            }
            $createdAt = isset($item['createdAt']) ? (int)$item['createdAt'] : current_time_ms();
            $visibility = ($item['visibility'] ?? '') === 'public' ? 'public' : 'hidden';
            $ins->execute([uuidv4(), $visitorId, $description, $photo, $createdAt, $visibility, $visitorName]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    json_response(['ok' => true, 'count' => count($items), 'limit' => $maxItems, 'isPremium' => $isPremium]);
}

error_response('Method not allowed.', 405);
