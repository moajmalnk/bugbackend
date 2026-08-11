-- Why: Store structured rejection reason + optional note when HR rejects onboarding.
-- Safe to re-run.

SET @db := DATABASE();

SET @has_reason := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'onboarding_rejection_reason'
);
SET @sql := IF(
  @has_reason = 0,
  'ALTER TABLE users ADD COLUMN onboarding_rejection_reason VARCHAR(64) NULL DEFAULT NULL AFTER onboarding_verified_by',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_note := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'onboarding_rejection_note'
);
SET @sql := IF(
  @has_note = 0,
  'ALTER TABLE users ADD COLUMN onboarding_rejection_note VARCHAR(500) NULL DEFAULT NULL AFTER onboarding_rejection_reason',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_action := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'onboarding_rejection_action'
);
SET @sql := IF(
  @has_action = 0,
  'ALTER TABLE users ADD COLUMN onboarding_rejection_action VARCHAR(255) NULL DEFAULT NULL AFTER onboarding_rejection_note',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
