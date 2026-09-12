-- Style-LORE — live-database migration for the auth-token, moderation,
-- email-verification, and profile-style-summary work described in
-- deployment-status.md.
--
-- Why this file exists: schema.sql uses "CREATE TABLE IF NOT EXISTS",
-- which only affects tables that don't exist yet — it will NOT add new
-- columns to accounts/profiles or the new `reports` table to a database
-- that was already created from an earlier schema.sql. Run this once
-- against your live database to bring it up to date.
--
-- How to run it: log into phpMyAdmin on GoDaddy/cPanel, select your
-- Style-LORE database, open the "SQL" tab, paste this whole file in, and
-- run it. It's safe to run more than once — every statement either
-- checks for the column/table first or uses IF NOT EXISTS.
--
-- After running this, also update config.php (not config.sample.php) on
-- the live site with real values for SITE_BASE_URL, MAIL_FROM, and
-- ADMIN_KEY — see the comments in config.sample.php for what each does
-- and how to generate ADMIN_KEY.

-- --- accounts: auth tokens + email verification + password reset -------

ALTER TABLE accounts
  ADD COLUMN IF NOT EXISTS auth_token_hash CHAR(64) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS email_verified TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS verify_token_hash CHAR(64) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS verify_token_expires BIGINT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS reset_token_hash CHAR(64) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS reset_token_expires BIGINT DEFAULT NULL;

-- --- profiles: auto-populated style summary -----------------------------

ALTER TABLE profiles
  ADD COLUMN IF NOT EXISTS kibbe_type_name VARCHAR(60) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS style_words TEXT DEFAULT NULL;

-- --- reports: new table backing the report/hide-threshold + moderation -

CREATE TABLE IF NOT EXISTS reports (
  post_id CHAR(36) NOT NULL,
  visitor_id VARCHAR(100) NOT NULL,
  created_at BIGINT NOT NULL,
  PRIMARY KEY (post_id, visitor_id),
  KEY idx_post (post_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --- one-time cleanup ----------------------------------------------------

-- Every account that existed before this migration has no auth_token_hash
-- yet, so every currently-logged-in session (including yours, if you've
-- been testing the live site) will get a 401 on its next write attempt
-- (posting, liking, following, editing a profile) until that person logs
-- in again, which issues them a fresh token. There's no way around this —
-- there was never a token to retroactively assign. Reading the feed and
-- viewing profiles keeps working the whole time; only writes are affected.
-- Everyone can fix it themselves by logging out and back in (or just
-- logging in again if the app doesn't prompt automatically).

-- Everyone who existed before this migration also has email_verified = 0
-- by default, so nobody will be able to post in Community until they
-- verify. If you'd rather grandfather in every existing account instead of
-- making them all verify retroactively, uncomment and run this once:
-- UPDATE accounts SET email_verified = 1 WHERE created_at < 1757000000000;
