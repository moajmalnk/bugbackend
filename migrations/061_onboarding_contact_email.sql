-- =============================================================================
-- BugRicer Migration 061 — Onboarding contact email
-- =============================================================================
-- Stores a verified personal/contact email collected during employee onboarding.
-- Safe to re-run.
-- =============================================================================

SET @db := DATABASE();

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'user_onboarding_details'
    AND COLUMN_NAME = 'contact_email'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `user_onboarding_details` ADD COLUMN `contact_email` VARCHAR(150) NULL DEFAULT NULL COMMENT ''Verified personal/contact email from onboarding'' AFTER `emergency_contact`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
