-- Style-LORE — MySQL schema for GoDaddy cPanel hosting
--
-- Import this file via phpMyAdmin (cPanel > Databases > phpMyAdmin) into the
-- MySQL database you create for this app. See DEPLOY-GODADDY.md for the
-- full click-by-click steps.
--
-- Mirrors the JSON-file "Collection" store the local prototype used
-- (server/store.js + server/index.js), moved to real tables so the app is
-- safe under real concurrent web traffic (a shared JSON file is not safe
-- once multiple PHP processes can write to it at once).

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- accounts — real login credentials, kept separate from `profiles` (public
-- display info) on purpose: this table is never read by any endpoint that
-- returns data to the browser. password_hash uses PHP's password_hash()
-- (bcrypt), the same algorithm family bcryptjs used locally.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS accounts (
  id CHAR(36) NOT NULL PRIMARY KEY,
  email VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at BIGINT NOT NULL,
  -- Lightweight auth: a random per-account token, hashed with SHA-256
  -- before storage (never store the raw token). Issued fresh at signup,
  -- login, and password reset; sent by the client as "authToken" on every
  -- write that claims an identity (profile edits, posts, follows, likes),
  -- and checked server-side before that write is allowed. This is what
  -- closes the "any client can claim any id" gap noted in
  -- DEPLOY-GODADDY.md's known-limitations section — still not a full
  -- session system (no expiry, one token per account), but it means a
  -- write now has to prove it knows a secret only the real account holder
  -- has, instead of just asserting an id.
  auth_token_hash CHAR(64) DEFAULT NULL,
  -- Email verification: unverified by default; verify_token_hash/expires
  -- back a one-time link sent to their email at signup (see auth_verify.php
  -- and auth_resend_verification.php).
  email_verified TINYINT(1) NOT NULL DEFAULT 0,
  verify_token_hash CHAR(64) DEFAULT NULL,
  verify_token_expires BIGINT DEFAULT NULL,
  -- Password reset: same one-time-link pattern as email verification (see
  -- auth_forgot.php / auth_reset.php).
  reset_token_hash CHAR(64) DEFAULT NULL,
  reset_token_expires BIGINT DEFAULT NULL,
  UNIQUE KEY uniq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- profiles — one editable row per person (name, bio, avatar), keyed by the
-- same id returned at signup/login and used everywhere else as visitorId.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS profiles (
  id CHAR(36) NOT NULL PRIMARY KEY,
  name VARCHAR(60) NOT NULL DEFAULT '',
  bio VARCHAR(160) NOT NULL DEFAULT '',
  avatar_url VARCHAR(255) DEFAULT NULL,
  -- Auto-populated style summary, synced from this account's own Kibbe
  -- Quiz and Style Quiz results (see api/profile_style.php) rather than
  -- typed in by hand — shown on the profile so anyone viewing it sees the
  -- person's style at a glance. Both columns are nullable: not everyone
  -- has taken either quiz yet.
  kibbe_type_name VARCHAR(60) DEFAULT NULL,
  style_words TEXT DEFAULT NULL,
  -- Full underlying quiz results (JSON-encoded), synced alongside the
  -- summary columns above — lets this account's results be reloaded on a
  -- new device without retaking either quiz, and lets two accounts be
  -- compared axis-by-axis on the Compare screen (see api/profile_style.php
  -- and api/profile.php). Both nullable: not everyone has taken either
  -- quiz yet.
  kibbe_result_json TEXT DEFAULT NULL,
  style_blend_json TEXT DEFAULT NULL,
  updated_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- posts — Community feed posts. style_tags is a JSON-encoded array of up
-- to 3 style-word ids (see the app's STYLE_WORDS list). hidden/report_count
-- are moderation-only fields never sent to the browser (see publicPost()
-- in includes/helpers.php).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS posts (
  id CHAR(36) NOT NULL PRIMARY KEY,
  author_id CHAR(36) NOT NULL,
  author_name VARCHAR(60) NOT NULL,
  author_avatar_url VARCHAR(255) DEFAULT NULL,
  kibbe_tag VARCHAR(40) NOT NULL,
  style_tags TEXT NOT NULL,
  caption VARCHAR(280) NOT NULL DEFAULT '',
  photo_url VARCHAR(255) DEFAULT NULL,
  video_file_url VARCHAR(255) DEFAULT NULL,
  video_url VARCHAR(500) DEFAULT NULL,
  created_at BIGINT NOT NULL,
  hidden TINYINT(1) NOT NULL DEFAULT 0,
  report_count INT NOT NULL DEFAULT 0,
  KEY idx_author (author_id),
  KEY idx_created (created_at),
  KEY idx_hidden (hidden)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- likes — one row per (post, visitor). `liked` can be 0 to record an
-- explicit unlike, matching the old JSON store's {visitorId: true|false}
-- map shape once reassembled by the API.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS likes (
  post_id CHAR(36) NOT NULL,
  visitor_id VARCHAR(100) NOT NULL,
  liked TINYINT(1) NOT NULL DEFAULT 1,
  updated_at BIGINT NOT NULL,
  PRIMARY KEY (post_id, visitor_id),
  KEY idx_post (post_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- comments — append-only per post.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS comments (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  post_id CHAR(36) NOT NULL,
  author_name VARCHAR(60) NOT NULL,
  text VARCHAR(180) NOT NULL,
  created_at BIGINT NOT NULL,
  KEY idx_post (post_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- follows — one-way follow edges. follower_id is now checked against an
-- auth token server-side (see follows.php) before a new edge is written.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS follows (
  id VARCHAR(80) NOT NULL PRIMARY KEY,
  follower_id VARCHAR(100) NOT NULL,
  follower_name VARCHAR(60) NOT NULL DEFAULT '',
  following_id VARCHAR(100) NOT NULL,
  following_name VARCHAR(60) NOT NULL DEFAULT '',
  created_at BIGINT NOT NULL,
  KEY idx_follower (follower_id),
  KEY idx_following (following_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- reports — one row per (post, reporter), so the same person can't inflate
-- a post's report count by reporting it repeatedly. A post is only hidden
-- once it has REPORT_HIDE_THRESHOLD distinct reporters (see
-- post_report.php) instead of on the very first report.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS reports (
  post_id CHAR(36) NOT NULL,
  visitor_id VARCHAR(100) NOT NULL,
  created_at BIGINT NOT NULL,
  PRIMARY KEY (post_id, visitor_id),
  KEY idx_post (post_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
