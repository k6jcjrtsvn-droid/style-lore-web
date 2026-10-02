<?php
/**
 * api/events — small product events the app sends so the funnel can see
 * the moments that leave no other trace.
 *
 * The admin Funnel tab counts what exists in the database: accounts, quiz
 * results, posts. It has always been blind to two things — anyone who took
 * the quiz signed out and left, and every share button that was tapped and
 * went nowhere. This endpoint records exactly those moments, and nothing
 * more: the event name is a closed list (below), the payload is a short
 * string, and the only identity is the session if there is one plus an
 * anonymous visitor id the client already keeps. No auth, because the
 * signed-out quiz completion is the point — so it is rate-limited per IP
 * and stores a hash of the address, never the address.
 *
 * POST JSON {event, meta?, visitorId?, accountId?, authToken?} -> {ok:true}.
 * Always {ok:true} for a valid event, even if nothing could be stored: a
 * metric must never surface as an error in the app.
 */
require_once __DIR__ . '/../includes/helpers.php';
require_method('POST');

/** The closed list. Add here first; anything else is a 400. */
const EVENT_NAMES = [
    'quiz_complete_signed_out', 'quiz_complete', 'color_quiz_complete',
    'result_share_tap', 'styleme_share_tap', 'outfit_share_tap',
    'post_from_result', 'post_from_styleme', 'post_from_outfit',
    'p_vote_cta_tap', 'pending_post_resumed',
];

$pdo = db();
rate_limit($pdo, 'events:' . client_ip(), 60, 3600, 'Too many events.');

function ensure_events_table(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec('CREATE TABLE IF NOT EXISTS events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        created_at BIGINT NOT NULL,
        event VARCHAR(40) NOT NULL,
        account_id CHAR(36) NULL,
        visitor_id VARCHAR(64) NULL,
        meta VARCHAR(500) NULL,
        ip_hash CHAR(40) NULL,
        INDEX idx_event_time (event, created_at),
        INDEX idx_time (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

$body = request_json();
$event = (string)($body['event'] ?? '');
if (!in_array($event, EVENT_NAMES, true)) error_response('Unknown event.', 400);

// Who, if anyone. The account id only counts when the token proves it —
// otherwise a client could file events under somebody else's id. A bad
// token is not an error here; the event is simply anonymous.
$accountId = null;
$claimedId = (string)($body['accountId'] ?? '');
if ($claimedId !== '' && preg_match('/^[0-9a-f-]{36}$/i', $claimedId)) {
    try {
        if (owner_token_valid($pdo, $claimedId, (string)($body['authToken'] ?? ''))) $accountId = $claimedId;
    } catch (Throwable $e) { error_log('events auth: ' . $e->getMessage()); }
}
$visitorId = mb_substr(trim((string)($body['visitorId'] ?? '')), 0, 64) ?: null;

$meta = $body['meta'] ?? null;
if ($meta !== null) {
    $meta = is_string($meta) ? $meta : json_encode($meta, JSON_UNESCAPED_SLASHES);
    $meta = mb_substr((string)$meta, 0, 500);
    if ($meta === '') $meta = null;
}

try {
    ensure_events_table($pdo);
    $pdo->prepare('INSERT INTO events (created_at, event, account_id, visitor_id, meta, ip_hash) VALUES (?,?,?,?,?,?)')
        ->execute([current_time_ms(), $event, $accountId, $visitorId, $meta, sha1(client_ip())]);
} catch (Throwable $e) {
    error_log('events insert: ' . $e->getMessage());
}

json_response(['ok' => true]);
