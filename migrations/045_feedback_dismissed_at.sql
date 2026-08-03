-- =============================================================================
-- BugRicer Migration 045 — Feedback prompt dismiss snooze (1 week)
-- =============================================================================
-- Adds dismissed_at so "Maybe Later" suppresses the prompt for 7 days.
-- Safe to re-run.
-- =============================================================================

SET @db := DATABASE();

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'user_feedback_tracking'
    AND COLUMN_NAME = 'dismissed_at'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `user_feedback_tracking` ADD COLUMN `dismissed_at` DATETIME NULL DEFAULT NULL',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'user_feedback_tracking'
    AND INDEX_NAME = 'idx_user_feedback_tracking_dismissed'
);
SET @sql := IF(@idx = 0,
  'CREATE INDEX `idx_user_feedback_tracking_dismissed` ON `user_feedback_tracking` (`dismissed_at`)',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
