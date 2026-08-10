-- =============================================================================
-- BugRicer Migration 063 — must_set_password for new hires
-- =============================================================================
-- Admins no longer set passwords on Add User. New accounts get a temporary
-- password for first login; employees choose their own during onboarding.
-- Existing users keep must_set_password = 0 and skip the password UI.
-- Safe to re-run.
-- =============================================================================

SET @db := DATABASE();

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'must_set_password'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `users` ADD COLUMN `must_set_password` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = choose password during onboarding'' AFTER `password`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
