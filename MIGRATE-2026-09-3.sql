-- Style-LORE — migration for the Community/social features phase:
-- Direct Messaging, Notifications, Stories, and Groups/interest
-- communities. Idempotent like the earlier MIGRATE-2026-09*.sql files —
-- safe to run more than once. Paste into phpMyAdmin's SQL tab (Databases
-- > phpMyAdmin > your stylelore database > SQL) and run once.

SET FOREIGN_KEY_CHECKS = 0;

-- posts: tag a post as belonging to a Group (NULL = normal Community feed
-- post). Group posts are excluded from the main feed by default.
ALTER TABLE posts
  ADD COLUMN IF NOT EXISTS group_id CHAR(36) DEFAULT NULL,
  ADD INDEX IF NOT EXISTS idx_group (group_id);

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

CREATE TABLE IF NOT EXISTS interest_groups (
  id CHAR(36) NOT NULL PRIMARY KEY,
  name VARCHAR(60) NOT NULL,
  description VARCHAR(280) NOT NULL DEFAULT '',
  topic_kibbe VARCHAR(40) DEFAULT NULL,
  topic_style VARCHAR(40) DEFAULT NULL,
  creator_id CHAR(36) NOT NULL,
  creator_name VARCHAR(60) NOT NULL DEFAULT '',
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

SET FOREIGN_KEY_CHECKS = 1;
