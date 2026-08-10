-- =============================================================================
-- BugRicer Migration 060 — Onboarding verification status
-- =============================================================================
-- After employees complete the wizard, documents stay "pending" until an admin
-- verifies them. Safe to re-run.
-- =============================================================================

SET @db := DATABASE();

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'onboarding_verification_status'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `users` ADD COLUMN `onboarding_verification_status` ENUM(''none'',''pending'',''verified'',''rejected'') NOT NULL DEFAULT ''none'' COMMENT ''HR verification of onboarding docs''',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'onboarding_verified_at'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `users` ADD COLUMN `onboarding_verified_at` TIMESTAMP NULL DEFAULT NULL',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'onboarding_verified_by'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `users` ADD COLUMN `onboarding_verified_by` VARCHAR(36) NULL DEFAULT NULL',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_onboarding_verification'
);
SET @sql := IF(@idx = 0,
  'ALTER TABLE `users` ADD INDEX `idx_users_onboarding_verification` (`onboarding_verification_status`)',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
