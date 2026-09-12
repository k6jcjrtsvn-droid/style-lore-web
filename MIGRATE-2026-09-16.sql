-- Adds the beta_testers table used by the new sign-up-form -> Play Store
-- closed-testing pipeline (beta.html / beta-admin.html / api/beta_signup.php
-- / api/admin_beta_testers.php).
--
-- Google doesn't offer an API to add someone to a Play Console closed-
-- testing tester list, so this can't be fully automatic end-to-end. The
-- flow this table supports:
--   1. beta.html collects an email (and optional name) and inserts a row
--      here with status 'pending'.
--   2. Kenneth opens beta-admin.html, copies the pending emails, and
--      pastes them into Play Console's tester email list by hand.
--   3. He then clicks "Mark copied as synced" (status -> 'synced').
--   4. He clicks "Send Play Store links" once Google actually shows them
--      as added -- this emails each 'synced' tester their real testing
--      link and marks them 'sent'.
--
-- Run this once against the live database (phpMyAdmin -> SQL tab, or the
-- mysql CLI) after this deploy goes out.
CREATE TABLE IF NOT EXISTS beta_testers (
  id CHAR(36) NOT NULL PRIMARY KEY,
  email VARCHAR(255) NOT NULL,
  name VARCHAR(60) NOT NULL DEFAULT '',
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  created_at BIGINT NOT NULL,
  synced_at BIGINT DEFAULT NULL,
  sent_at BIGINT DEFAULT NULL,
  UNIQUE KEY uniq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
