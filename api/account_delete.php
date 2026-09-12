<?php
/**
 * POST /api/account/delete — permanently deletes an account and the data
 * tied to it, after re-verifying the account's password (so a stolen
 * session/visitorId alone can't be used to destroy someone's account).
 *
 * Deletes: the accounts row, the profiles row, all posts by this author
 * (plus their uploaded photo/video files on disk), all likes by this
 * visitor, and all follow edges in both directions.
 *
 * Known, disclosed limitation: the `comments` table only stores a
 * denormalized `author_name` string, not an author id (see schema.sql) —
 * there is no reliable way to identify "this account's comments" without
 * risking deleting someone else's comment that happens to share a display
 * name. Comments are intentionally left in place; only the account that
 * could identify a real person (email, password, profile, posts, likes,
 * follows) is removed. If this needs to be tightened later, add an
 * author_id column to comments going forward (existing rows would stay
 * name-only) rather than guessing by name match now.
 */

require_once __DIR__ . '/../includes/helpers.php';
require_method('POST');

$body = request_json();
$id = trim((string)($body['id'] ?? ''));
$password = (string)($body['password'] ?? '');

if (!$id || !$password) {
    error_response('Missing account id or password.', 400);
}

$pdo = db();

try {
    $stmt = $pdo->prepare('SELECT id, password_hash FROM accounts WHERE id = ?');
    $stmt->execute([$id]);
    $account = $stmt->fetch();

    // Same deliberately-generic error as login — never confirm/deny by id
    // vs. password separately.
    if (!$account || !password_verify($password, $account['password_hash'])) {
        error_response('Incorrect password.', 401);
    }

    // Collect file paths to remove from disk *before* deleting the DB rows
    // that reference them.
    $filesToDelete = [];

    $profileStmt = $pdo->prepare('SELECT avatar_url FROM profiles WHERE id = ?');
    $profileStmt->execute([$id]);
    $profile = $profileStmt->fetch();
    if ($profile && $profile['avatar_url']) {
        $filesToDelete[] = $profile['avatar_url'];
    }

    $postsStmt = $pdo->prepare('SELECT photo_url, video_file_url FROM posts WHERE author_id = ?');
    $postsStmt->execute([$id]);
    foreach ($postsStmt->fetchAll() as $p) {
        if ($p['photo_url']) $filesToDelete[] = $p['photo_url'];
        if ($p['video_file_url']) $filesToDelete[] = $p['video_file_url'];
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM likes WHERE visitor_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM reports WHERE visitor_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM follows WHERE follower_id = ? OR following_id = ?')->execute([$id, $id]);
        $pdo->prepare('DELETE FROM posts WHERE author_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM profiles WHERE id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM accounts WHERE id = ?')->execute([$id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    // Best-effort file cleanup — a failure here shouldn't undo the DB
    // deletion that already succeeded (the account is gone either way).
    foreach ($filesToDelete as $url) {
        // $url looks like "/uploads/posts/xyz.jpg" — resolve against web/.
        $path = __DIR__ . '/../' . ltrim($url, '/');
        $real = realpath($path);
        $uploadsRoot = realpath(__DIR__ . '/../uploads');
        if ($real && $uploadsRoot && strpos($real, $uploadsRoot) === 0 && is_file($real)) {
            @unlink($real);
        }
    }

    json_response(['ok' => true]);
} catch (Throwable $e) {
    error_log('Account deletion failed: ' . $e->getMessage());
    error_response("Couldn't delete your account — try again.", 500);
}
