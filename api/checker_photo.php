<?php
/**
 * POST /api/checker/photo — multipart form: photo (file), kibbeTypeName
 * (optional string), topStyleWords (optional JSON array string),
 * visitorId, authToken.
 *
 * NOT CALLED BY THE FRONTEND ANYMORE as of the on-device photo checker
 * (see GarmentOnDeviceAnalyzer + photoStyleVerdict in index.html): the
 * Checker's photo path now runs entirely client-side (edge/color/
 * silhouette heuristics feeding the same STYLE_AXES rubric the text
 * checker uses) so it works with no Anthropic key and no per-check API
 * cost. This endpoint is left in place, working, and still gated behind
 * ANTHROPIC_API_KEY being configured, in case a real vision-AI opinion
 * is deliberately wired back in later — it just isn't reachable from the
 * app's UI right now.
 *
 * Real vision-AI version of the Checker: the on-device text checker
 * (styleCheckVerdict() in index.html) reads a typed description; this
 * endpoint instead looks at an actual uploaded photo — of a person, an
 * outfit, or a person wearing an outfit — using Anthropic's Claude API,
 * and returns a plain-language verdict the same way.
 *
 * Requires login (visitorId + authToken, checked via require_owner())
 * specifically so this can't be hit anonymously — every call here spends
 * real API cost, unlike the free, on-device text checker.
 *
 * Gracefully disabled rather than broken when ANTHROPIC_API_KEY isn't
 * configured yet: returns a 503 with code "ai_unavailable" that the
 * frontend already knows to render as a calm "not set up yet" message
 * (see Api.checkPhoto() in index.html) instead of a raw error. Once a
 * real key (starts with "sk-ant-api03-") is added to config.php, this
 * endpoint lights up with no other changes needed.
 */
require_once __DIR__ . '/../includes/helpers.php';
require_method('POST');

$visitorId = (string)($_POST['visitorId'] ?? '');
$authToken = (string)($_POST['authToken'] ?? '');
if (!$visitorId || !$authToken) {
    error_response('You need to be signed in to use the photo checker.', 401);
}

$pdo = db();
require_owner($pdo, $visitorId, $authToken);

// Defensive: config.sample.php doesn't (yet) declare these constants, and
// an older deployed config.php might not either — check with defined()
// rather than assuming, so a missing constant is "not set up yet" (503)
// rather than a fatal PHP error.
$apiKey = defined('ANTHROPIC_API_KEY') ? trim((string)ANTHROPIC_API_KEY) : '';
if ($apiKey === '') {
    json_response(['error' => "AI photo check isn't set up yet.", 'code' => 'ai_unavailable'], 503);
}
$model = (defined('ANTHROPIC_MODEL') && ANTHROPIC_MODEL) ? ANTHROPIC_MODEL : 'claude-haiku-4-5-20251001';

if (!isset($_FILES['photo']) || $_FILES['photo']['error'] === UPLOAD_ERR_NO_FILE) {
    error_response('Add a photo to check.', 400);
}
$file = $_FILES['photo'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    error_response('Photo upload failed — try again.', 400);
}
// The frontend already downsizes with downscaleImage() before uploading
// (same pattern as adding a Closet item), so this is a sanity ceiling,
// not the normal case — 8MB keeps the base64-encoded payload well under
// typical API request-size limits.
if ($file['size'] > 8 * 1024 * 1024) {
    error_response('That photo is too large — try a smaller one.', 400);
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']) ?: '';
finfo_close($finfo);
$allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
if (!in_array($mime, $allowedMimes, true)) {
    error_response('Photo must be a JPEG, PNG, or WEBP image.', 400);
}

$imageData = file_get_contents($file['tmp_name']);
if ($imageData === false) {
    error_response("Couldn't read that photo — try again.", 400);
}
$imageBase64 = base64_encode($imageData);

$kibbeTypeName = trim((string)($_POST['kibbeTypeName'] ?? ''));
$topStyleWordsRaw = (string)($_POST['topStyleWords'] ?? '[]');
$topStyleWords = json_decode($topStyleWordsRaw, true);
if (!is_array($topStyleWords)) $topStyleWords = [];
$topStyleWords = array_slice(array_map('strval', $topStyleWords), 0, 8);

$context = 'No Kibbe type or style-quiz words are on file for this person yet — judge the photo on general fit and styling principles instead.';
if ($kibbeTypeName || $topStyleWords) {
    $parts = [];
    if ($kibbeTypeName) $parts[] = "their Kibbe body type is \"$kibbeTypeName\"";
    if ($topStyleWords) $parts[] = 'their own stated style words are: ' . implode(', ', $topStyleWords);
    $context = 'Context on this person: ' . implode('; ', $parts) . '.';
}

$instructions = <<<TXT
You are a warm, direct personal styling assistant inside the Style-LORE app.
The attached photo may show a person, an outfit on its own, or a person
wearing an outfit. $context

Judge how well what's shown works for this person — silhouette, fit,
proportion, and (if relevant) how it lines up with their Kibbe type and
style words above. Be specific about what you actually see (colors, cut,
fit) rather than generic. Keep it encouraging but honest — call out real
mismatches, don't just flatter.

Respond with ONLY a single JSON object, no other text, in exactly this
shape:
{"verdict": "match" | "caution" | "mismatch", "headline": "one short punchy line, under 12 words", "detail": "2-4 sentences of specific, plain-language reasoning a real person would say out loud"}
TXT;

$payload = [
    'model' => $model,
    'max_tokens' => 500,
    'messages' => [[
        'role' => 'user',
        'content' => [
            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $imageBase64]],
            ['type' => 'text', 'text' => $instructions],
        ],
    ]],
];

try {
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
        CURLOPT_TIMEOUT => 45,
    ]);
    $responseBody = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($responseBody === false) {
        error_log('Checker photo: cURL error: ' . $curlErr);
        error_response("Couldn't reach the AI service right now — try again in a moment.", 502);
    }
    $decoded = json_decode($responseBody, true);
    if ($httpCode !== 200 || !is_array($decoded)) {
        error_log('Checker photo: API error (' . $httpCode . '): ' . substr((string)$responseBody, 0, 500));
        error_response("Couldn't reach the AI service right now — try again in a moment.", 502);
    }

    $text = '';
    if (!empty($decoded['content']) && is_array($decoded['content'])) {
        foreach ($decoded['content'] as $block) {
            if (($block['type'] ?? '') === 'text') $text .= $block['text'];
        }
    }

    $verdictData = json_decode(trim($text), true);
    if (!is_array($verdictData) || empty($verdictData['headline'])) {
        // The model didn't return clean JSON — fall back to showing its
        // raw text as the detail rather than failing outright.
        $verdictData = ['verdict' => 'caution', 'headline' => 'Here\'s what the AI saw', 'detail' => $text ?: "Couldn't get a clear read on that photo — try a clearer, well-lit shot."];
    }
    $verdict = in_array($verdictData['verdict'] ?? '', ['match', 'caution', 'mismatch'], true) ? $verdictData['verdict'] : 'caution';

    json_response([
        'verdict' => $verdict,
        'headline' => mb_substr((string)($verdictData['headline'] ?? ''), 0, 140),
        'detail' => mb_substr((string)($verdictData['detail'] ?? ''), 0, 800),
    ]);
} catch (Throwable $e) {
    error_log('Checker photo failed: ' . $e->getMessage());
    error_response("Couldn't run the photo check right now — try again in a moment.", 500);
}
