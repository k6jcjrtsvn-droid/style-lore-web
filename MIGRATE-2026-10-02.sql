-- Style-LORE — 2 Oct 2026
--
-- One row per Anthropic API call, with the token counts that call reported
-- and what those tokens cost at published rates.
--
-- WHY A TABLE AND NOT A COUNTER. Anthropic bills prepaid credits and exposes
-- no endpoint that reports the remaining balance — only spend after the
-- fact. So the only way to know how long the credit lasts is to meter every
-- call as it happens and project forward. Rows rather than a running total
-- because the useful questions are per-feature and per-day ("what is the
-- Stylist costing per read", "did yesterday spike"), and a counter answers
-- none of them.
--
-- SAFE TO SKIP. includes/helpers.php creates this table on first use
-- (ensure_ai_spend_table), exactly like the other ensure_* helpers, so a
-- deploy without running this file still works. Running it just means the
-- first AI call after deploy pays no DDL cost.
--
-- DDL forces an implicit COMMIT in MySQL, so these statements must stay
-- OUTSIDE any transaction.

CREATE TABLE IF NOT EXISTS ai_spend (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  created_at BIGINT NOT NULL,
  feature VARCHAR(40) NOT NULL,
  model VARCHAR(60) NOT NULL,
  account_id CHAR(36) NULL,
  input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
  output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
  cache_write_tokens INT UNSIGNED NOT NULL DEFAULT 0,
  cache_read_tokens INT UNSIGNED NOT NULL DEFAULT 0,
  cost_usd DECIMAL(12,8) NOT NULL DEFAULT 0,
  INDEX idx_time (created_at),
  INDEX idx_feature_time (feature, created_at),
  INDEX idx_account_time (account_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
