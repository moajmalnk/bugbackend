-- =============================================================================
-- BugRicer Migration 129 — magic_links.user_id stores the user UUID
-- =============================================================================
-- users.id is VARCHAR(36) but magic_links.user_id was INT, so the UUID was
-- truncated to its leading digits and magic link sign-in failed with
-- "This account is no longer active". Collation matches users.id so the
-- verify lookup never hits "Illegal mix of collations".
-- Rows with truncated ids map to no user and are removed (15-minute tokens).
-- Safe to re-run.
-- =============================================================================

ALTER TABLE `magic_links`
  MODIFY `user_id` VARCHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL;

DELETE ml FROM `magic_links` ml
  LEFT JOIN `users` u ON u.id = ml.user_id
  WHERE u.id IS NULL;
