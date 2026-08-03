-- =============================================================================
-- BugRicer Migration 047 — Office check-in geofence coords
-- =============================================================================
-- Stores GPS proof for Office check-ins (Wired In Coworks, 500 m radius).
-- Safe to re-run.
-- =============================================================================

SET @db := DATABASE();

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'work_submissions'
    AND COLUMN_NAME = 'check_in_lat'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `work_submissions` ADD COLUMN `check_in_lat` DECIMAL(10,7) NULL DEFAULT NULL AFTER `late_strike_consumed`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'work_submissions'
    AND COLUMN_NAME = 'check_in_lng'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `work_submissions` ADD COLUMN `check_in_lng` DECIMAL(10,7) NULL DEFAULT NULL AFTER `check_in_lat`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'work_submissions'
    AND COLUMN_NAME = 'check_in_accuracy_m'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `work_submissions` ADD COLUMN `check_in_accuracy_m` DECIMAL(8,2) NULL DEFAULT NULL AFTER `check_in_lng`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'work_submissions'
    AND COLUMN_NAME = 'check_in_distance_m'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `work_submissions` ADD COLUMN `check_in_distance_m` DECIMAL(8,2) NULL DEFAULT NULL AFTER `check_in_accuracy_m`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
