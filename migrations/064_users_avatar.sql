-- =============================================================================
-- BugRicer Migration 064 — users.avatar for onboarding / profile photos
-- =============================================================================
-- Onboarding + messaging write users.avatar, but some DBs only had
-- profile_picture / profile_picture_url. Without avatar, photos never persisted
-- and hard refresh fell back to initials. Safe to re-run.
-- =============================================================================

SET @db := DATABASE();

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'avatar'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `users` ADD COLUMN `avatar` VARCHAR(500) NULL DEFAULT NULL COMMENT ''Profile photo path (onboarding / uploads)'' AFTER `email`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Backfill from legacy profile_picture when avatar is empty.
SET @has_pp := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'profile_picture'
);
SET @sql := IF(@has_pp > 0,
  'UPDATE `users`
   SET `avatar` = `profile_picture`
   WHERE (`avatar` IS NULL OR `avatar` = '''')
     AND `profile_picture` IS NOT NULL
     AND `profile_picture` <> ''''',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
