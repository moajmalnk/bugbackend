-- ============================================================================
-- Add Planned Work Status Column to work_submissions Table
-- ============================================================================
-- This migration adds a new column to track the status of planned work
-- for daily work submissions.
--
-- Column: planned_work_status
-- Type: ENUM('not_started', 'in_progress', 'completed', 'blocked', 'cancelled')
-- Default: 'not_started'
-- Position: After planned_work column
-- ============================================================================

-- Step 1: Add the planned_work_status column
ALTER TABLE `work_submissions` 
ADD COLUMN `planned_work_status` ENUM('not_started', 'in_progress', 'completed', 'blocked', 'cancelled') 
NULL DEFAULT 'not_started' 
COMMENT 'Status of the planned work: not_started, in_progress, completed, blocked, or cancelled'
AFTER `planned_work`;

-- Step 1b: Add the planned_work_notes column
ALTER TABLE `work_submissions` 
ADD COLUMN `planned_work_notes` TEXT NULL DEFAULT NULL 
COMMENT 'Additional notes or comments about the planned work status'
AFTER `planned_work_status`;

-- Step 2: Verify the columns were added successfully
SELECT 
    COLUMN_NAME, 
    DATA_TYPE, 
    COLUMN_TYPE, 
    IS_NULLABLE, 
    COLUMN_DEFAULT, 
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'work_submissions' 
AND COLUMN_NAME IN ('planned_work_status', 'planned_work_notes');

-- ============================================================================
-- Expected Output:
-- ============================================================================
-- COLUMN_NAME: planned_work_status
-- DATA_TYPE: enum
-- COLUMN_TYPE: enum('not_started','in_progress','completed','blocked','cancelled')
-- IS_NULLABLE: YES
-- COLUMN_DEFAULT: not_started
-- COLUMN_COMMENT: Status of the planned work: not_started, in_progress, completed, blocked, or cancelled
-- ============================================================================

-- Step 3 (Optional): Update existing records to set a default status
-- Uncomment the following line if you want to set all existing NULL values to 'not_started'
-- UPDATE `work_submissions` SET `planned_work_status` = 'not_started' WHERE `planned_work_status` IS NULL;

-- ============================================================================
-- Rollback Instructions (if needed):
-- ============================================================================
-- To remove these columns if you need to rollback:
-- ALTER TABLE `work_submissions` DROP COLUMN `planned_work_notes`;
-- ALTER TABLE `work_submissions` DROP COLUMN `planned_work_status`;
-- ============================================================================

-- ============================================================================
-- Status Options Reference:
-- ============================================================================
-- not_started: Work hasn't been started yet (default)
-- in_progress: Work is currently being done
-- completed: Work has been completed
-- blocked: Work is blocked by dependencies
-- cancelled: Work was cancelled/deprioritized
-- ============================================================================
