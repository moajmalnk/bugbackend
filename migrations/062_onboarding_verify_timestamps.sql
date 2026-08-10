-- =============================================================================
-- BugRicer Migration 062 — Onboarding verification timestamps
-- =============================================================================
-- Records when emergency WhatsApp OTP, contact email OTP, and wizard completion
-- happened for audit / HR. Safe to re-run.
-- =============================================================================

SET @db := DATABASE();

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'user_onboarding_details'
    AND COLUMN_NAME = 'emergency_contact_verified_at'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `user_onboarding_details` ADD COLUMN `emergency_contact_verified_at` TIMESTAMP NULL DEFAULT NULL COMMENT ''When emergency WhatsApp OTP was verified'' AFTER `emergency_contact`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'user_onboarding_details'
    AND COLUMN_NAME = 'contact_email_verified_at'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `user_onboarding_details` ADD COLUMN `contact_email_verified_at` TIMESTAMP NULL DEFAULT NULL COMMENT ''When contact email OTP was verified'' AFTER `contact_email`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'onboarding_completed_at'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `users` ADD COLUMN `onboarding_completed_at` TIMESTAMP NULL DEFAULT NULL COMMENT ''When employee finalized onboarding wizard'' AFTER `onboarding_completed`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
