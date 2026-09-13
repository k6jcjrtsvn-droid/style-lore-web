<?php
/**
 * POST /api/account/delete — permanently deletes an account and the data
 * tied to it, after re-verifying the account's password (so a stolen
 * session/visitorId alone can't be used to destroy someone's account).
 *
 * Deletes everything tied to the account: accounts + profiles rows, posts
 * (plus uploaded files), comments (by author_id — rows from before
 * MIGRATE-2026-09-17 have no author_id and are left as name-only text),
 * likes, reports, follows in both directions, closet items, stories
 * (plus files) and story views, DM messages and conversation memberships
 * (conversations that end up empty are removed too), interest-group
 * memberships (with member_count fixed up; groups this account created are
 * kept — they belong to their members now, creator name is blanked),
 * notifications sent to or by the account, push device tokens, and the
 * subscription row.
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
rate_limit($pdo, 'delete:ip:' . client_ip(), 10, 3600);

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

    $storiesStmt = $pdo->prepare('SELECT id, media_url FROM stories WHERE author_id = ?');
    $storiesStmt->execute([$id]);
    $storyIds = [];
    foreach ($storiesStmt->fetchAll() as $st) {
        $storyIds[] = $st['id'];
        if ($st['media_url']) $filesToDelete[] = $st['media_url'];
    }

    $convStmt = $pdo->prepare('SELECT conversation_id FROM conversation_members WHERE account_id = ?');
    $convStmt->execute([$id]);
    $convIds = array_column($convStmt->fetchAll(), 'conversation_id');

    $groupStmt = $pdo->prepare('SELECT group_id FROM interest_group_members WHERE account_id = ?');
    $groupStmt->execute([$id]);
    $groupIds = array_column($groupStmt->fetchAll(), 'group_id');

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM likes WHERE visitor_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM reports WHERE visitor_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM follows WHERE follower_id = ? OR following_id = ?')->execute([$id, $id]);
        $pdo->prepare('DELETE FROM closet_items WHERE account_id = ?')->execute([$id]);
        try {
            $pdo->prepare('DELETE FROM comments WHERE author_id = ?')->execute([$id]);
        } catch (PDOException $e) {
            error_log('comments.author_id missing? run MIGRATE-2026-09-17.sql — ' . $e->getMessage());
        }
        $pdo->prepare('DELETE FROM posts WHERE author_id = ?')->execute([$id]);

        // Stories + who viewed them, and this account's own views of others'.
        foreach ($storyIds as $sid) {
            $pdo->prepare('DELETE FROM story_views WHERE story_id = ?')->execute([$sid]);
        }
        $pdo->prepare('DELETE FROM stories WHERE author_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM story_views WHERE visitor_id = ?')->execute([$id]);

        // Direct messages: remove the account's messages and membership;
        // drop any conversation that is left with no members.
        $pdo->prepare('DELETE FROM messages WHERE sender_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM conversation_members WHERE account_id = ?')->execute([$id]);
        foreach ($convIds as $cid) {
            $left = $pdo->prepare('SELECT COUNT(*) FROM conversation_members WHERE conversation_id = ?');
            $left->execute([$cid]);
            if ((int)$left->fetchColumn() === 0) {
                $pdo->prepare('DELETE FROM messages WHERE conversation_id = ?')->execute([$cid]);
                $pdo->prepare('DELETE FROM conversations WHERE id = ?')->execute([$cid]);
            }
        }

        // Groups: leave every group and fix up member counts; groups the
        // account created survive for their remaining members.
        $pdo->prepare('DELETE FROM interest_group_members WHERE account_id = ?')->execute([$id]);
        foreach ($groupIds as $gid) {
            $pdo->prepare('UPDATE interest_groups SET member_count = (SELECT COUNT(*) FROM interest_group_members WHERE group_id = ?) WHERE id = ?')->execute([$gid, $gid]);
        }
        $pdo->prepare("UPDATE interest_groups SET creator_name = '' WHERE creator_id = ?")->execute([$id]);

        $pdo->prepare('DELETE FROM notifications WHERE recipient_id = ? OR actor_id = ?')->execute([$id, $id]);
        $pdo->prepare('DELETE FROM device_tokens WHERE account_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM subscriptions WHERE account_id = ?')->execute([$id]);

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
