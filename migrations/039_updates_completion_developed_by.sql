-- Completion fields for "Mark as Completed".
-- Portable: no AFTER clause (column order does not matter).
-- Safe to re-run: skips columns that already exist.

SET @db := DATABASE();

-- approved_at
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'updates' AND COLUMN_NAME = 'approved_at'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `updates` ADD COLUMN `approved_at` DATETIME NULL DEFAULT NULL',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- declined_at
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'updates' AND COLUMN_NAME = 'declined_at'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `updates` ADD COLUMN `declined_at` DATETIME NULL DEFAULT NULL',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- completed_at
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'updates' AND COLUMN_NAME = 'completed_at'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `updates` ADD COLUMN `completed_at` DATETIME NULL DEFAULT NULL',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- completion_tested
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'updates' AND COLUMN_NAME = 'completion_tested'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `updates` ADD COLUMN `completion_tested` TINYINT(1) NULL DEFAULT NULL COMMENT ''1=tested, 0=not tested''',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- completion_dev_hours
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'updates' AND COLUMN_NAME = 'completion_dev_hours'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `updates` ADD COLUMN `completion_dev_hours` DECIMAL(10,2) NULL DEFAULT NULL',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- completion_dev_started_at
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'updates' AND COLUMN_NAME = 'completion_dev_started_at'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `updates` ADD COLUMN `completion_dev_started_at` DATETIME NULL DEFAULT NULL',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- completion_dev_ended_at
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'updates' AND COLUMN_NAME = 'completion_dev_ended_at'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `updates` ADD COLUMN `completion_dev_ended_at` DATETIME NULL DEFAULT NULL',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- completion_tested_by
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'updates' AND COLUMN_NAME = 'completion_tested_by'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `updates` ADD COLUMN `completion_tested_by` VARCHAR(255) NULL DEFAULT NULL',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- completion_developed_by
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'updates' AND COLUMN_NAME = 'completion_developed_by'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `updates` ADD COLUMN `completion_developed_by` VARCHAR(255) NULL DEFAULT NULL',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- completion_notes
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'updates' AND COLUMN_NAME = 'completion_notes'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `updates` ADD COLUMN `completion_notes` TEXT NULL',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
