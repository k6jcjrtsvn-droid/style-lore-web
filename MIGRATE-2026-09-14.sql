-- Style-LORE — migration for PER-ITEM closet visibility + the community
-- Closets feed. Supersedes the account-wide toggle added in
-- MIGRATE-2026-09-13.sql (profiles.closet_visibility is left in place,
-- unused, rather than migrated away — avoids extra migration risk).
--
-- Kenneth's feedback that prompted this: "There is now a hidden and public
-- option in the closet. But there's no way for me to assign whatever I add
-- to my closet to hidden or public. And if it's assigned to public, then
-- everybody in the community needs to see that according to what it's
-- labeled as." So visibility moves from the whole closet down to each
-- individual item, and a new author_name column lets the community-wide
-- feed (api/closet_feed.php) show whose item it is without an extra join.
--
-- Run this against the LIVE database (phpMyAdmin > SQL tab) the same way
-- MIGRATE-2026-09-13.sql was run.
--
-- NOTE (learned the hard way on this exact server): this MariaDB instance
-- rejects combining more than one IF NOT EXISTS-qualified clause in a
-- single ALTER TABLE statement (#1064 syntax error). Every ALTER TABLE
-- below is its own separate statement for that reason — do not merge them.

ALTER TABLE closet_items ADD COLUMN IF NOT EXISTS visibility VARCHAR(10) NOT NULL DEFAULT 'hidden';

ALTER TABLE closet_items ADD COLUMN IF NOT EXISTS author_name VARCHAR(60) NOT NULL DEFAULT '';

ALTER TABLE closet_items ADD INDEX IF NOT EXISTS idx_visibility_created (visibility, created_at);
