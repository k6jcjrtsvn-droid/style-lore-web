<?php
require_once __DIR__ . '/../includes/helpers.php';

$pdo = db();
$id = (string)($_GET['id'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare('SELECT id, name, bio, avatar_url, kibbe_type_name, style_words, kibbe_result_json, style_blend_json FROM profiles WHERE id = ?');
    $stmt->execute([$id]);
    $existing = $stmt->fetch();
    if ($existing) {
        $kibbeResult = null;
        if (!empty($existing['kibbe_result_json'])) {
            $decoded = json_decode($existing['kibbe_result_json'], true);
            if (is_array($decoded)) $kibbeResult = $decoded;
        }
        $styleBlend = null;
        if (!empty($existing['style_blend_json'])) {
            $decoded = json_decode($existing['style_blend_json'], true);
            if (is_array($decoded)) $styleBlend = $decoded;
        }
        json_response([
            'id' => $existing['id'],
            'name' => $existing['name'],
            'bio' => $existing['bio'],
            'avatarUrl' => $existing['avatar_url'],
            // Auto-populated from this person's own Kibbe/Style quiz
            // results — see api/profile_style.php. Never hand-entered.
            'kibbeTypeName' => $existing['kibbe_type_name'],
            'styleWords' => $existing['style_words'] ? json_decode($existing['style_words'], true) : [],
            // Full underlying quiz results — used to (a) reload this
            // account's own results on a new device without retaking
            // either quiz, and (b) drive the Compare screen. Same shape
            // the client itself uses locally: kibbeResult is the
            // {kibbeTypeId, kibbeBlend} object from the Kibbe Quiz,
            // styleBlend is the Style Quiz's raw word-weight dict. Null
            // when that quiz hasn't been taken (or synced) yet.
            'kibbeResult' => $kibbeResult,
            'styleBlend' => $styleBlend,
        ]);
    }

    // No profile row yet (never opened "Edit profile") — fall back to the
    // name on their most recent post, same as the local prototype.
    $postStmt = $pdo->prepare('SELECT author_name FROM posts WHERE author_id = ? AND hidden = 0 ORDER BY created_at DESC LIMIT 1');
    $postStmt->execute([$id]);
    $authored = $postStmt->fetch();

    json_response([
        'id' => $id,
        'name' => $authored ? $authored['author_name'] : null,
        'bio' => '',
        'avatarUrl' => null,
        'kibbeTypeName' => null,
        'styleWords' => [],
        'kibbeResult' => null,
        'styleBlend' => null,
        'exists' => false,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Ownership check — this used to update any profile by whatever id
    // was passed, with nothing to prove the request came from that
    // account. Post author ids are visible in the feed, so this was a
    // real way to overwrite a stranger's name/bio/avatar. See
    // deployment-status.md's audit notes.
    require_owner($pdo, $id, (string)($_POST['authToken'] ?? ''));

    $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 60);
    $bio = mb_substr(trim((string)($_POST['bio'] ?? '')), 0, 160);
    if (!$name) error_response('Name is required.', 400);

    $stmt = $pdo->prepare('SELECT avatar_url FROM profiles WHERE id = ?');
    $stmt->execute([$id]);
    $existing = $stmt->fetch();

    try {
        $uploadedUrl = save_upload('avatar', 'avatars', 'image/', 'Profile picture');
    } catch (RuntimeException $e) {
        error_response($e->getMessage(), 400);
    }
    $avatarUrl = $uploadedUrl ?: ($existing ? $existing['avatar_url'] : null);
    $now = current_time_ms();

    if ($existing) {
        $upd = $pdo->prepare('UPDATE profiles SET name = ?, bio = ?, avatar_url = ?, updated_at = ? WHERE id = ?');
        $upd->execute([$name, $bio, $avatarUrl, $now, $id]);
    } else {
        $ins = $pdo->prepare('INSERT INTO profiles (id, name, bio, avatar_url, updated_at) VALUES (?, ?, ?, ?, ?)');
        $ins->execute([$id, $name, $bio, $avatarUrl, $now]);
    }

    json_response(['id' => $id, 'name' => $name, 'bio' => $bio, 'avatarUrl' => $avatarUrl]);
}

error_response('Method not allowed.', 405);
