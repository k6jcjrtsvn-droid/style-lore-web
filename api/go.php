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
 *   /android          -> the public Play listing
 *   /ios?s=instagram  -> same, tagged so posts can be compared
 *
 * A failure here must never cost a tester, so every step is wrapped: if the
 * database is down the redirect still happens.
 */
require_once __DIR__ . '/../includes/helpers.php';

const DESTINATIONS = [
    'ios'     => 'https://testflight.apple.com/join/CqYS67Md',
    'android' => 'https://play.google.com/store/apps/details?id=com.stylelore.app',
];

/**
 * Crawlers and link-preview fetchers, matched by explicit token.
 *
 * An explicit list rather than a loose /bot/ pattern, on purpose: real Android
 * phones carry "CUBOT" in their user-agent, and a substring match would
 * quietly drop those people from the funnel -- the exact failure this filter
 * exists to prevent, pointed the other way.
 *
 * Preview fetchers (WhatsApp, Slack, Discord, Pinterest) are in the list too.
 * They still get their redirect and the link still previews; they are simply
 * not people and must not be counted as clicks.
 *
 * Added 19 Nov 2026. Search Console reported "Page with redirect" for /ios and
 * /android, which was the first hard evidence Googlebot was crawling them --
 * and every crawl wrote a row into link_clicks, inflating the top of the
 * funnel with traffic that was never a person. robots.txt was deliberately NOT
 * used for this: Disallow would also stop the preview fetchers, and a shared
 * /ios or /android link would lose its preview card.
 */
const BOT_TOKENS = [
    // search engines
    'googlebot', 'bingbot', 'slurp', 'duckduckbot', 'baiduspider', 'yandexbot',
    'sogou', 'exabot', 'applebot', 'petalbot', 'qwantify', 'seznambot',
    'mediapartners-google', 'adsbot-google', 'feedfetcher-google',
    // link previews and social
    'facebookexternalhit', 'facebot', 'twitterbot', 'linkedinbot', 'pinterest',
    'redditbot', 'slackbot', 'slack-imgproxy', 'telegrambot', 'whatsapp',
    'discordbot', 'embedly', 'quora link preview', 'skypeuripreview',
    'vkshare', 'outbrain', 'nuzzel', 'bitlybot', 'w3c_validator',
    // AI and archive
    'gptbot', 'claudebot', 'anthropic-ai', 'ccbot', 'amazonbot',
    'perplexitybot', 'ia_archiver', 'archive.org_bot', 'bytespider',
    // SEO and monitoring
    'ahrefsbot', 'semrushbot', 'mj12bot', 'dotbot', 'rogerbot', 'blexbot',
    'screaming frog', 'lighthouse', 'pagespeed', 'gtmetrix', 'pingdom',
    'uptimerobot', 'statuscake', 'site24x7',
    // headless and scripted clients
    'headlesschrome', 'phantomjs', 'puppeteer', 'playwright', 'selenium',
    'python-requests', 'python-urllib', 'scrapy', 'curl/', 'wget',
    'go-http-client', 'libwww-perl', 'apache-httpclient', 'axios/', 'node-fetch',
];

/**
 * An empty user-agent counts as a crawler: every real browser sends one, and a
 * request without one is a script. If that is ever wrong it costs one row in a
 * statistics table and never a redirect -- the redirect happens either way.
 */
function is_crawler(string $ua): bool {
    if ($ua === '') return true;
    $ua = strtolower($ua);
    foreach (BOT_TOKENS as $token) {
        if (strpos($ua, $token) !== false) return true;
    }
    return false;
}

$slug = strtolower(trim((string)($_GET['slug'] ?? '')));
if (!isset(DESTINATIONS[$slug])) {
    header('Location: https://style-lore.com/', true, 302);
    exit;
}

$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

// Crawlers get the redirect and nothing else. Recording them would make a link
// nobody tapped look exactly like a link everybody tapped, which is the one
// question this table exists to answer.
if (!is_crawler($ua)) {
    try {
        $pdo = db();
        ensure_funnel_schema($pdo);

        // Coarse platform read, so an Android phone tapping the iOS link shows
        // up as the mismatch it is rather than as a mystery non-converter.
        $platform = null;
        if (preg_match('/iPhone|iPad|iPod/i', $ua)) $platform = 'ios';
        elseif (preg_match('/Android/i', $ua)) $platform = 'android';
        else $platform = 'other';

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
}

header('Location: ' . DESTINATIONS[$slug], true, 302);
exit;
