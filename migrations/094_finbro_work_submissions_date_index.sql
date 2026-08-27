-- =============================================================================
-- BugRicer Migration 094 — Finbro hours month scan index
-- =============================================================================
-- Finbro GET /hours filters work_submissions by submission_date range across all
-- users. Existing (user_id, submission_date) indexes help per-user lookups but
-- not all-users date scans under parallel payroll GETs.
-- Safe to re-run: skips if idx_ws_submission_date_user already exists.
-- =============================================================================

SET @db := DATABASE();

SET @idx_exists := (
  SELECT COUNT(1)
  FROM information_schema.statistics
  WHERE table_schema = @db
    AND table_name = 'work_submissions'
    AND index_name = 'idx_ws_submission_date_user'
);

SET @sql := IF(
  @idx_exists = 0,
  'ALTER TABLE `work_submissions` ADD KEY `idx_ws_submission_date_user` (`submission_date`, `user_id`)',
  'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
