-- Add planned_projects and planned_work columns to work_submissions table
-- Run this SQL to add the new fields for check-in planning

-- Add planned_projects column (JSON array of project IDs)
ALTER TABLE `work_submissions` 
ADD COLUMN `planned_projects` JSON NULL DEFAULT NULL 
AFTER `check_in_time`;

-- Add planned_work column (TEXT for planned work description)
ALTER TABLE `work_submissions` 
ADD COLUMN `planned_work` TEXT NULL DEFAULT NULL 
AFTER `planned_projects`;

-- Verify the columns were added
-- SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT 
-- FROM INFORMATION_SCHEMA.COLUMNS 
-- WHERE TABLE_SCHEMA = DATABASE() 
-- AND TABLE_NAME = 'work_submissions' 
-- AND COLUMN_NAME IN ('planned_projects', 'planned_work');

