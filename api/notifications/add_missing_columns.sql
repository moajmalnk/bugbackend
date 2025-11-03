-- Production Migration: Add Missing Columns to notifications table
-- Run this via phpMyAdmin or MySQL command line
-- This script is idempotent - safe to run multiple times

-- Check and add entity_type column
SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'notifications' 
    AND COLUMN_NAME = 'entity_type'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE notifications ADD COLUMN entity_type VARCHAR(50) DEFAULT NULL COMMENT ''Type of entity: bug, task, meet, doc, update, project''',
    'SELECT ''entity_type column already exists'' AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check and add entity_id column
SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'notifications' 
    AND COLUMN_NAME = 'entity_id'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE notifications ADD COLUMN entity_id VARCHAR(36) DEFAULT NULL COMMENT ''ID of the related entity''',
    'SELECT ''entity_id column already exists'' AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check and add project_id column
SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'notifications' 
    AND COLUMN_NAME = 'project_id'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE notifications ADD COLUMN project_id VARCHAR(36) DEFAULT NULL COMMENT ''Related project ID''',
    'SELECT ''project_id column already exists'' AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verify columns were added
SELECT 
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'notifications' 
AND COLUMN_NAME IN ('entity_type', 'entity_id', 'project_id')
ORDER BY COLUMN_NAME;

