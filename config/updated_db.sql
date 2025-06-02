-- ================================================================
-- COMPREHENSIVE DATABASE UPDATES FOR BUGRICER - ROLE-BASED STATS
-- ================================================================
-- This file contains all necessary database schema updates
-- Run this file to enable role-based statistics functionality

-- ================================================================
-- STEP 1: ADD OR FIX UPDATED_BY COLUMN
-- ================================================================

-- Check if updated_by column exists and fix its type if needed
-- If it exists as varchar, convert to INT; if it doesn't exist, create it
SET @column_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'bugs' 
    AND COLUMN_NAME = 'updated_by'
);

-- Add column if it doesn't exist
SET @sql = IF(@column_exists = 0, 
    'ALTER TABLE bugs ADD COLUMN updated_by INT NULL', 
    'SELECT "Column updated_by already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Fix column type if it exists but is wrong type (varchar to INT)
ALTER TABLE bugs MODIFY COLUMN updated_by INT NULL;

-- ================================================================
-- STEP 2: ADD OR FIX UPDATED_AT COLUMN
-- ================================================================

-- Check if updated_at column exists
SET @column_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'bugs' 
    AND COLUMN_NAME = 'updated_at'
);

-- Add column if it doesn't exist
SET @sql = IF(@column_exists = 0, 
    'ALTER TABLE bugs ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL', 
    'SELECT "Column updated_at already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ================================================================
-- STEP 3: CREATE INDEXES FOR PERFORMANCE
-- ================================================================

-- Create indexes (using IF NOT EXISTS to avoid errors)
CREATE INDEX IF NOT EXISTS idx_bugs_updated_by ON bugs(updated_by);
CREATE INDEX IF NOT EXISTS idx_bugs_status ON bugs(status);
CREATE INDEX IF NOT EXISTS idx_bugs_updated_by_status ON bugs(updated_by, status);
CREATE INDEX IF NOT EXISTS idx_bugs_reported_by ON bugs(reported_by);
CREATE INDEX IF NOT EXISTS idx_project_members_user_id ON project_members(user_id);
CREATE INDEX IF NOT EXISTS idx_project_members_project_id ON project_members(project_id);

-- ================================================================
-- STEP 4: UPDATE HISTORICAL DATA
-- ================================================================

-- Set updated_at = created_at for existing bugs that don't have updated_at set
UPDATE bugs 
SET updated_at = created_at 
WHERE updated_at IS NULL;

-- ================================================================
-- STEP 5: CREATE TRIGGER FOR AUTOMATIC TIMESTAMP UPDATES
-- ================================================================

-- Drop trigger if it exists and recreate it
DROP TRIGGER IF EXISTS bugs_update_timestamp;

DELIMITER //
CREATE TRIGGER bugs_update_timestamp 
BEFORE UPDATE ON bugs
FOR EACH ROW
BEGIN
    -- Update timestamp and updated_by when important fields change
    IF NEW.status != OLD.status OR 
       NEW.priority != OLD.priority OR 
       NEW.description != OLD.description OR
       NEW.title != OLD.title THEN
        SET NEW.updated_at = CURRENT_TIMESTAMP;
        
        -- If updated_by is not being explicitly set, keep the old value
        IF NEW.updated_by = OLD.updated_by THEN
            SET NEW.updated_by = OLD.updated_by;
        END IF;
    END IF;
END//
DELIMITER ;

-- ================================================================
-- STEP 6: ADD FOREIGN KEY CONSTRAINT (OPTIONAL - SAFER TO SKIP)
-- ================================================================

-- Note: We skip foreign key constraints to avoid issues
-- The application will handle referential integrity
-- Uncomment below if you want to add foreign key constraint:

-- ALTER TABLE bugs 
-- ADD CONSTRAINT fk_bugs_updated_by 
-- FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL;

-- ================================================================
-- STEP 7: VERIFICATION QUERIES
-- ================================================================

-- Verify the changes
SELECT 'Database structure after updates:' as message;
DESCRIBE bugs;

-- Test statistics queries
SELECT 'Testing statistics queries:' as message;

-- Test total bugs query
SELECT 
    'Total bugs query test' as test_type,
    COUNT(*) as count 
FROM bugs 
WHERE reported_by IS NOT NULL;

-- Test total fixes query  
SELECT 
    'Total fixes query test' as test_type,
    COUNT(*) as count 
FROM bugs 
WHERE updated_by IS NOT NULL AND status = 'fixed';

-- Test recent activity query
SELECT 
    'Recent activity query test' as test_type,
    COUNT(*) as count
FROM (
    SELECT 'bug' as type, title, created_at 
    FROM bugs 
    WHERE reported_by IS NOT NULL
    UNION ALL
    SELECT 'fix' as type, CONCAT('Fixed: ', title) as title, updated_at as created_at
    FROM bugs 
    WHERE updated_by IS NOT NULL AND status = 'fixed'
) AS activity;

-- ================================================================
-- STEP 8: SAMPLE DATA FOR TESTING (OPTIONAL)
-- ================================================================

-- Uncomment to add sample data for testing
-- INSERT INTO bugs (title, description, priority, status, project_id, reported_by, updated_by, created_at, updated_at) 
-- VALUES 
-- ('Sample Bug 1', 'This is a sample bug for testing', 'high', 'fixed', 1, 2, 3, NOW(), NOW()),
-- ('Sample Bug 2', 'Another sample bug for testing', 'medium', 'in_progress', 1, 2, NULL, NOW(), NOW());

SELECT 'Database updates completed successfully!' as message;