-- Response status for CODO rule acknowledgements: acknowledged | doubt | not_required
-- Safe to re-run: skips if column already exists.
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'codo_rule_acknowledgements'
    AND COLUMN_NAME = 'status'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `codo_rule_acknowledgements` ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT ''acknowledged'' AFTER `user_id`, ADD KEY `idx_codo_ack_status` (`status`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
