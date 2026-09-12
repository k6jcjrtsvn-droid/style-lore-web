<?php
/**
 * Shared helpers for every api/*.php endpoint. Every endpoint should
 * require_once this file first (it also requires db.php), then this file's
 * exception/shutdown handlers guarantee the client always gets JSON back —
 * never a raw PHP error page — even if something unexpected throws.
 */

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

// Both handlers below log the real detail server-side only. The client
// only ever sees a generic message — an earlier version of this file
// echoed the raw exception/error message straight into the JSON response,
// which handed out internal detail (file paths, DB errors, etc.) to
// whoever happened to trigger the error. error_log() is where the real
// detail belongs; check your hosting's PHP error log to see it.
set_exception_handler(function ($e) {
    error_log('Uncaught exception: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Something went wrong on the server. Try again in a moment.']);
    exit;
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('Fatal error: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
        // A fatal error can happen after headers/output already started in
        // rare cases; guard against a double Content-Type/body.
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['error' => 'Something went wrong on the server. Try again in a moment.']);
    }
});

function json_response($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function error_response(string $message, int $status = 400): void {
    json_response(['error' => $message], $status);
}

function require_method(string $method): void {
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        error_response('Method not allowed.', 405);
    }
}

/** Decodes a JSON request body the same way express.json() does; [] on any failure. */
function request_json(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function normalize_email($v): string {
    return strtolower(trim((string)$v));
}

function is_valid_email(string $email): bool {
    return (bool)preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $email);
}

/** RFC 4122 v4 UUID, formatted exactly like crypto.randomUUID()'s output. */
function uuidv4(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function current_time_ms(): int {
    return (int) round(microtime(true) * 1000);
}

/** A fresh random auth token — 64 hex chars, returned to the client exactly
 *  once (at signup, login, or password reset). Never stored raw. */
function new_auth_token(): string {
    return bin2hex(random_bytes(32));
}

/** How a raw token is stored/compared — one-way, so a leaked database
 *  dump alone can't be used to log in as anyone. */
function hash_token(string $token): string {
    return hash('sha256', $token);
}

/**
 * Requires that $token is the current auth token for account $accountId,
 * else halts the request with a 401 — the fix for endpoints that used to
 * trust whatever id the client sent with no way to check it actually
 * belongs to them. Every write that claims an identity (editing a
 * profile, creating a post, following someone, liking a post) should
 * call this before touching the database.
 */
function require_owner(PDO $pdo, string $accountId, ?string $token): void {
    if ($accountId === '' || !$token) {
        error_response('Missing account id or auth token — try logging in again.', 401);
    }
    $stmt = $pdo->prepare('SELECT auth_token_hash FROM accounts WHERE id = ?');
    $stmt->execute([$accountId]);
    $row = $stmt->fetch();
    if (!$row || !$row['auth_token_hash'] || !hash_equals($row['auth_token_hash'], hash_token($token))) {
        error_response('Not authorized for this account — try logging in again.', 401);
    }
}

/**
 * Sends a plain-text email via PHP's built-in mail() (works out of the box
 * on GoDaddy shared hosting, no SMTP setup needed) — but only if the
 * envelope sender (the "-f" additional parameter) is set to a real mailbox
 * on this domain. Without it, GoDaddy's sendmail uses a default envelope
 * sender (something like the server's own hostname account) that fails
 * SPF for style-lore.com and gets silently dropped by receiving mail
 * servers — mail() still returns true either way, so this failure mode is
 * invisible unless you know to look for it. Confirmed by an earlier
 * diagnostic on this exact box: a plain mail() call with no "-f" never
 * arrived anywhere (inbox or spam), while the same call with "-f
 * no-reply@style-lore.com" (after that mailbox was created in cPanel) did.
 * MAIL_FROM is that same mailbox, so it doubles as the envelope sender.
 *
 * Returns whether mail() reported success — callers should treat a false
 * return as "couldn't send it right now" but generally shouldn't reveal
 * that to the client for auth emails (see auth_forgot.php's
 * anti-enumeration note).
 */
function send_app_email(string $to, string $subject, string $body): bool {
    $headers = "From: Style-LORE <" . MAIL_FROM . ">\r\n" .
        "Content-Type: text/plain; charset=utf-8\r\n";
    $envelopeSender = '-f' . MAIL_FROM;
    return @mail($to, $subject, $body, $headers, $envelopeSender);
}

/** A post is hidden once this many distinct people have reported it —
 *  see post_report.php and the `reports` table. */
const REPORT_HIDE_THRESHOLD = 3;

/**
 * Saves an uploaded file (one $_FILES entry) into web/uploads/$subdir with
 * a random filename, mirroring the local Node app's multer config (30MB
 * cap, image/* or video/* mimetype enforcement per field). Returns the
 * public "/uploads/..." URL, or null if that field wasn't sent. Throws a
 * RuntimeException with a user-facing message on any validation failure —
 * callers should catch and turn that into a 400 the same way the old
 * multer error-handling middleware did.
 */
function save_upload(string $field, string $subdir, string $requiredMimePrefix, string $friendlyName): ?string {
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $file = $_FILES[$field];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            throw new RuntimeException($friendlyName . ' is too large (max 30MB).');
        }
        throw new RuntimeException('Upload failed — try again.');
    }
    if ($file['size'] > 30 * 1024 * 1024) {
        throw new RuntimeException($friendlyName . ' is too large (max 30MB).');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']) ?: '';
    finfo_close($finfo);
    if (strpos($mime, $requiredMimePrefix) !== 0) {
        throw new RuntimeException($friendlyName . ' must be a ' . rtrim($requiredMimePrefix, '/') . ' file.');
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $ext = preg_replace('/[^a-z0-9]/', '', $ext);
    $ext = $ext !== '' ? '.' . substr($ext, 0, 10) : '';

    $dir = __DIR__ . '/../uploads/' . $subdir;
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $filename = bin2hex(random_bytes(16)) . $ext;
    $dest = $dir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('Could not save the uploaded file — check that the uploads/ folder is writable.');
    }
    return '/uploads/' . $subdir . '/' . $filename;
}

