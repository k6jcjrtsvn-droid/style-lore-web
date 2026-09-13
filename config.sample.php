<?php
/**
 * Style-LORE — database + app configuration.
 *
 * COPY this file to "config.php" (same folder) and fill in the values
 * below. Do NOT edit config.sample.php itself — config.php is the file the
 * app actually reads, and it's excluded from direct web access by
 * .htaccess.
 *
 * See DEPLOY-GODADDY.md for exactly where the DB_* values come from.
 */

// GoDaddy cPanel databases are almost always named and accessed with a
// prefix like "yourcpaneluser_dbname" and "yourcpaneluser_dbuser" — copy
// these exactly as shown on the cPanel "MySQL Databases" page.
define('DB_HOST', 'localhost');
define('DB_NAME', 'yourcpaneluser_stylelore');
define('DB_USER', 'yourcpaneluser_stylelore');
define('DB_PASS', 'REPLACE-WITH-THE-PASSWORD-YOU-SET-IN-CPANEL');

// The site's own public URL, no trailing slash — used to build the links
// sent in password-reset and email-verification emails
// (e.g. SITE_BASE_URL . "/?reset=<token>").
define('SITE_BASE_URL', 'https://style-lore.com');

// The "From" address on password-reset and email-verification emails.
// GoDaddy shared hosting's mail() works best (fewer spam-folder landings)
// when this address's domain matches the site's own domain.
define('MAIL_FROM', 'no-reply@style-lore.com');

// Resend (https://resend.com) API key for outbound email. When this is set,
// every email the app sends goes through Resend's HTTP API instead of PHP's
// mail(), which on this shared host has been observed to block for tens of
// seconds on handoff. Create a sending-only key in the Resend dashboard and
// paste it here. Leave empty to fall back to mail(). The sending domain
// (style-lore.com) must be verified in Resend first -- see
// claude/smtp-migration-plan.md in the project.
define('RESEND_API_KEY', '');

// A private key only you know, used to access the moderation endpoint
// (api/admin_moderation.php) — list and unhide reported posts by visiting
// e.g. https://style-lore.com/api/admin/moderation?key=<this value>.
// Leave blank to keep moderation completely locked (the endpoint refuses
// every request with a blank key). Make this a long random string, not a
// word — for example, generate one with:
//   php -r "echo bin2hex(random_bytes(24));"
define('ADMIN_KEY', '');

// Powers the "AI Stylist" (api/checker_photo.php) — Style-LORE Premium's
// flagship paid feature: a real server-side call to Anthropic's Claude API
// that actually looks at an uploaded photo (a person, an outfit, or both)
// and gives specific, plain-language styling feedback, as opposed to the
// free on-device checker (always available, everyone gets it, no key
// needed) which only estimates coarse structure/color/scale axes from
// pixel statistics. This is now gated behind has_premium() — only
// Style-LORE Premium subscribers can call it — so real per-use API cost
// is offset by subscription revenue (see RevenueCat settings below).
//
// Get a key at https://console.anthropic.com — Settings > API Keys >
// Create Key. Copy the value shown at that moment (starts with
// "sk-ant-api03-"); it is shown only once and can't be retrieved again
// later, only replaced with a new one. The Console's key LIST view shows
// a similar-looking but non-secret "Key ID" (starts with "apikey_") for
// reference only — that is not usable here.
//
// Leave ANTHROPIC_API_KEY blank to keep the AI Stylist gracefully disabled
// (it shows a clean "not set up yet" message instead of an error) —
// everything else in the app, including the free on-device checker, works
// fine without it. ANTHROPIC_MODEL defaults to claude-sonnet-5 — a
// meaningfully stronger vision model than the haiku tier used elsewhere in
// this app, worth the extra per-call cost now that quality is what people
// are paying for.
define('ANTHROPIC_API_KEY', '');
define('ANTHROPIC_MODEL', 'claude-sonnet-5');

// Powers Style-LORE Premium subscriptions (the AI Stylist above, unlimited
// Closet items instead of the free 20-item cap) via RevenueCat, which
// handles the actual Google Play Billing purchase flow from the mobile
// app and tells this server about subscription changes through a webhook.
//
// REVENUECAT_WEBHOOK_SECRET — a long random string YOU choose (e.g.
// `php -r "echo bin2hex(random_bytes(24));"`), entered in BOTH places:
// here, and as the "Authorization header value" when you set up the
// webhook in the RevenueCat dashboard (Project settings > Integrations >
// Webhooks > Webhook URL: https://style-lore.com/api/revenuecat/webhook).
// RevenueCat resends this exact value on every webhook call so
// api/revenuecat_webhook.php can reject anything else. Leave blank to
// keep the webhook endpoint refusing all requests (subscriptions just
// won't sync — nothing else breaks).
define('REVENUECAT_WEBHOOK_SECRET', '');

// Powers real phone push notifications (likes, comments, follows,
// messages, group-joins) via Firebase Cloud Messaging. Both values come
// from a Firebase project (free) at https://console.firebase.google.com:
//
// 1. Create a project (or reuse one), then add an Android app to it with
//    package name "com.stylelore.app" (must match exactly). Download the
//    google-services.json it gives you and save it at
//    mobile/android/app/google-services.json in this repo (NOT under
//    web/ — it's a mobile build input, never served by the website).
//
// 2. FIREBASE_PROJECT_ID — the project's ID, shown on the Firebase
//    console's Project Settings page (General tab), e.g. "style-lore-app".
//
// 3. FIREBASE_SERVICE_ACCOUNT_PATH — an absolute server-side file path to
//    a service-account JSON key: Firebase console > Project Settings >
//    Service Accounts tab > "Generate new private key". This file is a
//    real secret (it can send push to every device) — upload it
//    somewhere OUTSIDE public_html (e.g. one level above it, alongside
//    config.php) so it's never web-reachable, then point this constant at
//    its absolute path on the server, e.g.
//    '/home/yourcpaneluser/firebase-service-account.json'.
//
// Leave either value blank to keep push notifications gracefully
// disabled — the in-app notification bell/inbox still work exactly as
// before, this only skips the "also buzz their phone" step.
define('FIREBASE_PROJECT_ID', '');
define('FIREBASE_SERVICE_ACCOUNT_PATH', '');
