-- Why: HR may reject for several issues at once; store comma-separated codes + longer action copy.
-- Safe to re-run.

SET @db := DATABASE();

SET @has_reason := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'onboarding_rejection_reason'
);
SET @sql := IF(
  @has_reason > 0,
  'ALTER TABLE users MODIFY COLUMN onboarding_rejection_reason VARCHAR(255) NULL DEFAULT NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_action := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'onboarding_rejection_action'
);
SET @sql := IF(
  @has_action > 0,
  'ALTER TABLE users MODIFY COLUMN onboarding_rejection_action VARCHAR(1000) NULL DEFAULT NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
