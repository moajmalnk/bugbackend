-- =============================================================================
-- BugRicer Migration 116 — work_submissions overtime / break / extra-hours columns
-- =============================================================================
-- Why: These columns were only ever auto-added lazily by the submit endpoints,
-- in an order where each ALTER referenced a column that did not exist yet
-- (AFTER approval_reason / AFTER overtime_hours). Tables could end up missing
-- some of them, and api/users/work_stats.php (which SELECTs all of them)
-- returned 500 for every user.
--
-- Adds each column in dependency order. Additive only; safe to re-run
-- (information_schema guards). Mirrors br_ensure_work_submission_ot_columns().
-- =============================================================================

SET @db := DATABASE();

-- 1. overtime_hours
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_submissions' AND COLUMN_NAME = 'overtime_hours'
);
SET @sql := IF(
  @exist = 0,
  'ALTER TABLE `work_submissions` ADD COLUMN `overtime_hours` DECIMAL(6,2) DEFAULT 0 AFTER `hours_today`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. requested_extra_hours
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_submissions' AND COLUMN_NAME = 'requested_extra_hours'
);
SET @sql := IF(
  @exist = 0,
  'ALTER TABLE `work_submissions` ADD COLUMN `requested_extra_hours` DECIMAL(6,2) DEFAULT 0 AFTER `overtime_hours`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. approval_reason
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_submissions' AND COLUMN_NAME = 'approval_reason'
);
SET @sql := IF(
  @exist = 0,
  'ALTER TABLE `work_submissions` ADD COLUMN `approval_reason` TEXT NULL AFTER `requested_extra_hours`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. break_entries
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_submissions' AND COLUMN_NAME = 'break_entries'
);
SET @sql := IF(
  @exist = 0,
  'ALTER TABLE `work_submissions` ADD COLUMN `break_entries` JSON NULL DEFAULT NULL AFTER `approval_reason`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5. total_break_minutes
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_submissions' AND COLUMN_NAME = 'total_break_minutes'
);
SET @sql := IF(
  @exist = 0,
  'ALTER TABLE `work_submissions` ADD COLUMN `total_break_minutes` INT DEFAULT 0 AFTER `break_entries`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 6. extra_hours_approval_status
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_submissions' AND COLUMN_NAME = 'extra_hours_approval_status'
);
SET @sql := IF(
  @exist = 0,
  'ALTER TABLE `work_submissions` ADD COLUMN `extra_hours_approval_status` VARCHAR(24) NOT NULL DEFAULT ''none'' AFTER `approval_reason`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 7. extra_hours_approved_amount
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_submissions' AND COLUMN_NAME = 'extra_hours_approved_amount'
);
SET @sql := IF(
  @exist = 0,
  'ALTER TABLE `work_submissions` ADD COLUMN `extra_hours_approved_amount` DECIMAL(6,2) NULL DEFAULT NULL AFTER `extra_hours_approval_status`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 8. extra_hours_reviewed_by
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_submissions' AND COLUMN_NAME = 'extra_hours_reviewed_by'
);
SET @sql := IF(
  @exist = 0,
  'ALTER TABLE `work_submissions` ADD COLUMN `extra_hours_reviewed_by` INT UNSIGNED NULL DEFAULT NULL AFTER `extra_hours_approved_amount`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 9. extra_hours_reviewed_at
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_submissions' AND COLUMN_NAME = 'extra_hours_reviewed_at'
);
SET @sql := IF(
  @exist = 0,
  'ALTER TABLE `work_submissions` ADD COLUMN `extra_hours_reviewed_at` DATETIME NULL DEFAULT NULL AFTER `extra_hours_reviewed_by`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 10. extra_hours_admin_note
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_submissions' AND COLUMN_NAME = 'extra_hours_admin_note'
);
SET @sql := IF(
  @exist = 0,
  'ALTER TABLE `work_submissions` ADD COLUMN `extra_hours_admin_note` TEXT NULL DEFAULT NULL AFTER `extra_hours_reviewed_at`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
