-- Style-LORE — funnel visibility migration (2026-09-30).
-- Idempotent: safe to run more than once. Paste into phpMyAdmin's SQL tab
-- against the live `stylelore` database and run once.
--
-- Everything here is ALSO created lazily by the code on first use (see the
-- ensure_* helpers in includes/helpers.php, api/events.php and p.php), so
-- a deploy without this file still works. Running it just means the first
-- request after deploy pays nothing and the admin Funnel tab is complete
-- from the start.

-- Product events the app sends: signed-out quiz completions, share-button
-- taps, posts started from a result/Style-ME/outfit (api/events.php).
-- ip_hash is a SHA-1 of the address — the address itself is never stored.
CREATE TABLE IF NOT EXISTS events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  created_at BIGINT NOT NULL,
  event VARCHAR(40) NOT NULL,
  account_id CHAR(36) NULL,
  visitor_id VARCHAR(64) NULL,
  meta VARCHAR(500) NULL,
  ip_hash CHAR(40) NULL,
  INDEX idx_event_time (event, created_at),
  INDEX idx_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per render of a shared post page /p/<id> (p.php).
CREATE TABLE IF NOT EXISTS share_hits (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  post_id CHAR(36) NOT NULL,
  created_at BIGINT NOT NULL,
  ip_hash CHAR(40) NULL,
  referer VARCHAR(255) NULL,
  INDEX idx_post_time (post_id, created_at),
  INDEX idx_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- posts.source: which screen a post was started from
-- (result|styleme|outfit|composer|room|profile). NULL for every older post.
ALTER TABLE posts
  ADD COLUMN IF NOT EXISTS source VARCHAR(20) DEFAULT NULL;

-- accounts.last_seen_at: ms timestamp of the last authenticated request,
-- stamped at most once per 10 minutes per account (touch_last_seen()).
ALTER TABLE accounts
  ADD COLUMN IF NOT EXISTS last_seen_at BIGINT DEFAULT NULL;
