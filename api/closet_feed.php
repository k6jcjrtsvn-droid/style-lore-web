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

$limit = (int)($_GET['limit'] ?? 50);
if ($limit <= 0 || $limit > 200) $limit = 50;

$stmt = $pdo->prepare(
    "SELECT id, account_id, description, photo_data, created_at, visibility, author_name
     FROM closet_items WHERE visibility = 'public' ORDER BY created_at DESC LIMIT $limit"
);
$stmt->execute();
json_response(array_map('closet_item_to_public', $stmt->fetchAll()));
