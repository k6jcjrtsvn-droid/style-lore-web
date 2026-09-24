<?php
/**
 * POST /api/stylist/outfits — {visitorId, authToken, outfits:[...], profile:{...}}
 *
 * The Premium half of "Style me". The device has already assembled the
 * outfits (buildOutfit / autoBuildOutfits in index.html, instant and free);
 * this asks a model to say *why* each one works, in a sentence a person
 * would actually want to read.
 *
 * Splitting it this way is deliberate. Picking pieces is arithmetic and the
 * on-device rubric already does it well, offline and for nothing. What a
 * language model adds is language. So the picks never depend on this call,
 * and if it fails, is capped, or the key is missing, the person still has
 * their outfits — they just don't have the commentary. That is why every
 * error path here returns 'fallback' rather than an error the UI has to
 * apologise for.
 *
 * Cost control, in three layers:
 *   1. Premium only.
 *   2. A soft cap of STYLIST_MONTHLY_CAP writes per 30 days per account,
 *      via the existing rate_limits table. Hitting it is not an error — the
 *      response says so and the app keeps using the free on-device version.
 *   3. Only the chosen garments are sent, never the whole closet: about 40
 *      tokens per outfit instead of 25 per closet item. A 300-item closet
 *      costs the same as a 20-item one.
 *
 * Nothing about the closet is stored here and no photo is ever sent — the
 * model sees garment descriptions the person typed, and nothing else.
 */
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/style_brief.php';

require_method('POST');

const STYLIST_MONTHLY_CAP = 30;

$body = request_json();
$visitorId = (string)($body['visitorId'] ?? '');
$authToken = (string)($body['authToken'] ?? '');
if ($visitorId === '' || $authToken === '') error_response('Sign in to use the stylist.', 401);

$pdo = db();
require_owner($pdo, $visitorId, $authToken);

if (!has_premium($pdo, $visitorId)) {
    json_response(['ok' => false, 'code' => 'not_premium',
        'message' => 'Written suggestions are part of Premium.'], 403);
}

$apiKey = defined('ANTHROPIC_API_KEY') ? trim((string)ANTHROPIC_API_KEY) : '';
if ($apiKey === '') {
    // Not an error the person should ever see — the outfits are already on
    // their screen.
    json_response(['ok' => false, 'code' => 'fallback']);
}

/* The cap. rate_limit() throws a 429 of its own, which is the wrong shape
 * here: running out of written suggestions should read as "you've used this
 * month's", not as a failure. So we count by hand and answer calmly. */
$capKey = 'stylist_outfits:' . $visitorId;
$now = time();
$window = 30 * 86400;
$used = 0;
try {
    $st = $pdo->prepare('SELECT hits, window_start FROM rate_limits WHERE rl_key = ?');
    $st->execute([$capKey]);
    $row = $st->fetch();
    if ($row && ($now - (int)$row['window_start']) < $window) $used = (int)$row['hits'];
} catch (Throwable $e) { /* if the table is unreadable, don't block the feature */ }

if ($used >= STYLIST_MONTHLY_CAP) {
    json_response(['ok' => false, 'code' => 'capped', 'used' => $used, 'cap' => STYLIST_MONTHLY_CAP,
        'message' => "You've used this month's written suggestions. Your outfits are still here — the stylist's notes come back next month."]);
}

/* ---- what we send -------------------------------------------------- */
$outfits = is_array($body['outfits'] ?? null) ? array_slice($body['outfits'], 0, 5) : [];
if (!$outfits) error_response('No outfits to write about.', 400);

$lines = [];
foreach ($outfits as $i => $o) {
    $pieces = [];
    foreach (array_slice(is_array($o['pieces'] ?? null) ? $o['pieces'] : [], 0, 8) as $p) {
        $label = mb_substr(trim((string)($p['category'] ?? '')), 0, 20);
        $desc  = mb_substr(trim((string)($p['description'] ?? '')), 0, 120);
        if ($desc !== '') $pieces[] = ($label !== '' ? $label . ': ' : '') . $desc;
    }
    if ($pieces) $lines[] = ($i + 1) . '. ' . implode(' | ', $pieces);
}
if (!$lines) error_response('No outfits to write about.', 400);

$profile = is_array($body['profile'] ?? null) ? $body['profile'] : [];
$weather = mb_substr(trim((string)($body['weather'] ?? '')), 0, 60);

/* The type, colour season and style words come from the profiles row, not
 * from the request. The apps already shipped send a type NAME and nothing
 * else - no season - so reading server-side is what lets this get better for
 * people who are not on the newest build. The posted values are only a
 * fallback for an account whose quiz has never synced. */
