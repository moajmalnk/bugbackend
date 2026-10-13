-- Why: an admin can ask a person to link Google before the dashboard opens.
-- The flag stays on until a Google refresh token is saved for that user.
-- Re-runnable: guarded by an information_schema check.

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'google_connect_required'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE users ADD COLUMN google_connect_required TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
