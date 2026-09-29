-- =============================================================================
-- BugRicer Migration 112 — Birthday wish messages + wall ordering index
-- =============================================================================
-- Safe to re-run (the API also self-heals the column via br_ensure_birthday_wishes_table).
-- =============================================================================

SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'birthday_wishes' AND COLUMN_NAME = 'message'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `birthday_wishes` ADD COLUMN `message` VARCHAR(280) NULL AFTER `wish_date`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'birthday_wishes' AND INDEX_NAME = 'idx_birthday_wishes_wall'
);
SET @sql := IF(@idx_exists = 0,
  'CREATE INDEX `idx_birthday_wishes_wall` ON `birthday_wishes` (`to_user_id`, `wish_date`, `created_at`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
