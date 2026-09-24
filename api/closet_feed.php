<?php
/**
 * GET /api/closet/feed?limit=N — the community-wide public closet feed:
 * Public closet items across ALL accounts, newest first. This is what
 * powers Community's "Closets" tab — anything an account marks Public
 * shows up here for everybody, not just on that account's own profile
 * page (see Kenneth's 2026-09-14 feedback: "if it's assigned to public,
 * then everybody in the community needs to see that").
 */
require_once __DIR__ . '/../includes/helpers.php';
require_method('GET');

$pdo = db();
ensure_closet_columns($pdo);
ensure_block_schema($pdo);

$limit = (int)($_GET['limit'] ?? 50);
if ($limit <= 0 || $limit > 200) $limit = 50;

// Unauthenticated, like the rest of this endpoint — viewerId only decides
// whose blocked accounts to leave out, and the feed is public either way.
$viewerId = isset($_GET['viewerId']) ? (string)$_GET['viewerId'] : null;
$blocked = blocked_ids($pdo, $viewerId);
$blockSql = blocked_filter_sql($blocked, 'account_id');

$stmt = $pdo->prepare(
    "SELECT id, account_id, client_id, description, photo_data, created_at, visibility, author_name
     FROM closet_items
     WHERE visibility = 'public' AND deleted_at IS NULL" . $blockSql . "
     ORDER BY created_at DESC LIMIT $limit"
);
$stmt->execute($blocked);
json_response(array_map(function ($r) use ($pdo) { return closet_item_to_public($r, $pdo); }, $stmt->fetchAll()));
