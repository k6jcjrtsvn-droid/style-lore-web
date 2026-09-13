-- Style-LORE — security hardening migration (post-review).
-- Idempotent: safe to run more than once. Paste into phpMyAdmin's SQL tab
-- against the live `stylelore` database and run once.

-- comments: record WHO commented (verified account id) so comments are
-- ownership-checked like likes/follows/posts, and so account deletion can
-- purge them. Existing rows keep NULL (pre-hardening, name-only).
ALTER TABLE comments
  ADD COLUMN IF NOT EXISTS author_id CHAR(36) DEFAULT NULL,
  ADD INDEX IF NOT EXISTS idx_author (author_id);

-- rate_limits: small fixed-window counters used by includes/helpers.php's
-- rate_limit() for login / signup / password reset / beta signup.
CREATE TABLE IF NOT EXISTS rate_limits (
  rl_key VARCHAR(160) NOT NULL PRIMARY KEY,
  hits INT NOT NULL DEFAULT 0,
  window_start BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
