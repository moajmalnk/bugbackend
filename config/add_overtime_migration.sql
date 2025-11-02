-- Migration script to add overtime_hours column to existing work_submissions table
-- Run this script on existing databases to add the overtime tracking feature

-- Add overtime_hours column if it doesn't exist
ALTER TABLE work_submissions 
ADD COLUMN IF NOT EXISTS overtime_hours DECIMAL(6,2) DEFAULT 0 
AFTER hours_today;

-- Update existing records to calculate overtime based on hours_today
-- If hours_today > 8, set overtime_hours = hours_today - 8, otherwise 0
UPDATE work_submissions 
SET overtime_hours = CASE 
    WHEN hours_today > 8 THEN hours_today - 8 
    ELSE 0 
END
WHERE overtime_hours = 0;

-- Add index for better performance on overtime queries
CREATE INDEX IF NOT EXISTS idx_overtime ON work_submissions(overtime_hours);

-- Verify the migration
SELECT 
    COUNT(*) as total_records,
    COUNT(CASE WHEN overtime_hours > 0 THEN 1 END) as records_with_overtime,
    AVG(overtime_hours) as avg_overtime,
    MAX(overtime_hours) as max_overtime
FROM work_submissions;
