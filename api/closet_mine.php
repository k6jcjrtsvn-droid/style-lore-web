<?php
/**
 * GET /api/closet/mine?visitorId=X&authToken=Y — the signed-in owner's OWN
 * closet, every item, Hidden ones included, photos and all.
 *
 * Why this exists separately from api/closet.php's GET: that one is the
 * public mirror (visibility = 'public' only) and is deliberately identical
 * whether the caller is the owner or a stranger, so the owner's private
 * items never leak through a profile page. This endpoint is the other half
 * — it is authenticated, it is only ever about the caller's own account,
 * and it is what lets a closet built on the phone reappear when the same
 * person signs in on the website (or a new phone). Before this, the closet
 * lived only in that one device's localStorage and was silently lost on
 * every other device.
 *
 * Ordering matters on the client: this must be fetched and merged BEFORE
 * the first api/closet POST of a session, because that POST is a wholesale
 * replace (DELETE then re-INSERT). A fresh browser with an empty local
 * closet that uploads first would wipe the account's real closet.
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

$stmt = $pdo->prepare(
    "SELECT id, account_id, description, photo_data, created_at, visibility, author_name
     FROM closet_items WHERE account_id = ? ORDER BY created_at DESC"
);
$stmt->execute([$visitorId]);

// Never cache a private closet in a shared/browser cache.
header('Cache-Control: private, no-store');
json_response(array_map('closet_item_to_public', $stmt->fetchAll()));
