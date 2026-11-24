<?php
/**
 * api/lorey_voice — Lorey's actual voice.
 *
 * The device synthesiser is free, offline and leaks nothing, and it sounds
 * like a satnav. This is the other option: a real neural voice, rendered
 * server-side so the key never goes near a browser.
 *
 * The client already knows how to use it. It probes this endpoint once per
 * session, caches the answer, and switches on its own — so turning a real
 * voice on is setting LOREY_TTS_KEY in config.php and nothing else. No app
 * release, no store review, no deploy of index.html.
 *
 * It also sounds better in a way that has nothing to do with the voice: the
 * client decodes real audio through a WebAudio analyser, so her jaw moves on
 * the actual waveform instead of on an envelope guessing at one.
 *
 * COST. Billed per character, so: Premium only, rate limited, length capped,
 * and identical text is served from a small on-disk cache rather than
 * re-rendered. Her stock lines — the greeting, the upsell — are then free
 * after the first person hears them.
 */
require_once __DIR__ . '/../includes/helpers.php';

$configured = defined('LOREY_TTS_KEY') && LOREY_TTS_KEY !== '';
$voiceId    = defined('LOREY_TTS_VOICE') && LOREY_TTS_VOICE !== ''
            ? LOREY_TTS_VOICE : 'EXAVITQu4vr4xnSDxMaL';   // a warm, mid-range female

if (($_GET['probe'] ?? '') !== '') {
    if (!$configured) { http_response_code(404); echo '{"ok":false}'; exit; }
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

require_method('POST');
if (!$configured) error_response('Her voice is not switched on.', 404);

$visitorId = (string)($_GET['visitorId'] ?? '');
$authToken = (string)($_GET['authToken'] ?? '');
if (!$visitorId || !$authToken) error_response('You need to be signed in.', 401);

$pdo = db();
require_owner($pdo, $visitorId, $authToken);
if (!has_premium($pdo, $visitorId)) {
    json_response(['error' => "Lorey's speaking voice is a Premium feature.", 'code' => 'premium_required'], 402);
}
rate_limit($pdo, 'lorey_voice:' . $visitorId, 400, 86400,
           "That's a lot of talking for one day — try again tomorrow.");

$body = json_decode((string)file_get_contents('php://input'), true);
$text = trim((string)($body['text'] ?? ''));
$text = preg_replace('/\s+/u', ' ', $text);
$text = mb_substr($text, 0, 600);
if ($text === '') error_response('Nothing to say.', 400);

/* The cache. Her greeting is the same sentence for everybody, so it should
   cost one render in total rather than one per listener. Keyed on the voice
   as well as the text, so changing voice does not serve the old one. */
$dir = sys_get_temp_dir() . '/lorey-voice';
if (!is_dir($dir)) @mkdir($dir, 0700, true);
$key  = hash('sha256', $voiceId . '|' . $text);
$file = $dir . '/' . $key . '.mp3';
if (is_file($file) && filesize($file) > 512) {
    header('Content-Type: audio/mpeg');
    header('Cache-Control: private, max-age=86400');
    readfile($file);
    exit;
}

$payload = json_encode([
    'text'     => $text,
    'model_id' => 'eleven_turbo_v2_5',
    'voice_settings' => [
        // Nuance, not a monotone: enough stability to stay recognisably her,
        // low enough that she is not flat, with style raised a little so the
        // phrasing moves.
        'stability'         => 0.42,
        'similarity_boost'  => 0.80,
        'style'             => 0.35,
        'use_speaker_boost' => true,
    ],
]);

$ch = curl_init('https://api.elevenlabs.io/v1/text-to-speech/' . rawurlencode($voiceId));
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 25,
    CURLOPT_HTTPHEADER     => [
        'xi-api-key: ' . LOREY_TTS_KEY,
        'Content-Type: application/json',
        'Accept: audio/mpeg',
    ],
]);
$audio = curl_exec($ch);
$code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err   = curl_error($ch);
curl_close($ch);

if ($audio === false || $code >= 400 || strlen((string)$audio) < 512) {
    // Never echo the provider's body: it carries request ids and account
    // details that are none of the client's business. The client falls back
    // to the device voice on any failure, so she still speaks.
    error_log('lorey_voice: provider ' . $code . ' ' . substr((string)$err, 0, 120));
    error_response('Could not render that right now.', 502);
}

@file_put_contents($file, $audio);
header('Content-Type: audio/mpeg');
header('Cache-Control: private, max-age=86400');
echo $audio;
