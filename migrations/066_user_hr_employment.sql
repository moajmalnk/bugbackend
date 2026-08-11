-- =============================================================================
-- BugRicer Migration 066 — HR employment profile + personal demographics
-- =============================================================================
-- User-owned (onboarding): date_of_birth, gender, marital_status
-- Admin-owned (users): employee_code, job title/level, department, reports_to,
--   contract_type, offer_letter_issued, probation_end_date, employment_status
-- Safe to re-run.
-- =============================================================================

SET @db := DATABASE();

-- ---------------------------------------------------------------------------
-- user_onboarding_details — personal demographics (collected from employee)
-- ---------------------------------------------------------------------------

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_onboarding_details' AND COLUMN_NAME = 'date_of_birth'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `user_onboarding_details` ADD COLUMN `date_of_birth` DATE NULL DEFAULT NULL COMMENT ''Employee date of birth''',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_onboarding_details' AND COLUMN_NAME = 'gender'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `user_onboarding_details` ADD COLUMN `gender` ENUM(''male'',''female'',''other'',''prefer_not_to_say'') NULL DEFAULT NULL COMMENT ''Employee gender''',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_onboarding_details' AND COLUMN_NAME = 'marital_status'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `user_onboarding_details` ADD COLUMN `marital_status` ENUM(''single'',''married'',''divorced'',''widowed'',''other'') NULL DEFAULT NULL COMMENT ''Employee marital status''',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------------
-- users — admin HR employment fields
-- ---------------------------------------------------------------------------

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'employee_code'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `users` ADD COLUMN `employee_code` VARCHAR(32) NULL DEFAULT NULL COMMENT ''CODO cipher employee ID e.g. CODO-TPLN-KLTK'' AFTER `joining_date`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'job_title'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `users` ADD COLUMN `job_title` VARCHAR(200) NULL DEFAULT NULL COMMENT ''HR job title'' AFTER `employee_code`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'job_level'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `users` ADD COLUMN `job_level` VARCHAR(80) NULL DEFAULT NULL COMMENT ''HR job level e.g. Senior, Intern'' AFTER `job_title`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'department'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `users` ADD COLUMN `department` VARCHAR(150) NULL DEFAULT NULL COMMENT ''HR department'' AFTER `job_level`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'reports_to_user_id'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `users` ADD COLUMN `reports_to_user_id` VARCHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT ''Manager users.id'' AFTER `department`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'contract_type'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `users` ADD COLUMN `contract_type` ENUM(''full_time'',''remote'',''part_time'',''contract'',''intern'',''other'') NULL DEFAULT NULL COMMENT ''Employment contract type'' AFTER `reports_to_user_id`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'offer_letter_issued'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `users` ADD COLUMN `offer_letter_issued` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 when offer letter issued (Yes/No)'' AFTER `contract_type`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'probation_end_date'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `users` ADD COLUMN `probation_end_date` DATE NULL DEFAULT NULL COMMENT ''Probation end date; NULL = NILL'' AFTER `offer_letter_issued`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'employment_status'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `users` ADD COLUMN `employment_status` ENUM(''active'',''inactive'') NOT NULL DEFAULT ''active'' COMMENT ''HR employment status; syncs with account_active'' AFTER `probation_end_date`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Unique employee_code (multiple NULLs allowed in MySQL)
SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND INDEX_NAME = 'uq_users_employee_code'
);
SET @sql := IF(@idx = 0,
  'ALTER TABLE `users` ADD UNIQUE INDEX `uq_users_employee_code` (`employee_code`)',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_department'
);
SET @sql := IF(@idx = 0,
  'ALTER TABLE `users` ADD INDEX `idx_users_department` (`department`)',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_employment_status'
);
SET @sql := IF(@idx = 0,
  'ALTER TABLE `users` ADD INDEX `idx_users_employment_status` (`employment_status`)',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_reports_to'
);
SET @sql := IF(@idx = 0,
  'ALTER TABLE `users` ADD INDEX `idx_users_reports_to` (`reports_to_user_id`)',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- FK for reports_to (skip if already present or charset mismatch)
SET @fk := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND CONSTRAINT_NAME = 'fk_users_reports_to'
);
SET @sql := IF(@fk = 0,
  'ALTER TABLE `users` ADD CONSTRAINT `fk_users_reports_to` FOREIGN KEY (`reports_to_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Align employment_status with existing account_active
UPDATE `users`
SET `employment_status` = IF(COALESCE(`account_active`, 1) = 1, 'active', 'inactive')
WHERE `employment_status` IS NOT NULL;
