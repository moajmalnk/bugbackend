-- =============================================================================
-- BugRicer Migration 126 — Project incentive adjustments
-- =============================================================================
-- Adds project_incentive type + optional project_id on month adjustments so
-- payroll incentives are tied to a real assigned project. Safe to re-run.
-- =============================================================================

-- Expand adjustment type enum (MySQL: MODIFY rewrites the full ENUM list).
ALTER TABLE `attendance_month_adjustments`
  MODIFY COLUMN `type` ENUM(
    'advance',
    'deduction',
    'credit',
    'other',
    'project_incentive'
  ) NOT NULL DEFAULT 'deduction';

-- Link incentive rows to the project they were earned on.
SET @pv_has_project := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'attendance_month_adjustments'
    AND COLUMN_NAME = 'project_id'
);
SET @pv_sql := IF(
  @pv_has_project = 0,
  'ALTER TABLE `attendance_month_adjustments`
     ADD COLUMN `project_id` VARCHAR(36) NULL DEFAULT NULL AFTER `reason`,
     ADD KEY `idx_att_adj_project` (`project_id`)',
  'SELECT 1'
);
PREPARE pv_stmt FROM @pv_sql;
EXECUTE pv_stmt;
DEALLOCATE PREPARE pv_stmt;
