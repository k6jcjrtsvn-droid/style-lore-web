-- Free AI Stylist reads (one per account, ever). Run once in phpMyAdmin.
CREATE TABLE IF NOT EXISTS ai_reads (
  account_id CHAR(36) NOT NULL PRIMARY KEY,
  used INT NOT NULL DEFAULT 0,
  last_at BIGINT NOT NULL
);
