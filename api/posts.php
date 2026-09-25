<?php
require_once __DIR__ . '/../includes/helpers.php';

$pdo = db();
ensure_poll_schema($pdo);
ensure_post_share_column($pdo);
ensure_comment_threads_schema($pdo);
ensure_block_schema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Who is asking, so a poll can come back with their own vote on it. Not
    // authenticated: this only decides which of the two bars is ticked, and
    // the tallies are public either way.
    $viewerId = isset($_GET['viewerId']) ? (string)$_GET['viewerId'] : null;
    $limit = min((int)($_GET['limit'] ?? 100), 200);
    if ($limit <= 0) $limit = 100;
    $authorId = isset($_GET['authorId']) ? (string)$_GET['authorId'] : null;
    // Group posts (see the `posts.group_id` column) are deliberately kept
    // out of the main Community feed and out of profile post listings —
    // they only ever come back when a caller explicitly asks for that
    // group's posts, matching Facebook-Groups-style separation from the
    // main feed.
    $groupId = isset($_GET['groupId']) ? (string)$_GET['groupId'] : null;

    // One post by id — what a notification tap opens (see the app's
    // PostDetailScreen). Group posts are included: if you were notified
    // about it you are already in that group. Hidden/reported posts are
    // not, so a removed post stays removed.
    //
    // Posts by anyone in a block relationship with the viewer are excluded
    // from every branch below (see blocked_ids / blocked_filter_sql in
    // helpers.php). A blocked author's post comes back as an empty list,
    // which the client already renders as "This post isn't here any more" —
    // the same thing it shows for a deleted post, and deliberately so: a
    // block should not announce itself to the person who was blocked.
    $blocked = blocked_ids($pdo, $viewerId);
    $blockSql = blocked_filter_sql($blocked, 'author_id');

    $postId = isset($_GET['postId']) ? (string)$_GET['postId'] : null;
    if ($postId !== null && $postId !== '') {
        $stmt = $pdo->prepare('SELECT * FROM posts WHERE hidden = 0 AND id = ?' . $blockSql . ' LIMIT 1');
        $stmt->execute(array_merge([$postId], $blocked));
        $rows = $stmt->fetchAll();
        json_response(array_map(fn($r) => post_to_public($pdo, $r, $viewerId), $rows));
    }

    if ($groupId !== null && $groupId !== '') {
        $stmt = $pdo->prepare('SELECT * FROM posts WHERE hidden = 0 AND group_id = ?' . $blockSql . ' ORDER BY created_at DESC LIMIT ' . $limit);
        $stmt->execute(array_merge([$groupId], $blocked));
    } elseif ($authorId !== null && $authorId !== '') {
        $stmt = $pdo->prepare('SELECT * FROM posts WHERE hidden = 0 AND author_id = ? AND group_id IS NULL' . $blockSql . ' ORDER BY created_at DESC LIMIT ' . $limit);
        $stmt->execute(array_merge([$authorId], $blocked));
    } else {
        $stmt = $pdo->prepare('SELECT * FROM posts WHERE hidden = 0 AND group_id IS NULL' . $blockSql . ' ORDER BY created_at DESC LIMIT ' . $limit);
        $stmt->execute($blocked);
    }
    $rows = $stmt->fetchAll();
    json_response(array_map(fn($r) => post_to_public($pdo, $r, $viewerId), $rows));
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

    /* THE FIRST POST GOES THROUGH UNVERIFIED. Everything after it does not.
     *
     * This gate was costing far more than the spam it prevented. On
     * 2026-09-25: 17 accounts, 7 verified, 11 who finished the quiz, 3 who
     * ever posted. Five of the people who got furthest and stopped were
     * unverified — so the app invited them to post and then refused, and
     * their verification link had expired days earlier. The door was locked
     * and the sign said come in.
     *
     * One post is a small blast radius: sign-up is rate limited per IP, every
     * post is reportable, and enough reports auto-hide it. A spammer gets one
     * post per fabricated account instead of none, which is a price worth
     * paying to stop losing real people at the only moment they were willing
     * to contribute something.
     *
     * The second post still needs a verified address, and the 403 now carries
     * a code so the app can offer a one-tap resend at the wall instead of
     * telling someone to go and find it. */
    $verifiedStmt = $pdo->prepare('SELECT email_verified FROM accounts WHERE id = ?');
    $verifiedStmt->execute([$authorId]);
    $verifiedRow = $verifiedStmt->fetch();
    if (!$verifiedRow || !$verifiedRow['email_verified']) {
        $already = $pdo->prepare('SELECT COUNT(*) c FROM posts WHERE author_id = ?');
        $already->execute([$authorId]);
        if ((int)$already->fetch()['c'] > 0) {
            json_response([
                'error' => 'Your first post is up. Verify your email to keep posting — we can send you a fresh link right now.',
                'code'  => 'verify_required',
            ], 403);
        }
    }

    $isPoll = !empty($_POST['isPoll']);
    // Opt-in, per post, default off. See ensure_post_share_column().
    $shareable = !empty($_POST['shareable']);

    try {
        $photoUrl = save_upload('photo', 'posts', 'image/', 'Photo');
        $videoFileUrl = save_upload('video', 'posts', 'video/', 'Video');
        // The second option of a "Help me choose" poll.
        $photoBUrl = $isPoll ? save_upload('photoB', 'posts', 'image/', 'Second photo') : null;
    } catch (RuntimeException $e) {
        error_response($e->getMessage(), 400);
    }

    // A poll is two photos and a question. Anything less isn't a choice, and
    // a half-built poll in the feed is worse than no poll.
    if ($isPoll && (!$photoUrl || !$photoBUrl)) {
        error_response('A poll needs both photos.', 400);
    }
    if ($isPoll && !$caption) {
        error_response('Add a question so people know what they are choosing between.', 400);
    }
    if ($isPoll && $videoFileUrl) {
        error_response('A poll is two photos — leave the video out.', 400);
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
        'INSERT INTO posts (id, author_id, author_name, author_avatar_url, kibbe_tag, style_tags, caption, photo_url, photo_b_url, is_poll, shareable, video_file_url, video_url, created_at, hidden, report_count, group_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?)'
    );
    $ins->execute([
        $id, $authorId, $authorName, $authorAvatarUrl, $kibbeTag,
        json_encode($styleTags), $caption, $photoUrl, $photoBUrl, $isPoll ? 1 : 0, $shareable ? 1 : 0,
        $videoFileUrl, $videoUrl, $now, $groupId,
    ]);

    $rowStmt = $pdo->prepare('SELECT * FROM posts WHERE id = ?');
    $rowStmt->execute([$id]);
    json_response(post_to_public($pdo, $rowStmt->fetch(), $authorId), 201);
}

error_response('Method not allowed.', 405);