/**
 * Reassembles one post row into the exact JSON shape the frontend already
 * expects from the old Express API — likes as a {visitorId: bool} map,
 * comments as an array, styleTags decoded back into a real array, and
 * moderation-only fields (hidden/reportCount) stripped, matching
 * publicPost() in the local server.
 */
function post_to_public(PDO $pdo, array $row): array {
    $likesStmt = $pdo->prepare('SELECT visitor_id, liked FROM likes WHERE post_id = ?');
    $likesStmt->execute([$row['id']]);
    $likes = [];
    foreach ($likesStmt->fetchAll() as $l) {
        $likes[$l['visitor_id']] = (bool)$l['liked'];
    }

    $commentsStmt = $pdo->prepare('SELECT author_name, text, created_at FROM comments WHERE post_id = ? ORDER BY id ASC');
    $commentsStmt->execute([$row['id']]);
    $comments = array_map(function ($c) {
        return [
            'authorName' => $c['author_name'],
            'text' => $c['text'],
            'createdAt' => (int)$c['created_at'],
        ];
    }, $commentsStmt->fetchAll());

    $styleTags = json_decode($row['style_tags'] ?: '[]', true);
    if (!is_array($styleTags)) $styleTags = [];

    return [
        'id' => $row['id'],
        'authorName' => $row['author_name'],
        'authorId' => $row['author_id'],
        'authorAvatarUrl' => $row['author_avatar_url'],
        'kibbeTag' => $row['kibbe_tag'],
        'styleTags' => array_values($styleTags),
        'caption' => $row['caption'],
        'photoUrl' => $row['photo_url'],
        'videoFileUrl' => $row['video_file_url'],
        'videoUrl' => $row['video_url'],
        'createdAt' => (int)$row['created_at'],
        'likes' => $likes,
        'comments' => $comments,
        'groupId' => $row['group_id'] ?? null,
    ];
}

/* =========================================================================
 * Community/social features phase — Notifications, Direct Messages,
 * Stories, Groups. Shared helpers used by more than one api/*.php file
 * live here, same as post_to_public() above.
 * ========================================================================= */

