<?php
/**
 * POST /api/checker/photo — multipart form: photo (file), kibbeTypeName
 * (optional string), topStyleWords (optional JSON array string), wardrobe
 * (optional: women|men|both),
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
require_once __DIR__ . '/../includes/style_brief.php';

/**
 * Turns whatever the model wrote into the {verdict, headline, detail,
 * suggestion} array — tolerating a missing opening brace (we prefill it),
 * ```json fences, text around the object, and a reply cut off by
 * max_tokens (in which case the fields that did arrive are salvaged).
 */
function parse_stylist_json(string $text): ?array {
    $t = trim($text);
    $t = preg_replace('/^```(?:json)?\s*/i', '', $t);
    $t = preg_replace('/\s*```\s*$/', '', $t);
    if ($t === '') return null;
    if ($t[0] !== '{') $t = '{' . $t;
    $data = json_decode($t, true);
    if (is_array($data)) return $data;
    // Text around the object?
    if (preg_match('/\{.*\}/s', $t, $m)) {
        $data = json_decode($m[0], true);
        if (is_array($data)) return $data;
    }
    // Truncated: pull each field out individually.
    $out = [];
    foreach (['verdict', 'headline', 'detail', 'suggestion'] as $k) {
        if (preg_match('/"' . $k . '"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"?/s', $t, $m)) {
            $v = json_decode('"' . rtrim($m[1], '\\') . '"');
            if ($v === null) $v = stripslashes($m[1]);
            $out[$k] = trim((string)$v);
        }
    }
    return $out ?: null;
}

/** Last resort: make a non-JSON reply readable — no fences, braces, keys or stray quotes. */
function stylist_text_to_prose(string $text): string {
    $t = preg_replace('/```(?:json)?/i', '', $text);
    $t = preg_replace('/"(verdict|headline|detail|suggestion)"\s*:\s*/', '', $t);
    $t = str_replace(['{', '}'], '', $t);
    $t = preg_replace('/"\s*,\s*"/', ' ', $t);
    $t = trim(str_replace('"', '', $t), " \t\n\r,");
    return trim(preg_replace('/\s+/', ' ', $t));
}
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

$wardrobe = (string)($_POST['wardrobe'] ?? '');
if (!in_array($wardrobe, ['women', 'men', 'both'], true)) $wardrobe = '';

/* What the model is told about this person.
 *
 * Read from the profiles row first, and only fall back to what the request
 * carried. That ordering matters: the builds already in people's hands post
 * a type NAME and nothing else - no colour season, no closet - so pulling
 * from the row is what lets the stylist get better for everyone already on
 * 1.8 without shipping a new app.
 *
 * Until this existed, the model got the string "Flamboyant Gamine" and was
 * left to fill in the rest from whatever it had absorbed about Kibbe on the
 * internet, which is a system that is widely written about and widely
 * written about wrongly. See includes/style_brief.php. */
$ctx = style_profile($pdo, $visitorId);
if ($ctx['typeId'] === '' && $kibbeTypeName !== '') $ctx['typeName'] = $kibbeTypeName;
if (!$ctx['styleWords'] && $topStyleWords) $ctx['styleWords'] = $topStyleWords;
$context = style_brief_text($ctx, $wardrobe);

/* Their own wardrobe, so a fix can name a piece they already own rather
 * than sending them shopping. */
$closet = closet_lines($pdo, $visitorId, 40);
if ($closet) {
    $context .= "\n\nWHAT THEY ALREADY OWN (their logged closet - descriptions they typed themselves):\n  - "
              . implode("\n  - ", $closet)
              . "\nIf a piece here would fix or lift the outfit, name it exactly as written. Never claim they own "
              . "something that is not on this list.";
}

$instructions = <<<TXT
You are the AI Stylist inside Style-LORE. You are a working personal
stylist with a trained eye, not an image describer and not a cheerleader.
The attached photo may show a person, an outfit laid out on its own, or a
person wearing an outfit.

$context

HOW TO READ THE PHOTO
Look before you write. Name what is actually there: the garments and their
cut, the real colours (say "rust", "sage", "washed indigo", not "a warm
tone"), where the waist and hems fall, how the fabric hangs, the scale of
the details and accessories. If the photo is too dark or too cropped to
judge something, say which part you cannot see rather than guessing.

WHAT YOUR ANSWER MUST DO
1. Say what the outfit is doing to their LINES, in the plain language above
   - is it long and unbroken where they need broken, soft where they need
   crisp, oversized where the frame is compact? Name the piece that decides
   it. Never use the words yin, yang, or a type letter, and never quote the
   rules back at them as a list.
2. Say something specific about the COLOUR against their season - whether it
   sits in their range, and which garment is carrying or breaking it. Skip
   this only if no colour season is on file.
3. Give exactly ONE fix they could make tonight. A swap, a tuck, a hem, a
   different shoe, a piece removed. If something they already own would do
   it, name that piece. "Try accessorising" and "consider a belt" are
   failures; "swap the flat sandals for the red ankle boots you own - the
   shorter, harder shoe breaks the line the maxi is running away with" is
   the standard.

Be honest. If it works, say precisely why, in a way they could repeat next
time. If it does not, say so plainly and warmly - they paid for a real
opinion, and vague approval is the one thing that makes this worthless. If
it half works, name the piece that is carrying it and the piece that is
fighting it.

Write like a person talking, not like a report. No bullet points, no
headings, no hedging stacks ("you might perhaps consider"). Do not open with
"This outfit" or "Great choice". Vary how you start.

VERDICT
  "match"    - the lines and the colours both suit them; a fix would refine it.
  "caution"  - something real is off, but one change would fix it.
  "mismatch" - the silhouette or the palette genuinely fights them.

Respond with ONLY a single JSON object, no other text:
{"verdict": "match" | "caution" | "mismatch", "headline": "under 12 words, specific to THIS photo, no generic praise", "detail": "3-5 sentences covering the lines and the colour, naming real garments and shades you can see", "suggestion": "one concrete change they could make tonight, naming a piece from their closet where one fits"}
TXT;

$payload = [
    'model' => $model,
    'max_tokens' => 1200,
    'messages' => [
        [
            'role' => 'user',
            'content' => [
                ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $imageBase64]],
                ['type' => 'text', 'text' => $instructions],
            ],
        ],
        // Prefill the reply with the opening brace so the model continues
        // the JSON object directly instead of wrapping it in ```json fences
        // or a preamble — which is what used to reach the screen raw.
        ['role' => 'assistant', 'content' => '{'],
    ],
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

    $verdictData = parse_stylist_json($text);
    if (!is_array($verdictData) || empty($verdictData['headline'])) {
        // The model didn't return usable JSON — show its words as prose
        // rather than failing outright, but never raw braces and quotes.
        $prose = stylist_text_to_prose($text);
        $verdictData = ['verdict' => 'caution', 'headline' => 'A stylist\'s read on this photo', 'detail' => $prose ?: "Couldn't get a clear read on that photo — try a clearer, well-lit shot.", 'suggestion' => ''];
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
