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
  -- Whether this account's Closet (see closet_items below) is visible on
  -- their public profile to other people. Hidden by default — closet items
  -- live only on this account's own device until they opt in.
  closet_visibility VARCHAR(10) NOT NULL DEFAULT 'hidden', -- UNUSED: superseded by per-item closet_items.visibility; api/closet_visibility.php (which wrote this) was removed 2026-09-12. Left in place rather than dropped live; safe to drop in a future migration once confirmed nothing reads it.
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
  -- NULL for the main Community feed; set when this post was made inside
  -- a Group (see interest_groups below) — group posts are deliberately
  -- excluded from the main feed/profile listings and only ever returned
  -- when a caller explicitly asks for that group's posts (api/posts.php).
  group_id CHAR(36) DEFAULT NULL,
  KEY idx_author (author_id),
  KEY idx_created (created_at),
  KEY idx_hidden (hidden),
  KEY idx_group (group_id)
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

-- ---------------------------------------------------------------------
-- notifications — one row per event a person should be told about
-- (someone followed them, liked or commented on their post, sent them a
-- message, or joined a group they created). `actor_id` is nullable
-- because comments don't carry a verified account id yet (see
-- api/post_comments.php) — the notification still shows `actor_name`,
-- it just can't be tapped through to that person's profile. `data` is a
-- small JSON blob (e.g. {"conversationId":"...","otherId":"..."}) that
-- tells the frontend where a tap on this notification should go, without
-- needing a different column per notification type.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
  id CHAR(36) NOT NULL PRIMARY KEY,
  recipient_id CHAR(36) NOT NULL,
  actor_id CHAR(36) DEFAULT NULL,
  actor_name VARCHAR(60) NOT NULL DEFAULT '',
  actor_avatar_url VARCHAR(255) DEFAULT NULL,
  type VARCHAR(20) NOT NULL,
  message VARCHAR(200) NOT NULL,
  data TEXT DEFAULT NULL,
  created_at BIGINT NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  KEY idx_recipient_created (recipient_id, created_at),
  KEY idx_recipient_unread (recipient_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Direct messages — 1:1 only for now (not group DMs, despite
-- conversation_members allowing more than 2 rows per conversation — the
-- API only ever creates/looks up 2-member conversations today). A
-- conversation's own row just tracks the last message for the inbox list;
-- the actual text lives in `messages`.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS conversations (
  id CHAR(36) NOT NULL PRIMARY KEY,
  created_at BIGINT NOT NULL,
  last_message_at BIGINT NOT NULL,
  last_message_preview VARCHAR(180) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS conversation_members (
  conversation_id CHAR(36) NOT NULL,
  account_id CHAR(36) NOT NULL,
  account_name VARCHAR(60) NOT NULL DEFAULT '',
  -- Used purely to compute "unread" in the inbox list (last_message_at >
  -- last_read_at for this member) — not a message-level read receipt.
  last_read_at BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (conversation_id, account_id),
  KEY idx_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS messages (
  id CHAR(36) NOT NULL PRIMARY KEY,
  conversation_id CHAR(36) NOT NULL,
  sender_id CHAR(36) NOT NULL,
  sender_name VARCHAR(60) NOT NULL DEFAULT '',
  text VARCHAR(2000) NOT NULL,
  created_at BIGINT NOT NULL,
  KEY idx_conversation_created (conversation_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Stories — 24-hour ephemeral photo/video posts. `expires_at` is computed
-- once at creation (created_at + 24h in the same millisecond epoch every
-- other timestamp in this app uses) rather than derived on every read, so
-- a listing query is a plain index range scan.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS stories (
  id CHAR(36) NOT NULL PRIMARY KEY,
  author_id CHAR(36) NOT NULL,
  author_name VARCHAR(60) NOT NULL DEFAULT '',
  author_avatar_url VARCHAR(255) DEFAULT NULL,
  media_url VARCHAR(255) NOT NULL,
  media_type VARCHAR(10) NOT NULL DEFAULT 'image',
  caption VARCHAR(200) NOT NULL DEFAULT '',
  created_at BIGINT NOT NULL,
  expires_at BIGINT NOT NULL,
  KEY idx_author (author_id),
  KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS story_views (
  story_id CHAR(36) NOT NULL,
  visitor_id VARCHAR(100) NOT NULL,
  viewed_at BIGINT NOT NULL,
  PRIMARY KEY (story_id, visitor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Groups / interest communities. Named "interest_groups" rather than
-- "groups" — GROUPS is a reserved word in MySQL 8 (window-frame syntax),
-- so a table literally named `groups` needs backtick-escaping everywhere
-- it's used; simplest to just avoid the collision.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS interest_groups (
  id CHAR(36) NOT NULL PRIMARY KEY,
  name VARCHAR(60) NOT NULL,
  description VARCHAR(280) NOT NULL DEFAULT '',
  -- Optional tie-in to an existing Kibbe type or style word, so a group
  -- can show the same color swatch/chip the rest of the app already uses
  -- for that type/word — purely decorative, never required.
  topic_kibbe VARCHAR(40) DEFAULT NULL,
  topic_style VARCHAR(40) DEFAULT NULL,
  creator_id CHAR(36) NOT NULL,
  creator_name VARCHAR(60) NOT NULL DEFAULT '',
  -- Denormalized count, kept in sync by group_join.php/group_leave.php —
  -- avoids a COUNT(*) subquery on every group listed in the directory.
  member_count INT NOT NULL DEFAULT 1,
  created_at BIGINT NOT NULL,
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS interest_group_members (
  group_id CHAR(36) NOT NULL,
  account_id CHAR(36) NOT NULL,
  account_name VARCHAR(60) NOT NULL DEFAULT '',
  joined_at BIGINT NOT NULL,
  PRIMARY KEY (group_id, account_id),
  KEY idx_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Closet — a wholesale mirror of each account's local closet array (see
-- api/closet.php), synced up so each item can be shown according to its
-- OWN visibility flag: Public items appear on the owner's profile and in
-- Community's Closets feed (api/closet_feed.php); Hidden items never leave
-- the owner's device via this mirror. (profiles.closet_visibility above is
-- an earlier, account-wide toggle superseded by this per-item column —
-- left in place unused rather than migrated away, to avoid extra risk.)
-- The client is still the source of truth for editing (each closet item's
-- own device adds/removes/re-labels it locally); the server side is a read
-- replica for other people's viewing, replaced in full on every sync
-- rather than individually added-to/removed-from. author_name is
-- denormalized from the account's display name at sync time so the
-- community-wide feed can show attribution without an extra join.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS closet_items (
  id CHAR(36) NOT NULL PRIMARY KEY,
  account_id CHAR(36) NOT NULL,
  description VARCHAR(200) NOT NULL DEFAULT '',
  photo_data MEDIUMTEXT DEFAULT NULL,
  created_at BIGINT NOT NULL,
  visibility VARCHAR(10) NOT NULL DEFAULT 'hidden',
  author_name VARCHAR(60) NOT NULL DEFAULT '',
  KEY idx_account_created (account_id, created_at),
  KEY idx_visibility_created (visibility, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Subscription entitlement (Style-LORE Premium), kept in sync by
-- RevenueCat's webhook (see api/revenuecat_webhook.php). One row per
-- account; account_id doubles as the RevenueCat "app user id" -- the
-- mobile app configures the RevenueCat SDK with this account's own id as
-- appUserID, so a webhook event's app_user_id always maps 1:1 back to
-- accounts.id with no separate mapping table needed.
CREATE TABLE IF NOT EXISTS subscriptions (
  account_id CHAR(36) NOT NULL PRIMARY KEY,
  is_premium TINYINT(1) NOT NULL DEFAULT 0,
  product_id VARCHAR(120) DEFAULT NULL,
  expires_at BIGINT DEFAULT NULL,
  updated_at BIGINT NOT NULL,
  KEY idx_is_premium (is_premium)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Push notification device tokens (Firebase Cloud Messaging). One row per
-- installed app instance; upserted on every register call (a token can
-- rotate at any time per FCM's own docs), removed on sign-out or once FCM
-- reports it as no longer valid. Used by send_push_notification() in
-- helpers.php, called from create_notification() whenever an in-app
-- notification is created, so a like/comment/follow/message/group-join
-- also raises a real phone notification.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS device_tokens (
  token VARCHAR(255) NOT NULL PRIMARY KEY,
  account_id CHAR(36) NOT NULL,
  platform VARCHAR(20) NOT NULL DEFAULT 'android',
  updated_at BIGINT NOT NULL,
  KEY idx_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