/**
 * Records one notification for $recipientId. Never notifies someone
 * about their own action (e.g. liking your own post) — every call site
 * should still avoid calling this for self-actions, but this is a last
 * line of defense. $actorId is nullable: api/post_comments.php has no
 * verified account id for the commenter (see its own comments), so a
 * comment notification shows actor_name but can't be tapped through to a
 * profile. $data is a small associative array JSON-encoded for the
 * frontend to decide what tapping this notification should do (e.g.
 * ['conversationId' => ..., 'otherId' => ...] for a message).
 */
function create_notification(PDO $pdo, string $recipientId, ?string $actorId, string $actorName, ?string $actorAvatarUrl, string $type, string $message, ?array $data = null): void {
    if ($recipientId === '' || $recipientId === $actorId) return;
    $stmt = $pdo->prepare(
        'INSERT INTO notifications (id, recipient_id, actor_id, actor_name, actor_avatar_url, type, message, data, created_at, is_read)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0)'
    );
    $stmt->execute([
        uuidv4(), $recipientId, $actorId ?: null, mb_substr($actorName, 0, 60), $actorAvatarUrl,
        $type, mb_substr($message, 0, 200), $data ? json_encode($data) : null, current_time_ms(),
    ]);
}

function notification_to_public(array $row): array {
    $data = null;
    if (!empty($row['data'])) {
        $decoded = json_decode($row['data'], true);
        if (is_array($decoded)) $data = $decoded;
    }
    return [
        'id' => $row['id'],
        'actorId' => $row['actor_id'],
        'actorName' => $row['actor_name'],
        'actorAvatarUrl' => $row['actor_avatar_url'],
        'type' => $row['type'],
        'message' => $row['message'],
        'data' => $data,
        'createdAt' => (int)$row['created_at'],
        'read' => (bool)$row['is_read'],
    ];
}

function message_to_public(array $row): array {
    return [
        'id' => $row['id'],
        'conversationId' => $row['conversation_id'],
        'senderId' => $row['sender_id'],
        'senderName' => $row['sender_name'],
        'text' => $row['text'],
        'createdAt' => (int)$row['created_at'],
    ];
}

function story_to_public(array $row, bool $viewed): array {
    return [
        'id' => $row['id'],
        'authorId' => $row['author_id'],
        'authorName' => $row['author_name'],
        'authorAvatarUrl' => $row['author_avatar_url'],
        'mediaUrl' => $row['media_url'],
        'mediaType' => $row['media_type'],
        'caption' => $row['caption'],
        'createdAt' => (int)$row['created_at'],
        'expiresAt' => (int)$row['expires_at'],
        'viewed' => $viewed,
    ];
}

/**
 * One closet item as returned to the frontend. photo_data is the same
 * downscaled base64 data: URL the client already renders directly via
 * <img src>, stored as-is (no server-side re-encoding, no separate file on
 * disk) — see api/closet.php for why this table is a wholesale
 * replace-on-save mirror of the client's local closet array rather than a
 * normal CRUD resource. visibility/authorName are per-item (each closet
 * item is individually Hidden or Public — see Kenneth's 2026-09-14
 * feedback that a single account-wide toggle wasn't granular enough).
 */
function closet_item_to_public(array $row): array {
    return [
        'id' => $row['id'],
        'description' => $row['description'],
        'photo' => $row['photo_data'],
        'createdAt' => (int)$row['created_at'],
        'visibility' => $row['visibility'] ?? 'hidden',
        'authorName' => $row['author_name'] ?? '',
        'authorId' => $row['account_id'] ?? null,
    ];
}

function group_to_public(array $row, ?bool $isMember = null): array {
    $out = [
        'id' => $row['id'],
        'name' => $row['name'],
        'description' => $row['description'],
        'topicKibbe' => $row['topic_kibbe'],
        'topicStyle' => $row['topic_style'],
        'creatorId' => $row['creator_id'],
        'creatorName' => $row['creator_name'],
        'memberCount' => (int)$row['member_count'],
        'createdAt' => (int)$row['created_at'],
    ];
    if ($isMember !== null) $out['isMember'] = $isMember;
    return $out;
}
