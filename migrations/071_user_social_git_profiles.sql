-- =============================================================================
-- BugRicer Migration 071 — Git + LinkedIn/GitHub profile fields
-- =============================================================================
-- Employee-owned social/git identity on user_onboarding_details.
-- Safe to re-run.
-- =============================================================================

SET @db := DATABASE();

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'user_onboarding_details'
    AND COLUMN_NAME = 'git_username'
);
SET @sql := IF(
  @exist = 0,
  'ALTER TABLE `user_onboarding_details` ADD COLUMN `git_username` VARCHAR(100) NULL DEFAULT NULL COMMENT ''Git / GitHub login handle'' AFTER `marital_status`',
  'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'user_onboarding_details'
    AND COLUMN_NAME = 'git_email'
);
SET @sql := IF(
  @exist = 0,
  'ALTER TABLE `user_onboarding_details` ADD COLUMN `git_email` VARCHAR(150) NULL DEFAULT NULL COMMENT ''Git commit email'' AFTER `git_username`',
  'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'user_onboarding_details'
    AND COLUMN_NAME = 'github_url'
);
SET @sql := IF(
  @exist = 0,
  'ALTER TABLE `user_onboarding_details` ADD COLUMN `github_url` VARCHAR(255) NULL DEFAULT NULL COMMENT ''GitHub profile URL'' AFTER `git_email`',
  'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'user_onboarding_details'
    AND COLUMN_NAME = 'linkedin_url'
);
SET @sql := IF(
  @exist = 0,
  'ALTER TABLE `user_onboarding_details` ADD COLUMN `linkedin_url` VARCHAR(255) NULL DEFAULT NULL COMMENT ''LinkedIn profile URL'' AFTER `github_url`',
  'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
