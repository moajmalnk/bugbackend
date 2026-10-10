-- Per-user onboarding mode chosen by an admin in Edit User.
-- NULL = role default (required for developer / CODO tester; off for client tester, creator, admin).
-- required = locked into the wizard until submitted; optional = fill from Profile any time; off = no onboarding.
-- Re-runnable: guarded by an information_schema check.

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'onboarding_mode'
);
SET @sql := IF(@col_exists = 0,
  "ALTER TABLE users ADD COLUMN onboarding_mode ENUM('required','optional','off') NULL DEFAULT NULL",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
