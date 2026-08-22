-- =============================================================================
-- BugRicer Migration 079 — Indexes for admin sidebar COUNT queries
-- =============================================================================
-- Sidebar badges poll pending OT / leave / WFH. Columns used in WHERE must be
-- indexed (CODO database indexing). Safe to re-run.
-- =============================================================================

SET @db := DATABASE();

SET @exist := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'work_submissions'
    AND INDEX_NAME = 'idx_ws_ot_approval_status'
);
SET @sql := IF(@exist = 0,
  'CREATE INDEX `idx_ws_ot_approval_status` ON `work_submissions` (`extra_hours_approval_status`)',
  'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
