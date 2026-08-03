-- =============================================================================
-- BugRicer Migration 046 — Late check-in strikes + Office/WFH work mode
-- =============================================================================
-- - work_submissions.work_mode / is_late / late_strike_consumed
-- - attendance_office_restrictions (next-week Office-only after 3 late strikes)
-- Safe to re-run.
-- =============================================================================

SET @db := DATABASE();

-- work_mode
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'work_submissions'
    AND COLUMN_NAME = 'work_mode'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `work_submissions` ADD COLUMN `work_mode` ENUM(''office'',''wfh'') NULL DEFAULT NULL AFTER `check_in_time`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- is_late
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'work_submissions'
    AND COLUMN_NAME = 'is_late'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `work_submissions` ADD COLUMN `is_late` TINYINT(1) NOT NULL DEFAULT 0 AFTER `work_mode`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- late_strike_consumed
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'work_submissions'
    AND COLUMN_NAME = 'late_strike_consumed'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `work_submissions` ADD COLUMN `late_strike_consumed` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_late`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'work_submissions'
    AND INDEX_NAME = 'idx_ws_late_strikes'
);
SET @sql := IF(@idx = 0,
  'CREATE INDEX `idx_ws_late_strikes` ON `work_submissions` (`user_id`, `is_late`, `late_strike_consumed`)',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS `attendance_office_restrictions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` VARCHAR(64) NOT NULL,
  `week_start` DATE NOT NULL COMMENT 'Monday of Office-only week',
  `week_end` DATE NOT NULL COMMENT 'Sunday of Office-only week',
  `triggered_at` DATETIME NOT NULL,
  `trigger_late_count` INT NOT NULL DEFAULT 3,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_week_start` (`user_id`, `week_start`),
  KEY `idx_user_week_range` (`user_id`, `week_start`, `week_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
