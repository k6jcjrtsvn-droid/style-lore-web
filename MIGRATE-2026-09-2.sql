-- Style-LORE — live-database migration #2: full quiz-result storage for
-- cross-device reuse + the new "Compare styles" screen.
--
-- Background: profiles.kibbe_type_name / profiles.style_words (added by
-- MIGRATE-2026-09.sql) only ever stored a short display summary (the
-- Kibbe type's name, and up to 8 style-word ids) — enough for the little
-- chips shown on a profile, but not enough to reconstruct the actual quiz
-- results. This migration adds two columns that store the full underlying
-- data, JSON-encoded, so:
--   (a) a person can open the app on a new device/browser and see their
--       own quiz results without retaking either quiz, and
--   (b) two accounts' full results can be compared axis-by-axis on the new
--       Compare screen, not just by their top 5 words in common.
--
-- Safe to run more than once (IF NOT EXISTS on both columns).
--
-- How to run it: cPanel → Databases → phpMyAdmin → select the Style-LORE
-- database → SQL tab → paste this whole file → Go.

ALTER TABLE profiles
  -- { kibbeTypeId, kibbeBlend } from the app's Kibbe Quiz — kibbeBlend is a
  -- weight-per-Kibbe-type map, not just the single winning type.
  ADD COLUMN IF NOT EXISTS kibbe_result_json TEXT DEFAULT NULL,
  -- The Style Quiz's raw ~40-word weight dictionary (styleBlend in the
  -- app), not just the derived top-5 style_words summary.
  ADD COLUMN IF NOT EXISTS style_blend_json TEXT DEFAULT NULL;
