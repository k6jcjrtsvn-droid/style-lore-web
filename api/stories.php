<?php
/**
 * GET /api/stories?visitorId=&authToken= — active (not yet expired)
 * stories from this account and everyone it follows, oldest first (so
 * the frontend can group by author and show them in a stable order),
 * each flagged whether this visitor has already viewed it.
 *
 * POST /api/stories (multipart) — authorId, authToken, one of photo/video,
 * caption (optional) — creates a new story that expires 24h from now.
 */
require_once __DIR__ . '/../includes/helpers.php';

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $visitorId = (string)($_GET['visitorId'] ?? '');
    require_owner($pdo, $visitorId, bearer_token());

    $followStmt = $pdo->prepare('SELECT following_id FROM follows WHERE follower_id = ?');
    $followStmt->execute([$visitorId]);
    $authorIds = array_column($followStmt->fetchAll(), 'following_id');
    $authorIds[] = $visitorId;
    $authorIds = array_values(array_unique($authorIds));

    $placeholders = implode(',', array_fill(0, count($authorIds), '?'));
    $now = current_time_ms();
    $stmt = $pdo->prepare(
        "SELECT * FROM stories WHERE expires_at > ? AND author_id IN ($placeholders) ORDER BY created_at ASC"
    );
    $stmt->execute(array_merge([$now], $authorIds));
    $rows = $stmt->fetchAll();

    $viewedIds = [];
    if ($rows) {
        $storyIds = array_column($rows, 'id');
        $vp = implode(',', array_fill(0, count($storyIds), '?'));
        $viewStmt = $pdo->prepare("SELECT story_id FROM story_views WHERE visitor_id = ? AND story_id IN ($vp)");
        $viewStmt->execute(array_merge([$visitorId], $storyIds));
        $viewedIds = array_column($viewStmt->fetchAll(), 'story_id');
    }
    $viewedSet = array_flip($viewedIds);

    json_response(array_map(fn($r) => story_to_public($r, isset($viewedSet[$r['id']])), $rows));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $authorId = trim((string)($_POST['authorId'] ?? ''));
    $caption = mb_substr(trim((string)($_POST['caption'] ?? '')), 0, 200);
    if (!$authorId) error_response('Missing authorId.', 400);
    require_owner($pdo, $authorId, (string)($_POST['authToken'] ?? ''));

    try {
        $photoUrl = save_upload('photo', 'stories', 'image/', 'Photo');
        $videoUrl = save_upload('video', 'stories', 'video/', 'Video');
    } catch (RuntimeException $e) {
        error_response($e->getMessage(), 400);
    }
    if (!$photoUrl && !$videoUrl) error_response('A story needs a photo or a short video.', 400);

    $mediaUrl = $videoUrl ?: $photoUrl;
    $mediaType = $videoUrl ? 'video' : 'image';

    $profileStmt = $pdo->prepare('SELECT name, avatar_url FROM profiles WHERE id = ?');
    $profileStmt->execute([$authorId]);
    $authorProfile = $profileStmt->fetch();
    $authorName = ($authorProfile && $authorProfile['name']) ? $authorProfile['name'] : 'Someone';
    $authorAvatarUrl = $authorProfile ? $authorProfile['avatar_url'] : null;

    $id = uuidv4();
    $now = current_time_ms();
    $expiresAt = $now + (24 * 60 * 60 * 1000);

    // Housekeeping: stories that expired more than a day ago are gone for
    // good — drop their rows, views and media files so uploads/ doesn't
    // grow forever. Done here (on the next story post) since shared
    // hosting has no reliable cron.
    try {
        $old = $pdo->prepare('SELECT id, media_url FROM stories WHERE expires_at < ? LIMIT 50');
        $old->execute([$now - 24 * 60 * 60 * 1000]);
        $uploadsRoot = realpath(__DIR__ . '/../uploads');
        foreach ($old->fetchAll() as $dead) {
            $pdo->prepare('DELETE FROM story_views WHERE story_id = ?')->execute([$dead['id']]);
            $pdo->prepare('DELETE FROM stories WHERE id = ?')->execute([$dead['id']]);
            $real = $dead['media_url'] ? realpath(__DIR__ . '/../' . ltrim($dead['media_url'], '/')) : false;
            if ($real && $uploadsRoot && strpos($real, $uploadsRoot) === 0 && is_file($real)) @unlink($real);
        }
    } catch (Throwable $e) {
        error_log('Expired story cleanup failed: ' . $e->getMessage());
    }
    $ins = $pdo->prepare(
        'INSERT INTO stories (id, author_id, author_name, author_avatar_url, media_url, media_type, caption, created_at, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $ins->execute([$id, $authorId, $authorName, $authorAvatarUrl, $mediaUrl, $mediaType, $caption, $now, $expiresAt]);

    $rowStmt = $pdo->prepare('SELECT * FROM stories WHERE id = ?');
    $rowStmt->execute([$id]);
    json_response(story_to_public($rowStmt->fetch(), false), 201);
}

error_response('Method not allowed.', 405);
