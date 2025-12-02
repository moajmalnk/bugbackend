-- Add check_in_time column to work_submissions table
-- Run this SQL if the auto-migration fails

-- Check if column exists first (optional, but good practice)
-- If column doesn't exist, add it
ALTER TABLE `work_submissions` 
ADD COLUMN `check_in_time` TIMESTAMP NULL DEFAULT NULL 
AFTER `start_time`;

-- Verify the column was added
-- SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT 
-- FROM INFORMATION_SCHEMA.COLUMNS 
-- WHERE TABLE_SCHEMA = DATABASE() 
-- AND TABLE_NAME = 'work_submissions' 
-- AND COLUMN_NAME = 'check_in_time';

