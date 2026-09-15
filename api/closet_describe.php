<?php
/**
 * POST /api/closet_describe.php — multipart form: photo (file), visitorId,
 * authToken, wardrobe (optional: women|men|both).
 *
 * "Describe it for me" in the Add-to-closet sheet. Looks at a garment
 * photo once and returns a short, closet-ready description ("Black leather
 * biker jacket, silver zips, cropped") so the person only has to confirm
 * instead of typing. Free for every signed-in account (it's a cheap
 * vision call on the small model and it removes the biggest reason people
 * abandon the closet — the typing), rate-limited per day to cap abuse.
 *
 * Privacy: this is the ONE closet path that sends a photo off the phone,
 * and only when the person taps the button. The photo is not stored —
 * it goes to the model and is discarded. The sheet says so next to the
 * button.
 *
 * Falls back to 503 "ai_unavailable" if ANTHROPIC_API_KEY isn't set, so
 * the button simply hides itself (the frontend probes nothing — it just
 * handles the 503 by telling the person to type instead).
 */
require_once __DIR__ . '/../includes/helpers.php';

require_method('POST');

$visitorId = (string)($_POST['visitorId'] ?? '');
$authToken = (string)($_POST['authToken'] ?? '');
if (!$visitorId || !$authToken) {
    error_response('Sign in to have items described for you.', 401);
}

$pdo = db();
require_owner($pdo, $visitorId, $authToken);
rate_limit($pdo, 'closet_describe:' . $visitorId, 60, 86400, "That's a lot of items for one day — type this one in and try again tomorrow.");

$apiKey = defined('ANTHROPIC_API_KEY') ? trim((string)ANTHROPIC_API_KEY) : '';
if ($apiKey === '') {
    json_response(['error' => "Auto-describe isn't set up yet — type a description instead.", 'code' => 'ai_unavailable'], 503);
}
// Small, fast model for a one-line caption; overridable. If the cheap
// model name is ever retired, retry once on the main model.
$cheapModel = (defined('ANTHROPIC_CHEAP_MODEL') && ANTHROPIC_CHEAP_MODEL) ? ANTHROPIC_CHEAP_MODEL : 'claude-haiku-4-5';
$mainModel = (defined('ANTHROPIC_MODEL') && ANTHROPIC_MODEL) ? ANTHROPIC_MODEL : 'claude-sonnet-5';

if (!isset($_FILES['photo']) || $_FILES['photo']['error'] === UPLOAD_ERR_NO_FILE) {
    error_response('Add a photo first.', 400);
}
$file = $_FILES['photo'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    error_response('Photo upload failed — try again.', 400);
}
if ($file['size'] > 4 * 1024 * 1024) {
    error_response('That photo is too large — try a smaller one.', 400);
}
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']) ?: '';
finfo_close($finfo);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    error_response('Photo must be a JPEG, PNG, or WEBP image.', 400);
}
$imageData = file_get_contents($file['tmp_name']);
if ($imageData === false) {
    error_response("Couldn't read that photo — try again.", 400);
}
$imageBase64 = base64_encode($imageData);

$wardrobe = (string)($_POST['wardrobe'] ?? '');
$wardrobeNote = '';
if ($wardrobe === 'men') $wardrobeNote = ' Use menswear vocabulary (e.g. oxford shirt, chinos, crewneck, blazer).';
elseif ($wardrobe === 'women') $wardrobeNote = ' Use womenswear vocabulary where it applies.';

$instructions = <<<TXT
You write one-line closet labels for a wardrobe app. Look at the garment in the photo and describe it the way a careful person would label it in their closet: color(s), garment type, and 1–3 distinguishing details (cut, length, fabric, pattern, notable hardware). Examples of the exact style wanted:
- "Camel wool coat, straight cut, knee length"
- "Black leather biker jacket, silver zips"
- "Navy slim chinos, cropped"
- "White cotton oxford shirt, button-down collar"
Rules: 4–12 words, no sentence, no brand guesses, no opinions, no "photo of". If several garments show, describe the main one. If there is no clothing in the photo, respond with an empty description.$wardrobeNote
Respond with ONLY a JSON object: {"description": "..."}
TXT;

function closet_describe_call(string $apiKey, string $model, string $mime, string $imageBase64, string $instructions): array {
    $payload = [
        'model' => $model,
        'max_tokens' => 120,
        'messages' => [
            ['role' => 'user', 'content' => [
                ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $imageBase64]],
                ['type' => 'text', 'text' => $instructions],
            ]],
            ['role' => 'assistant', 'content' => '{"description": "'],
        ],
    ];
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body === false ? null : (string)$body, $err];
}

try {
    [$httpCode, $responseBody, $curlErr] = closet_describe_call($apiKey, $cheapModel, $mime, $imageBase64, $instructions);
    if ($httpCode === 404 || $httpCode === 400) {
        // Model name not available on this key — try the main model once.
        [$httpCode, $responseBody, $curlErr] = closet_describe_call($apiKey, $mainModel, $mime, $imageBase64, $instructions);
    }
    if ($responseBody === null) {
        error_log('Closet describe: cURL error: ' . $curlErr);
        error_response("Couldn't reach the AI service right now — type a description instead.", 502);
    }
    $decoded = json_decode($responseBody, true);
    if ($httpCode !== 200 || !is_array($decoded)) {
        error_log('Closet describe: API error (' . $httpCode . '): ' . substr($responseBody, 0, 300));
        error_response("Couldn't reach the AI service right now — type a description instead.", 502);
    }
    $text = '';
    foreach (($decoded['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') $text .= $block['text'];
    }
    // We prefilled '{"description": "' so the model continues with the
    // value; tolerate a full object too.
    $desc = '';
    $t = trim($text);
    if ($t !== '' && $t[0] !== '{' && strpos($t, '"description"') === false) {
        $t = '{"description": "' . $t;
    }
    if (preg_match('/"description"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"?/s', $t, $m)) {
        $v = json_decode('"' . rtrim($m[1], '\\') . '"');
        $desc = is_string($v) ? $v : stripslashes($m[1]);
    }
    $desc = trim(preg_replace('/\s+/', ' ', (string)$desc), " \t\n\r.\"'");
    if ($desc !== '') $desc = mb_strtoupper(mb_substr($desc, 0, 1)) . mb_substr($desc, 1);
    json_response(['description' => mb_substr($desc, 0, 200)]);
} catch (Throwable $e) {
    error_log('Closet describe failed: ' . $e->getMessage());
    error_response("Couldn't describe that photo right now — type a description instead.", 500);
}
