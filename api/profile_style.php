<?php
/**
 * POST /api/profile-style/:id — { kibbeTypeName, styleWords, kibbeResult,
 * styleBlend, authToken }
 *
 * Background sync of the "Style" summary shown on a profile, derived
 * entirely from that account's own Kibbe Quiz and Style Quiz results on
 * their device (see App()'s useEffect that calls Api.updateStyleSummary
 * whenever those results change) — never typed in by hand.
 *
 * kibbeResult/styleBlend are the full underlying quiz data (not just the
 * display summary in kibbeTypeName/styleWords) — stored so this account's
 * results can be reloaded on a new device without retaking either quiz,
 * and so two accounts can be compared axis-by-axis on the Compare screen.
 * Both are optional — older/updating clients that only send the summary
 * fields still work exactly as before, and either can be omitted or sent
 * as null to leave the previously-stored value untouched (nothing here
 * ever *clears* a value that wasn't included in this request).
 *
 * Deliberately a separate endpoint from profile.php's POST rather than
 * folding this into it: that endpoint always overwrites name/bio on every
 * call and requires a non-empty name, so a fire-and-forget quiz-completion
 * sync going through it would risk blanking out the person's real
 * name/bio (or failing outright, silently dropping the style sync) any
 * time it fired without those fields in hand.
 */
require_once __DIR__ . '/../includes/helpers.php';
require_method('POST');

$id = (string)($_GET['id'] ?? '');
$body = request_json();

if (!$id) error_response('Missing profile id.', 400);

$pdo = db();
require_owner($pdo, $id, (string)($body['authToken'] ?? ''));

$kibbeTypeName = $body['kibbeTypeName'] ?? null;
$kibbeTypeName = $kibbeTypeName ? mb_substr(trim((string)$kibbeTypeName), 0, 60) : null;

$styleWords = $body['styleWords'] ?? [];
if (!is_array($styleWords)) $styleWords = [];
// Keep this small and simple — a handful of short id strings, same shape
// as the app's STYLE_WORDS ids (e.g. "minimal", "romantic").
$styleWords = array_values(array_slice(array_map('strval', $styleWords), 0, 8));
$styleWordsJson = json_encode($styleWords);

// Full underlying quiz data — optional, and only touched when the caller
// actually sends it (see docblock above: omitting a field, or sending it
// as null, leaves whatever was previously stored alone rather than
// clearing it). Capped well above any real quiz's size as a sanity limit
// against a malformed/oversized payload.
const MAX_BLEND_JSON_BYTES = 20000;

$kibbeResultJson = null;
$hasKibbeResult = array_key_exists('kibbeResult', $body) && $body['kibbeResult'] !== null;
if ($hasKibbeResult) {
    if (!is_array($body['kibbeResult'])) error_response('kibbeResult must be an object.', 400);
    $kibbeResultJson = json_encode($body['kibbeResult']);
    if ($kibbeResultJson === false || strlen($kibbeResultJson) > MAX_BLEND_JSON_BYTES) {
        error_response('kibbeResult is too large or invalid.', 400);
    }
}

$styleBlendJson = null;
$hasStyleBlend = array_key_exists('styleBlend', $body) && $body['styleBlend'] !== null;
if ($hasStyleBlend) {
    if (!is_array($body['styleBlend'])) error_response('styleBlend must be an object.', 400);
    $styleBlendJson = json_encode($body['styleBlend']);
    if ($styleBlendJson === false || strlen($styleBlendJson) > MAX_BLEND_JSON_BYTES) {
        error_response('styleBlend is too large or invalid.', 400);
    }
}

$now = current_time_ms();

$stmt = $pdo->prepare('SELECT id FROM profiles WHERE id = ?');
$stmt->execute([$id]);
$existing = $stmt->fetch();

if ($existing) {
    $sets = ['kibbe_type_name = ?', 'style_words = ?', 'updated_at = ?'];
    $params = [$kibbeTypeName, $styleWordsJson, $now];
    if ($hasKibbeResult) { $sets[] = 'kibbe_result_json = ?'; $params[] = $kibbeResultJson; }
    if ($hasStyleBlend) { $sets[] = 'style_blend_json = ?'; $params[] = $styleBlendJson; }
    $params[] = $id;
    $upd = $pdo->prepare('UPDATE profiles SET ' . implode(', ', $sets) . ' WHERE id = ?');
    $upd->execute($params);
} else {
    // No profile row yet (this person has never opened "Edit profile") —
    // create a minimal one so the style summary has somewhere to live;
    // name/bio stay at their schema defaults until they do edit it.
    $ins = $pdo->prepare('INSERT INTO profiles (id, name, bio, avatar_url, kibbe_type_name, style_words, kibbe_result_json, style_blend_json, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $ins->execute([$id, '', '', null, $kibbeTypeName, $styleWordsJson, $kibbeResultJson, $styleBlendJson, $now]);
}

json_response(['ok' => true, 'kibbeTypeName' => $kibbeTypeName, 'styleWords' => $styleWords]);
