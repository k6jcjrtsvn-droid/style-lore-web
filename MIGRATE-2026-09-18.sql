-- Free AI Stylist reads (one per account, ever). Run once in phpMyAdmin.
CREATE TABLE IF NOT EXISTS ai_reads (
  account_id CHAR(36) NOT NULL PRIMARY KEY,
  used INT NOT NULL DEFAULT 0,
  last_at BIGINT NOT NULL
);

-- Weekly email: opt-out flag and last-sent timestamp per account.
ALTER TABLE accounts
  ADD COLUMN IF NOT EXISTS digest_opt_out TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS digest_sent_at BIGINT DEFAULT NULL;
