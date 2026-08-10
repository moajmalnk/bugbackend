-- =============================================================================
-- BugRicer Migration 059 — Employee Onboarding Details
-- =============================================================================
-- Adds onboarding_completed / terms / privacy timestamps on users.
-- Creates user_onboarding_details for address, statutory, banking, WFH coords.
-- Safe to re-run. Existing users remain onboarding_completed = 0 (forced wizard).
--
-- Why COLLATE utf8mb4_general_ci: must match users.id or InnoDB rejects the FK
-- (errno 150 / "Foreign key constraint is incorrectly formed").
-- =============================================================================

SET @db := DATABASE();

-- users.onboarding_completed
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'onboarding_completed'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `users` ADD COLUMN `onboarding_completed` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 when mandatory onboarding wizard finished''',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- users.terms_accepted_at
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'terms_accepted_at'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `users` ADD COLUMN `terms_accepted_at` TIMESTAMP NULL DEFAULT NULL COMMENT ''When Terms of Service were accepted''',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- users.privacy_accepted_at
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'privacy_accepted_at'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `users` ADD COLUMN `privacy_accepted_at` TIMESTAMP NULL DEFAULT NULL COMMENT ''When Privacy Policy was accepted''',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Index for onboarding guard queries
SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_onboarding_completed'
);
SET @sql := IF(@idx = 0,
  'ALTER TABLE `users` ADD INDEX `idx_users_onboarding_completed` (`onboarding_completed`)',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS `user_onboarding_details` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` VARCHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,

  `emergency_contact` VARCHAR(15) NULL DEFAULT NULL,
  `house_name_number` VARCHAR(150) NULL DEFAULT NULL,
  `landmark` VARCHAR(200) NULL DEFAULT NULL,
  `city` VARCHAR(100) NULL DEFAULT NULL,
  `post_office` VARCHAR(100) NULL DEFAULT NULL,
  `pin_code` VARCHAR(10) NULL DEFAULT NULL,
  `district` VARCHAR(100) NULL DEFAULT NULL,
  `state` VARCHAR(100) NULL DEFAULT NULL,
  `country` VARCHAR(100) NULL DEFAULT NULL,

  `wfh_latitude` DECIMAL(10, 8) NULL DEFAULT NULL,
  `wfh_longitude` DECIMAL(11, 8) NULL DEFAULT NULL,

  `aadhaar_number` VARCHAR(20) NULL DEFAULT NULL,
  `aadhaar_file_path` VARCHAR(500) NULL DEFAULT NULL,
  `pan_number` VARCHAR(20) NULL DEFAULT NULL,
  `pan_file_path` VARCHAR(500) NULL DEFAULT NULL,
  `offer_letter_path` VARCHAR(500) NULL DEFAULT NULL,
  `nda_path` VARCHAR(500) NULL DEFAULT NULL,

  `account_holder_name` VARCHAR(150) NULL DEFAULT NULL,
  `bank_name` VARCHAR(150) NULL DEFAULT NULL,
  `account_number` VARCHAR(40) NULL DEFAULT NULL,
  `ifsc_code` VARCHAR(20) NULL DEFAULT NULL,
  `branch_name` VARCHAR(150) NULL DEFAULT NULL,
  `account_type` VARCHAR(40) NULL DEFAULT NULL,
  `upi_id` VARCHAR(100) NULL DEFAULT NULL,
  `upi_linked_phone` VARCHAR(15) NULL DEFAULT NULL,

  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_onboarding_user_id` (`user_id`),
  CONSTRAINT `fk_user_onboarding_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
