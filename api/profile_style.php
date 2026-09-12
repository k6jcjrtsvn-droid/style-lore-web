<?php
/**
 * POST /api/profile-style/:id — { kibbeTypeName, styleWords, authToken }
 *
 * Background sync of the "Style" summary shown on a profile, derived
 * entirely from that account's own Kibbe Quiz and Style Quiz results on
 * their device (see App()'s useEffect that calls Api.updateStyleSummary
 * whenever those results change) — never typed in by hand.
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

$now = current_time_ms();

$stmt = $pdo->prepare('SELECT id FROM profiles WHERE id = ?');
$stmt->execute([$id]);
$existing = $stmt->fetch();

if ($existing) {
    $upd = $pdo->prepare('UPDATE profiles SET kibbe_type_name = ?, style_words = ?, updated_at = ? WHERE id = ?');
    $upd->execute([$kibbeTypeName, $styleWordsJson, $now, $id]);
} else {
    // No profile row yet (this person has never opened "Edit profile") —
    // create a minimal one so the style summary has somewhere to live;
    // name/bio stay at their schema defaults until they do edit it.
    $ins = $pdo->prepare('INSERT INTO profiles (id, name, bio, avatar_url, kibbe_type_name, style_words, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $ins->execute([$id, '', '', null, $kibbeTypeName, $styleWordsJson, $now]);
}

json_response(['ok' => true, 'kibbeTypeName' => $kibbeTypeName, 'styleWords' => $styleWords]);
