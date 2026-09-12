<?php
require_once __DIR__ . '/../includes/helpers.php';

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $limit = min((int)($_GET['limit'] ?? 100), 200);
    if ($limit <= 0) $limit = 100;
    $authorId = isset($_GET['authorId']) ? (string)$_GET['authorId'] : null;
    // Group posts (see the `posts.group_id` column) are deliberately kept
    // out of the main Community feed and out of profile post listings —
    // they only ever come back when a caller explicitly asks for that
    // group's posts, matching Facebook-Groups-style separation from the
    // main feed.
    $groupId = isset($_GET['groupId']) ? (string)$_GET['groupId'] : null;

    if ($groupId !== null && $groupId !== '') {
        $stmt = $pdo->prepare('SELECT * FROM posts WHERE hidden = 0 AND group_id = ? ORDER BY created_at DESC LIMIT ' . $limit);
        $stmt->execute([$groupId]);
    } elseif ($authorId !== null && $authorId !== '') {
        $stmt = $pdo->prepare('SELECT * FROM posts WHERE hidden = 0 AND author_id = ? AND group_id IS NULL ORDER BY created_at DESC LIMIT ' . $limit);
        $stmt->execute([$authorId]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM posts WHERE hidden = 0 AND group_id IS NULL ORDER BY created_at DESC LIMIT ' . $limit);
        $stmt->execute();
    }
    $rows = $stmt->fetchAll();
    json_response(array_map(fn($r) => post_to_public($pdo, $r), $rows));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $authorName = mb_substr(trim((string)($_POST['authorName'] ?? '')), 0, 60);
    $authorId = trim((string)($_POST['authorId'] ?? ''));
    $kibbeTag = trim((string)($_POST['kibbeTag'] ?? ''));
    $caption = mb_substr(trim((string)($_POST['caption'] ?? '')), 0, 280);
    $videoUrl = trim((string)($_POST['videoUrl'] ?? '')) ?: null;
    $groupId = trim((string)($_POST['groupId'] ?? '')) ?: null;

    $styleTags = [];
    $rawStyleTags = json_decode((string)($_POST['styleTags'] ?? '[]'), true);
    if (is_array($rawStyleTags)) {
        $styleTags = array_values(array_slice(array_filter($rawStyleTags, 'is_string'), 0, 3));
    }

    if (!$authorName || !$authorId || !$kibbeTag) {
        error_response('Missing author or Kibbe tag.', 400);
    }

    // Ownership check — this used to accept any authorId the client sent,
    // no proof required, which let anyone post as anyone. See
    // deployment-status.md's audit notes.
    require_owner($pdo, $authorId, (string)($_POST['authToken'] ?? ''));

    if ($groupId !== null) {
        $groupCheck = $pdo->prepare('SELECT id FROM interest_groups WHERE id = ?');
        $groupCheck->execute([$groupId]);
        if (!$groupCheck->fetch()) error_response('Group not found.', 404);
        $memberCheck = $pdo->prepare('SELECT 1 FROM interest_group_members WHERE group_id = ? AND account_id = ?');
        $memberCheck->execute([$groupId, $authorId]);
        if (!$memberCheck->fetch()) error_response('Join the group before posting in it.', 403);
    }

    $verifiedStmt = $pdo->prepare('SELECT email_verified FROM accounts WHERE id = ?');
    $verifiedStmt->execute([$authorId]);
    $verifiedRow = $verifiedStmt->fetch();
    if (!$verifiedRow || !$verifiedRow['email_verified']) {
        error_response('Verify your email before posting in Community — check your inbox, or resend the link from Home.', 403);
    }

    try {
        $photoUrl = save_upload('photo', 'posts', 'image/', 'Photo');
        $videoFileUrl = save_upload('video', 'posts', 'video/', 'Video');
    } catch (RuntimeException $e) {
        error_response($e->getMessage(), 400);
    }

    if (!$caption && !$photoUrl && !$videoFileUrl && !$videoUrl) {
        error_response('A post needs a caption, photo, or video.', 400);
    }

    // A freshly uploaded clip wins over a pasted link if somehow both arrive.
    if ($videoFileUrl) $videoUrl = null;

    $profileStmt = $pdo->prepare('SELECT avatar_url FROM profiles WHERE id = ?');
    $profileStmt->execute([$authorId]);
    $authorProfile = $profileStmt->fetch();
    $authorAvatarUrl = $authorProfile ? $authorProfile['avatar_url'] : null;

    $id = uuidv4();
    $now = current_time_ms();

    $ins = $pdo->prepare(
        'INSERT INTO posts (id, author_id, author_name, author_avatar_url, kibbe_tag, style_tags, caption, photo_url, video_file_url, video_url, created_at, hidden, report_count, group_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?)'
    );
    $ins->execute([
        $id, $authorId, $authorName, $authorAvatarUrl, $kibbeTag,
        json_encode($styleTags), $caption, $photoUrl, $videoFileUrl, $videoUrl, $now, $groupId,
    ]);

    $rowStmt = $pdo->prepare('SELECT * FROM posts WHERE id = ?');
    $rowStmt->execute([$id]);
    json_response(post_to_public($pdo, $rowStmt->fetch()), 201);
}

error_response('Method not allowed.', 405);