$ctx = style_profile($pdo, $visitorId);
if ($ctx['typeId'] === '' && $ctx['typeName'] === '') {
    $ctx['typeName'] = mb_substr(trim((string)($profile['kibbeTypeName'] ?? '')), 0, 40);
}
if (!$ctx['styleWords'] && is_array($profile['styleWords'] ?? null)) {
    foreach (array_slice($profile['styleWords'], 0, 4) as $w) {
        $w = mb_substr(trim((string)$w), 0, 24);
        if ($w !== '') $ctx['styleWords'][] = $w;
    }
}
$brief = style_brief_text($ctx, (string)($profile['wardrobe'] ?? ''));

$outfitBlock = implode("\n", $lines);
$n = count($lines);
$weatherLine = $weather !== '' ? "\n\nTODAY'S WEATHER: {$weather}. A note may mention it only if it actually changes what to wear." : '';

$instructions = <<<TXT
You are a working personal stylist. A client has been handed {$n} outfits
built from their own wardrobe, and you are writing the note that goes under
each one.

{$brief}{$weatherLine}

THE OUTFITS, each a list of pieces they own:
{$outfitBlock}

For each outfit, write ONE sentence of 14-30 words that names a specific
piece and says what it is doing to their lines or their colour - the reason
this combination works, in words they could use again next time. Do not
list the pieces back. Do not start two notes the same way. Do not mention
brands or invent garments. No hedging, no "a great choice".

If a combination is weaker, say which piece is carrying it and which one is
fighting it, rather than pretending it is perfect. That honesty is the
product.

Then pick the ONE outfit you would actually put them in today and say why
in a sentence, and name one swap that would lift any of the {$n} - a piece
from their wardrobe above, or a specific thing to change.

Respond with ONLY this JSON object:
{"notes": [{$n} strings, in the same order as the outfits], "best": <the 1-based number of the outfit you would put them in>, "bestWhy": "one sentence", "swap": "one concrete swap, naming pieces"}
TXT;

$payload = [
    'model' => (defined('ANTHROPIC_MODEL') && ANTHROPIC_MODEL) ? ANTHROPIC_MODEL : 'claude-sonnet-5',
    'max_tokens' => 900,
    'messages' => [
        ['role' => 'user', 'content' => $instructions],
        // Prefilled so the reply starts inside the object and can't preamble.
        ['role' => 'assistant', 'content' => '{'],
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
$responseBody = curl_exec($ch);
$curlErr = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($responseBody === false || $httpCode !== 200) {
    error_log('Stylist outfits: HTTP ' . $httpCode . ' ' . $curlErr . ' ' . substr((string)$responseBody, 0, 300));
    json_response(['ok' => false, 'code' => 'fallback']);
}

$decoded = json_decode((string)$responseBody, true);
$text = '';
foreach (($decoded['content'] ?? []) as $block) {
    if (($block['type'] ?? '') === 'text') $text .= $block['text'];
}
$text = trim($text);
if ($text === '') json_response(['ok' => false, 'code' => 'fallback']);
if ($text[0] !== '{') $text = '{' . $text;                 // undo the prefill
$reply = json_decode($text, true);
if (!is_array($reply) || !is_array($reply['notes'] ?? null)) {
    error_log('Stylist outfits: unparseable reply: ' . substr($text, 0, 300));
    json_response(['ok' => false, 'code' => 'fallback']);
}
$notes = $reply['notes'];

$tidy = static function ($v, int $max): string {
    return mb_substr(trim(preg_replace('/\s+/', ' ', (string)$v)), 0, $max);
};
$clean = [];
foreach (array_slice($notes, 0, $n) as $note) {
    $clean[] = $tidy($note, 300);
}
// best/bestWhy/swap are additive: builds already in people's hands read
// `notes` and ignore anything else, so this needs no app release.
$best = (int)($reply['best'] ?? 0);
if ($best < 1 || $best > $n) $best = 0;
$bestWhy = $tidy($reply['bestWhy'] ?? '', 260);
$swap    = $tidy($reply['swap'] ?? '', 260);
// A short reply is fine — the UI shows notes where it has them and leaves
// the rest as they were.
if (!$clean) json_response(['ok' => false, 'code' => 'fallback']);

// Only a call that produced something counts against the cap.
try {
    $st = $pdo->prepare('SELECT hits, window_start FROM rate_limits WHERE rl_key = ?');
    $st->execute([$capKey]);
    $row = $st->fetch();
    if (!$row || ($now - (int)$row['window_start']) >= $window) {
        $pdo->prepare('INSERT INTO rate_limits (rl_key, hits, window_start) VALUES (?, 1, ?)
                       ON DUPLICATE KEY UPDATE hits = 1, window_start = VALUES(window_start)')
            ->execute([$capKey, $now]);
        $used = 1;
    } else {
        $used = (int)$row['hits'] + 1;
        $pdo->prepare('UPDATE rate_limits SET hits = ? WHERE rl_key = ?')->execute([$used, $capKey]);
    }
} catch (Throwable $e) { /* never fail a good response over the counter */ }

json_response(['ok' => true, 'notes' => $clean, 'best' => $best, 'bestWhy' => $bestWhy,
    'swap' => $swap, 'used' => $used, 'cap' => STYLIST_MONTHLY_CAP]);
