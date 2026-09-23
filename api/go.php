<?php
/**
 * /ios and /android — the links to hand out.
 *
 * Records the hit, then redirects to the store. The point is that Apple and
 * Google both report who JOINED a beta and neither reports who looked, so
 * without a hop of our own the top of the funnel is invisible: a link that
 * nobody clicks and a link that everybody clicks and abandons look
 * identical.
 *
 *   /ios              -> the TestFlight public link
 *   /android          -> the Play opt-in page
 *   /ios?s=instagram  -> same, tagged so posts can be compared
 *
 * A failure here must never cost a tester, so every step is wrapped: if the
 * database is down the redirect still happens.
 */
require_once __DIR__ . '/../includes/helpers.php';

const DESTINATIONS = [
    'ios'     => 'https://testflight.apple.com/join/CqYS67Md',
    'android' => 'https://play.google.com/apps/testing/com.stylelore.app',
];

$slug = strtolower(trim((string)($_GET['slug'] ?? '')));
if (!isset(DESTINATIONS[$slug])) {
    header('Location: https://style-lore.com/', true, 302);
    exit;
}

try {
    $pdo = db();
    ensure_funnel_schema($pdo);

    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    // Coarse platform read, so an Android phone tapping the iOS link shows up
    // as the mismatch it is rather than as a mystery non-converter.
    $platform = null;
    if (preg_match('/iPhone|iPad|iPod/i', $ua)) $platform = 'ios';
    elseif (preg_match('/Android/i', $ua)) $platform = 'android';
    elseif ($ua !== '') $platform = 'other';

    $pdo->prepare(
        'INSERT INTO link_clicks (slug, created_at, referrer, source, user_agent, platform, ip)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $slug,
        current_time_ms(),
        mb_substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 255) ?: null,
        mb_substr((string)($_GET['s'] ?? ''), 0, 64) ?: null,
        mb_substr($ua, 0, 255) ?: null,
        $platform,
        client_ip(),
    ]);
} catch (Throwable $e) {
    error_log('go.php: ' . $e->getMessage());
}

header('Location: ' . DESTINATIONS[$slug], true, 302);
exit;
