<?php
/**
 * GET /api/capture_allowed.php?accountId=…&authToken=…
 *
 * Answers one question: may this account turn off the Android app's
 * screen-capture block?
 *
 * The Android app sets FLAG_SECURE in MainActivity, so Android refuses to
 * screenshot or screen-record it at all. That is deliberate — it is what
 * stops anyone capturing another member's Community photos — and it is
 * app-wide, because the flag is set in onCreate before anyone is signed in.
 *
 * This endpoint is how the app learns, once it *does* know who is signed in,
 * that this particular account is allowed to clear it. The decision lives on
 * the server (includes/capture_allowlist.php) rather than in the binary, so
 * it can be granted or revoked without shipping a new build.
 *
 * Authenticated: you can only ask about yourself, and only with a live
 * session token. The answer is a single boolean — it reveals nothing about
 * any other account, and a "false" is the same shape as a "true".
 */
require_once __DIR__ . '/../includes/helpers.php';
@include_once __DIR__ . '/../includes/capture_allowlist.php';
require_method('GET');

$accountId = (string)($_GET['accountId'] ?? '');
$token     = (string)($_GET['authToken'] ?? '');
if ($token === '') $token = bearer_token();

$pdo = db();
require_owner($pdo, $accountId, $token);

$allowed = false;
if (defined('CAPTURE_ALLOWED_EMAIL_HASHES') && CAPTURE_ALLOWED_EMAIL_HASHES) {
    $stmt = $pdo->prepare('SELECT email FROM accounts WHERE id = ?');
    $stmt->execute([$accountId]);
    $email = (string)($stmt->fetchColumn() ?: '');
    if ($email !== '') {
        $hash = hash('sha256', strtolower(trim($email)));
        foreach (CAPTURE_ALLOWED_EMAIL_HASHES as $known) {
            // hash_equals so the comparison doesn't leak timing on a short list.
            if (hash_equals(strtolower(trim((string)$known)), $hash)) { $allowed = true; break; }
        }
    }
}

json_response(['allowed' => $allowed]);
