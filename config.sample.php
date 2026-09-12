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

// A private key only you know, used to access the moderation endpoint
// (api/admin_moderation.php) — list and unhide reported posts by visiting
// e.g. https://style-lore.com/api/admin/moderation?key=<this value>.
// Leave blank to keep moderation completely locked (the endpoint refuses
// every request with a blank key). Make this a long random string, not a
// word — for example, generate one with:
//   php -r "echo bin2hex(random_bytes(24));"
define('ADMIN_KEY', '');

// Powers the real photo-based AI Checker (api/checker_photo.php) — a
// server-side call to Anthropic's Claude API that looks at an uploaded
// photo (a person, an outfit, or both) and gives a fit verdict, as
// opposed to the free on-device text checker that's always available.
//
// Get a key at https://console.anthropic.com — Settings > API Keys >
// Create Key. Copy the value shown at that moment (starts with
// "sk-ant-api03-"); it is shown only once and can't be retrieved again
// later, only replaced with a new one. The Console's key LIST view shows
// a similar-looking but non-secret "Key ID" (starts with "apikey_") for
// reference only — that is not usable here.
//
// Leave ANTHROPIC_API_KEY blank to keep the photo checker gracefully
// disabled (it shows a clean "not set up yet" message instead of an
// error) — everything else in the app works fine without it.
define('ANTHROPIC_API_KEY', '');
define('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001');
