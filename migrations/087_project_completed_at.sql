-- =============================================================================
-- BugRicer Migration 087 — Project completed_at (when status → completed)
-- =============================================================================
-- Stamp when a project is marked completed / release_ready so overview can
-- show the completion date under Status. Safe to re-run.
-- =============================================================================

SET @db := DATABASE();

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'projects' AND COLUMN_NAME = 'completed_at'
);
SET @sql := IF(
  @exist = 0,
  'ALTER TABLE `projects` ADD COLUMN `completed_at` DATETIME NULL DEFAULT NULL AFTER `status`',
  'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'projects' AND INDEX_NAME = 'idx_projects_completed_at'
);
SET @sql := IF(
  @idx = 0,
  'CREATE INDEX `idx_projects_completed_at` ON `projects` (`completed_at`)',
  'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Backfill already-closed projects so overview shows a date immediately
UPDATE `projects`
SET `completed_at` = COALESCE(`updated_at`, `created_at`, CURRENT_TIMESTAMP)
WHERE `status` IN ('completed', 'release_ready')
  AND `completed_at` IS NULL;
