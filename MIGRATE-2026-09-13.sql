-- Style-LORE — migration for the Closet public/hidden visibility feature.
-- Run this against the LIVE database (phpMyAdmin > SQL tab) the same way
-- MIGRATE-2026-09-3.sql was run for the Community/social features phase.
--
-- NOTE (learned the hard way on this exact server, 2026-09-12): this
-- MariaDB instance rejects combining more than one IF NOT EXISTS-qualified
-- clause in a single ALTER TABLE statement (#1064 syntax error). Every
-- ALTER TABLE below is its own separate statement for that reason — do not
-- merge them.

ALTER TABLE profiles ADD COLUMN IF NOT EXISTS closet_visibility VARCHAR(10) NOT NULL DEFAULT 'hidden';

CREATE TABLE IF NOT EXISTS closet_items (
  id CHAR(36) NOT NULL PRIMARY KEY,
  account_id CHAR(36) NOT NULL,
  description VARCHAR(200) NOT NULL DEFAULT '',
  photo_data MEDIUMTEXT DEFAULT NULL,
  created_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE closet_items ADD INDEX IF NOT EXISTS idx_account_created (account_id, created_at);
