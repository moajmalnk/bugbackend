-- Per-request hours credit override (Official Leave editable hours). Safe to re-run.

SET @db := DATABASE();

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'hours_per_day'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE leave_requests ADD COLUMN hours_per_day DECIMAL(5,2) NULL DEFAULT NULL AFTER days_count',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
