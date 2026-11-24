<?php
/**
 * api/lorey_listen — turn a few seconds of speech into text.
 *
 * WHY THIS EXISTS. Holding a button and talking to Lorey works in Chrome
 * because Chrome has the Web Speech API. Apple does not implement it, and
 * on iOS every browser is WebKit underneath — Safari, Chrome and Firefox
 * alike — so on an iPhone there is no speech recognition in the browser at
 * all, and no amount of client code conjures one. This endpoint is how an
 * iPhone talks to her: the page records a clip, posts it here, and gets
 * back the words.
 *
 * It is deliberately NOT a general transcription service:
 *   - Premium only, and rate limited per account.
 *   - One short clip at a time, hard capped.
 *   - The audio is held in memory, sent, and dropped. Nothing is written to
 *     disk, nothing goes in the database, and there is no log of what
 *     anybody said.
 *
 * TO TURN IT ON: put the provider key in includes/config.php as
 * LOREY_STT_KEY. Until that constant exists this endpoint answers 404 to
 * the client's probe, which is exactly how the app decides whether to show
 * hold-to-talk on a browser that cannot do it locally. No deploy of the app
 * is needed either way.
 */
require_once __DIR__ . '/../includes/helpers.php';

$configured = defined('LOREY_STT_KEY') && LOREY_STT_KEY !== '';

// The probe. The client asks once per session whether this exists, and
// caches the answer; an unconfigured server costs one 404 and no more.
if (($_GET['probe'] ?? '') !== '') {
    if (!$configured) { http_response_code(404); echo '{"ok":false}'; exit; }
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'maxSeconds' => 20]);
    exit;
}

require_method('POST');
if (!$configured) error_response('Voice input is not switched on.', 404);

/* Who is asking. Same gate and same shape as api/checker_ask.php, which is
   the thing this feeds: signed in, owner of the visitor id, Premium. */
$visitorId = (string)($_GET['visitorId'] ?? '');
$authToken = (string)($_GET['authToken'] ?? '');
if (!$visitorId || !$authToken) error_response('You need to be signed in to talk to Lorey.', 401);

$pdo = db();
require_owner($pdo, $visitorId, $authToken);
if (!has_premium($pdo, $visitorId)) {
    json_response(['error' => 'Talking to Lorey is a Style-LORE Premium feature.', 'code' => 'premium_required'], 402);
}

/* Purely an abuse cap on a per-call-cost feature, not a product limit:
   a real conversation never comes near it, a runaway loop hits it fast. */
rate_limit($pdo, 'lorey_listen:' . $visitorId, 300, 86400, "That's a lot of talking for one day — try again tomorrow.");

$raw = file_get_contents('php://input');
$len = strlen($raw);
if ($len < 1024)      error_response('No audio arrived.', 400);
if ($len > 2000000)   error_response('That clip is too long — keep it under about twenty seconds.', 413);

$type = (string)($_SERVER['CONTENT_TYPE'] ?? '');
$ext  = stripos($type, 'mp4') !== false ? 'mp4'
      : (stripos($type, 'ogg') !== false ? 'ogg' : 'webm');

/* Hand it to the provider. multipart, in memory, nothing on disk. */
$boundary = '----lorey' . bin2hex(random_bytes(8));
$body  = "--$boundary\r\n";
$body .= "Content-Disposition: form-data; name=\"model_id\"\r\n\r\nscribe_v1\r\n";
$body .= "--$boundary\r\n";
$body .= "Content-Disposition: form-data; name=\"file\"; filename=\"clip.$ext\"\r\n";
$body .= "Content-Type: $type\r\n\r\n";
$body .= $raw . "\r\n";
$body .= "--$boundary--\r\n";

$ch = curl_init('https://api.elevenlabs.io/v1/speech-to-text');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 25,
    CURLOPT_HTTPHEADER     => [
        'xi-api-key: ' . LOREY_STT_KEY,
        'Content-Type: multipart/form-data; boundary=' . $boundary,
    ],
]);
$res  = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);
unset($raw, $body);           // the audio stops existing here

if ($res === false || $code >= 400) {
    // Never echo the provider's body: it can carry request ids and account
    // details that are none of the client's business.
    error_log('lorey_listen: provider ' . $code . ' ' . substr((string)$err, 0, 120));
    error_response('Could not make out what you said — try again, or type it.', 502);
}

$d    = json_decode((string)$res, true);
$text = trim((string)($d['text'] ?? ''));
if ($text === '') error_response('Nothing came through — try again a little louder.', 422);

header('Content-Type: application/json');
echo json_encode(['ok' => true, 'text' => mb_substr($text, 0, 400)]);
