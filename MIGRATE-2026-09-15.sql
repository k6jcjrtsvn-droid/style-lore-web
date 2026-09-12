-- Idempotent migration for push-notification device tokens.
-- Paste into phpMyAdmin's SQL tab against the live `stylelore` database.
-- Just one new table, no ALTERs needed.

CREATE TABLE IF NOT EXISTS device_tokens (
  token VARCHAR(255) NOT NULL PRIMARY KEY,
  account_id CHAR(36) NOT NULL,
  platform VARCHAR(20) NOT NULL DEFAULT 'android',
  updated_at BIGINT NOT NULL,
  KEY idx_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
