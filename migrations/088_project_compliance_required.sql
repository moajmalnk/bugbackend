-- =============================================================================
-- BugRicer Migration 088 — Project compliance_required toggle
-- =============================================================================
-- When 0, CODO compliance is skipped for that project (no close gate, no UI).
-- Defaults to 1 so existing projects keep current behavior. Safe to re-run.
-- =============================================================================

SET @db := DATABASE();

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'projects' AND COLUMN_NAME = 'compliance_required'
);
SET @sql := IF(
  @exist = 0,
  'ALTER TABLE `projects` ADD COLUMN `compliance_required` TINYINT(1) NOT NULL DEFAULT 1 AFTER `status`',
  'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'projects' AND INDEX_NAME = 'idx_projects_compliance_required'
);
SET @sql := IF(
  @idx = 0,
  'CREATE INDEX `idx_projects_compliance_required` ON `projects` (`compliance_required`)',
  'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
