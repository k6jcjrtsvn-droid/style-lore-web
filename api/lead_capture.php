<?php
/**
 * "Email me my result" — the signed-out capture.
 *
 * The quiz deliberately works without an account. That is the right call for
 * organic traffic and a hole in the bucket for paid: someone arrives on a
 * bought click, takes the quiz, reads their type and leaves, and the click is
 * gone with nothing recorded. This endpoint is the lighter-than-signup ask
 * that turns that visitor into something we can count and follow up.
 *
 * It creates NO account and grants nothing. It stores an address, the result
 * that address asked for, and where they came from, then sends them that one
 * result. If they later sign up, auth_signup.php stamps converted_at.
 */
require_once __DIR__ . '/../includes/helpers.php';
require_method('POST');

$body  = request_json();
$email = normalize_email($body['email'] ?? '');
if (!is_valid_email($email)) error_response('Enter a valid email address.', 400);

// A closed set on both, so a stranger cannot write arbitrary text into an id
// column that the admin tab renders and the email turns into a link.
$kibbe = trim((string)($body['kibbeId'] ?? ''));
if ($kibbe !== '' && !in_array($kibbe, style_lore_kibbe_ids(), true)) $kibbe = '';
$season = trim((string)($body['seasonId'] ?? ''));
if ($season !== '' && !in_array($season, style_lore_season_ids(), true)) $season = '';
if ($kibbe === '' && $season === '') error_response('No result to send yet — finish the quiz first.', 400);

$pdo = db();
ensure_leads_table($pdo);
rate_limit($pdo, 'lead:ip:' . client_ip(), 10, 3600, 'Too many requests from this connection — please try again later.');

$now = current_time_ms();

// Upsert on the address. Someone who retakes the quiz and asks again should
// get the newer result, not a duplicate row and not a silent no-op -- but a
// later ask must never clear an attribution the first one recorded, which is
// what a plain overwrite would do once the utm has fallen out of the URL.
try {
    $pdo->prepare(
        'INSERT INTO leads (email, created_at, kibbe_id, season_id, source, medium, campaign, referrer, device)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            kibbe_id  = COALESCE(VALUES(kibbe_id), kibbe_id),
            season_id = COALESCE(VALUES(season_id), season_id),
            source    = COALESCE(source, VALUES(source)),
            medium    = COALESCE(medium, VALUES(medium)),
            campaign  = COALESCE(campaign, VALUES(campaign)),
            referrer  = COALESCE(referrer, VALUES(referrer))'
    )->execute([
        $email,
        $now,
        $kibbe !== '' ? $kibbe : null,
        $season !== '' ? $season : null,
        normalize_attribution($body['source'] ?? null),
        normalize_attribution($body['medium'] ?? null),
        normalize_attribution($body['campaign'] ?? null),
        normalize_attribution($body['referrer'] ?? null, 255),
        normalize_device($body['device'] ?? null),
    ]);
} catch (Throwable $e) {
    error_log('lead_capture insert: ' . $e->getMessage());
    error_response("Couldn't save that — try again.", 500);
}

/** "soft-classic" -> "Soft Classic". Derived rather than looked up, so this
 *  can never drift out of step with the id lists in helpers.php. */
function lead_pretty(string $id): string {
    return ucwords(str_replace('-', ' ', $id));
}

$rows = '';
if ($kibbe !== '') {
    $name = htmlspecialchars(lead_pretty($kibbe));
    $url  = SITE_BASE_URL . '/types/' . rawurlencode($kibbe) . '.html';
    $rows .= "<p style=\"margin:0 0 14px;\"><strong>Your lines:</strong> $name<br>"
           . "<a href=\"$url\" style=\"color:#C92C69;\">Read the $name guide</a></p>";
}
if ($season !== '') {
    $name = htmlspecialchars(lead_pretty($season));
    $url  = SITE_BASE_URL . '/colors/' . rawurlencode($season) . '.html';
    $rows .= "<p style=\"margin:0 0 14px;\"><strong>Your colour season:</strong> $name<br>"
           . "<a href=\"$url\" style=\"color:#C92C69;\">See the $name palette</a></p>";
}

$html = style_lore_email_html(
    'Your Style-LORE result',
    '<p style="margin:0 0 16px;">Here it is, so you have it whenever you need it:</p>' . $rows
        . '<p style="margin:0;">A free account keeps this across your devices and opens Community — '
        . 'photographs of real people built like you, which is the part that actually helps when you\'re standing in a shop.</p>',
    'Open Style-LORE',
    SITE_BASE_URL . '/',
    "You asked us to email this result, so we sent it once. We haven't created an account for you and you're not on a mailing list."
);

$textLines = "Here's your Style-LORE result:\n\n";
if ($kibbe !== '')  $textLines .= 'Your lines: ' . lead_pretty($kibbe) . "\n" . SITE_BASE_URL . '/types/' . rawurlencode($kibbe) . ".html\n\n";
if ($season !== '') $textLines .= 'Your colour season: ' . lead_pretty($season) . "\n" . SITE_BASE_URL . '/colors/' . rawurlencode($season) . ".html\n\n";
$textLines .= "A free account keeps this across your devices and opens Community.\n" . SITE_BASE_URL . "/\n\n"
            . "You asked us to email this result, so we sent it once. We haven't created an account for you and you're not on a mailing list.\n";

// A send failure must not read as a save failure. The address is already
// recorded, which is the part that matters for measuring the channel.
$sent = false;
try { $sent = send_app_html_email($email, 'Your Style-LORE result', $html, $textLines); }
catch (Throwable $e) { error_log('lead_capture send: ' . $e->getMessage()); }

json_response(['ok' => true, 'sent' => (bool)$sent]);
