<?php
/**
 * Styling tips on someone else's public closet item.
 *
 * GET  /api/closet/tips?ownerId=X&itemId=Y
 *        → {tips:[{id, authorId, authorName, authorAvatarUrl, text, createdAt}]}
 *
 * POST /api/closet/tips
 *        {visitorId, authToken, ownerId, itemId, text}
 *
 * The point of this is that advice becomes content. A private message helps
 * one person; a tip under the item is there for everyone who scrolls past it
 * afterwards, which is what a quiet community actually needs.
 *
 * Only items the owner has explicitly marked Public can be tipped, checked
 * against the row itself on every write — not inferred from the fact that
 * the client had the id, which someone could have kept from before the item
 * was made private.
 *
 * Identity note: a closet item's public id is its `client_id`, which is only
 * unique WITHIN an account (two people's devices can mint the same one). So
 * a tip is keyed on the pair (owner_id, item_id), never on item_id alone.
 * Getting that wrong would attach one person's advice to another person's
 * coat.
 */
require_once __DIR__ . '/../includes/helpers.php';

$pdo = db();
ensure_closet_columns($pdo);
ensure_closet_tips_table($pdo);

/** The live public row for (owner, client item id), or null. */
function closet_tips_find_item(PDO $pdo, string $ownerId, string $itemId): ?array {
    $st = $pdo->prepare(
        "SELECT id, account_id, client_id, description, visibility, deleted_at
           FROM closet_items
          WHERE account_id = ? AND client_id = ? AND visibility = 'public' AND deleted_at IS NULL
          LIMIT 1"
    );
    $st->execute([$ownerId, $itemId]);
    $row = $st->fetch();
    return $row ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $ownerId = (string)($_GET['ownerId'] ?? '');
    $itemId  = (string)($_GET['itemId'] ?? '');
    if ($ownerId === '' || $itemId === '') error_response('Missing item.', 400);

    $st = $pdo->prepare(
        'SELECT id, author_id, author_name, author_avatar_url, text, created_at
           FROM closet_item_tips
          WHERE owner_id = ? AND item_id = ? AND hidden = 0
          ORDER BY created_at ASC LIMIT 100'
    );
    $st->execute([$ownerId, $itemId]);
    $tips = array_map(fn($r) => [
        'id' => $r['id'],
        'authorId' => $r['author_id'],
        'authorName' => $r['author_name'],
        'authorAvatarUrl' => $r['author_avatar_url'],
        'text' => $r['text'],
        'createdAt' => (int)$r['created_at'],
    ], $st->fetchAll(PDO::FETCH_ASSOC));

    json_response(['tips' => $tips]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') error_response('Method not allowed.', 405);

$body = request_json();
$visitorId = (string)($body['visitorId'] ?? '');
$ownerId   = (string)($body['ownerId'] ?? '');
$itemId    = (string)($body['itemId'] ?? '');
$text      = mb_substr(trim((string)($body['text'] ?? '')), 0, 280);

if ($visitorId === '') error_response('Sign in to leave a tip.', 401);
if ($ownerId === '' || $itemId === '') error_response('Missing item.', 400);
if ($text === '') error_response('Write a suggestion first.', 400);

require_owner($pdo, $visitorId, (string)($body['authToken'] ?? ''));

// Same bar as posting in Community: a verified address. Advice from an
// unverified throwaway is exactly what would poison this.
$vst = $pdo->prepare('SELECT email_verified FROM accounts WHERE id = ?');
$vst->execute([$visitorId]);
$v = $vst->fetch();
if (!$v || !$v['email_verified']) {
    error_response('Verify your email before leaving tips — check your inbox, or resend the link from Home.', 403);
}

rate_limit($pdo, 'closet_tip:' . $visitorId, 30, 86400,
    "That's a lot of tips for one day — come back tomorrow.");

$item = closet_tips_find_item($pdo, $ownerId, $itemId);
if (!$item) error_response('That item is no longer shared.', 404);

$profStmt = $pdo->prepare('SELECT name, avatar_url FROM profiles WHERE id = ?');
$profStmt->execute([$visitorId]);
$prof = $profStmt->fetch();
$authorName = ($prof && $prof['name']) ? $prof['name'] : 'Someone';
$authorAvatar = $prof ? $prof['avatar_url'] : null;

$now = current_time_ms();
$ins = $pdo->prepare(
    'INSERT INTO closet_item_tips (id, owner_id, item_id, author_id, author_name, author_avatar_url, text, created_at, hidden)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)'
);
$ins->execute([uuidv4(), $ownerId, $itemId, $visitorId, $authorName, $authorAvatar, $text, $now]);

// Tell the owner — this is the notification most likely to bring someone
// back, because somebody took the trouble to help with something they own.
if ($ownerId !== $visitorId) {
    $what = mb_substr((string)$item['description'], 0, 40);
    create_notification(
        $pdo, $ownerId, $visitorId, $authorName, $authorAvatar,
        'tip', $authorName . ' has a styling tip for your ' . ($what !== '' ? $what : 'closet item') . '.',
        ['actorId' => $visitorId, 'itemId' => $itemId, 'ownerId' => $ownerId],
        3600
    );
}

$st = $pdo->prepare(
    'SELECT id, author_id, author_name, author_avatar_url, text, created_at
       FROM closet_item_tips WHERE owner_id = ? AND item_id = ? AND hidden = 0 ORDER BY created_at ASC LIMIT 100'
);
$st->execute([$ownerId, $itemId]);
json_response(['tips' => array_map(fn($r) => [
    'id' => $r['id'],
    'authorId' => $r['author_id'],
    'authorName' => $r['author_name'],
    'authorAvatarUrl' => $r['author_avatar_url'],
    'text' => $r['text'],
    'createdAt' => (int)$r['created_at'],
], $st->fetchAll(PDO::FETCH_ASSOC))], 201);
