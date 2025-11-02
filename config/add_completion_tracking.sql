-- Migration to add individual completion tracking to shared_task_assignees table
-- Run this migration to enable individual completion status tracking

ALTER TABLE shared_task_assignees 
ADD COLUMN completed_at DATETIME DEFAULT NULL;

-- Add index for better performance when querying completion status
CREATE INDEX idx_completed_at ON shared_task_assignees(completed_at);

-- Verify the migration
DESCRIBE shared_task_assignees;
