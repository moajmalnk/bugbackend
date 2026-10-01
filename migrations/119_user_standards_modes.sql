-- Per-user CODO Rules / Cursor Tips modes + Cursor Tips acknowledgements.
-- NULL mode = role default (CODO: required for developer / CODO tester, optional for creator;
-- Cursor Tips: optional). Re-runnable: guarded by information_schema checks.

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'codo_rules_mode'
);
SET @sql := IF(@col_exists = 0,
  "ALTER TABLE users ADD COLUMN codo_rules_mode ENUM('required','optional','hidden') NULL DEFAULT NULL",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'cursor_tips_mode'
);
SET @sql := IF(@col_exists = 0,
  "ALTER TABLE users ADD COLUMN cursor_tips_mode ENUM('required','optional','hidden') NULL DEFAULT NULL",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `cursor_tip_acknowledgements` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tip_id` INT UNSIGNED NOT NULL,
  `user_id` VARCHAR(36) NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'acknowledged',
  `acknowledged_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cursor_tip_ack_tip_user` (`tip_id`, `user_id`),
  KEY `idx_cursor_tip_ack_user` (`user_id`),
  KEY `idx_cursor_tip_ack_status` (`status`),
  CONSTRAINT `fk_cursor_tip_ack_tip`
    FOREIGN KEY (`tip_id`) REFERENCES `cursor_tips` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
