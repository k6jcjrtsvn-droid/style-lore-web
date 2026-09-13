<?php
/**
 * POST /api/checker/photo — multipart form: photo (file), kibbeTypeName
 * (optional string), topStyleWords (optional JSON array string),
 * visitorId, authToken.
 *
 * STYLE-LORE PREMIUM FEATURE ("AI Stylist"). The free on-device photo
 * checker (GarmentOnDeviceAnalyzer + photoStyleVerdict in index.html)
 * covers everyone with no server cost and no Anthropic key — this
 * endpoint is the paid upgrade on top of that: a real Claude vision call
 * that actually looks at the photo (colors, cut, fabric drape, fit) and
 * gives specific, plain-language styling feedback the on-device heuristic
 * fundamentally can't (it only estimates four coarse axes from edge/color
 * pixel statistics, never anything close to genuine visual judgment).
 *
 * Requires login (visitorId + authToken, checked via require_owner()) and
 * an active Style-LORE Premium subscription (has_premium(), synced from
 * RevenueCat's webhook — see api/revenuecat_webhook.php) before any API
 * call is made — every call here spends real per-use money, unlike the
 * free, on-device text/photo checker.
 *
 * Gracefully disabled with a clean 503 ("ai_unavailable") if Kenneth
 * hasn't added a real ANTHROPIC_API_KEY to config.php yet — separate from
 * the 402 "premium_required" a non-premium account gets, so the frontend
 * can tell "you need to upgrade" apart from "this is temporarily down"
 * and show the right message for each.
 */
require_once __DIR__ . '/../includes/helpers.php';
require_method('POST');

$visitorId = (string)($_POST['visitorId'] ?? '');
$authToken = (string)($_POST['authToken'] ?? '');
if (!$visitorId || !$authToken) {
    error_response('You need to be signed in to use the AI Stylist.', 401);
}

$pdo = db();
require_owner($pdo, $visitorId, $authToken);

// One free read per account, ever: the best paywall is a taste of the
// real thing. After that it's Premium. Premium reads are rate-limited
// per day too, purely to cap abuse of a per-call-cost feature.
$isPremium = has_premium($pdo, $visitorId);
$freeLeft = free_ai_reads_left($pdo, $visitorId);
if (!$isPremium && $freeLeft <= 0) {
    json_response(['error' => 'The AI Stylist is a Style-LORE Premium feature.', 'code' => 'premium_required'], 402);
}
if ($isPremium) {
    rate_limit($pdo, 'ai_read:' . $visitorId, 40, 86400, "That's a lot of stylist reads for one day — try again tomorrow.");
}

// Defensive: config.sample.php doesn't (yet) declare these constants, and
// an older deployed config.php might not either — check with defined()
// rather than assuming, so a missing constant is "not set up yet" (503)
// rather than a fatal PHP error.
$apiKey = defined('ANTHROPIC_API_KEY') ? trim((string)ANTHROPIC_API_KEY) : '';
if ($apiKey === '') {
    json_response(['error' => "The AI Stylist isn't set up yet.", 'code' => 'ai_unavailable'], 503);
}
// claude-sonnet-5 by default — meaningfully stronger vision judgment than
// the haiku tier this endpoint used before, worth the extra per-call cost
// now that it's a paid feature people expect real quality from. Still
// overridable via ANTHROPIC_MODEL in config.php.
$model = (defined('ANTHROPIC_MODEL') && ANTHROPIC_MODEL) ? ANTHROPIC_MODEL : 'claude-sonnet-5';

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
You are the AI Stylist inside the Style-LORE app — a warm, sharp-eyed
personal stylist, not a generic image describer. The attached photo may
show a person, an outfit on its own, or a person wearing an outfit. $context

This is a paid feature — the person looking at your answer expects real,
specific expertise, not vague encouragement. Actually look closely at what's
in the photo: the exact colors and how they interact, the cut and
silhouette, proportions, fabric weight/drape if you can tell, styling
details (layering, accessories, hemlines, necklines), and how well all of
that lines up with their Kibbe type and style words above. Name the actual
colors and garment details you see rather than speaking generically — "the
cropped denim jacket over a fitted rust midi dress" beats "your outfit."

Be honest, not just flattering — if something works, say specifically why;
if something's off (proportion, color clash, a silhouette that fights their
type), say so plainly and say what would fix it. Every response must
include one concrete, specific, actionable suggestion — a swap, an
addition, or a styling tweak they could actually make — never a vague "try
accessorizing more."

Respond with ONLY a single JSON object, no other text, in exactly this
shape:
{"verdict": "match" | "caution" | "mismatch", "headline": "one short punchy line, under 12 words, specific to this photo", "detail": "2-4 sentences of specific, plain-language reasoning naming actual colors/garments/proportions you observed", "suggestion": "one concrete, specific styling change or affirmation — a real swap, addition, or adjustment"}
TXT;

$payload = [
    'model' => $model,
    'max_tokens' => 700,
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
        $verdictData = ['verdict' => 'caution', 'headline' => 'Here\'s what the AI saw', 'detail' => $text ?: "Couldn't get a clear read on that photo — try a clearer, well-lit shot.", 'suggestion' => ''];
    }
    $verdict = in_array($verdictData['verdict'] ?? '', ['match', 'caution', 'mismatch'], true) ? $verdictData['verdict'] : 'caution';

    record_ai_read($pdo, $visitorId);
    json_response([
        'freeReadsLeft' => $isPremium ? null : max(0, $freeLeft - 1),
        'verdict' => $verdict,
        'headline' => mb_substr((string)($verdictData['headline'] ?? ''), 0, 140),
        'detail' => mb_substr((string)($verdictData['detail'] ?? ''), 0, 800),
        'suggestion' => mb_substr((string)($verdictData['suggestion'] ?? ''), 0, 300),
    ]);
} catch (Throwable $e) {
    error_log('Checker photo failed: ' . $e->getMessage());
    error_response("Couldn't run the AI Stylist right now — try again in a moment.", 500);
}
