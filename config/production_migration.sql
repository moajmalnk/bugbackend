-- Production Migration for Individual Completion Tracking
-- Database: u524154866_bugfixer (Production)
-- This migration adds individual completion tracking to shared_task_assignees table

-- Step 1: Add the completed_at column
ALTER TABLE shared_task_assignees 
ADD COLUMN completed_at DATETIME DEFAULT NULL;

-- Step 2: Add index for better performance when querying completion status
CREATE INDEX idx_completed_at ON shared_task_assignees(completed_at);

-- Step 3: Verify the migration
DESCRIBE shared_task_assignees;

-- Step 4: Check if there are existing assignees to test with
SELECT COUNT(*) as total_assignees FROM shared_task_assignees;

-- Step 5: Show sample of current data structure
SELECT * FROM shared_task_assignees LIMIT 5;
