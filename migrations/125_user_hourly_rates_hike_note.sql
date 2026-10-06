-- =============================================================================
-- BugRicer Migration 125 — Salary hike note on user_hourly_rates
-- =============================================================================
-- Additive: optional note / reason for each rate change (hike history).
-- Safe to re-run.
-- =============================================================================

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'user_hourly_rates'
    AND COLUMN_NAME = 'note'
);

SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `user_hourly_rates`
     ADD COLUMN `note` VARCHAR(500) NULL DEFAULT NULL AFTER `effective_from`',
  'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
