-- User Activity Tracking System (Safe Version)
-- Tracks user activity sessions for calculating active hours
-- This version checks for existing indexes before adding them

-- Create user_activity_sessions table to track detailed activity
CREATE TABLE IF NOT EXISTS user_activity_sessions (
    id VARCHAR(36) NOT NULL PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    session_start DATETIME NOT NULL,
    session_end DATETIME NULL DEFAULT NULL,
    duration_minutes INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add indexes for performance (only if they don't exist)
-- Check and add idx_user_activity_user_id
SET @sql = (
    SELECT IF(
        NOT EXISTS(
            SELECT 1 
            FROM information_schema.statistics 
            WHERE table_schema = DATABASE() 
            AND table_name = 'user_activity_sessions' 
            AND index_name = 'idx_user_activity_user_id'
        ),
        'ALTER TABLE user_activity_sessions ADD INDEX idx_user_activity_user_id (user_id)',
        'SELECT "Index idx_user_activity_user_id already exists" as message'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check and add idx_user_activity_session_start
SET @sql = (
    SELECT IF(
        NOT EXISTS(
            SELECT 1 
            FROM information_schema.statistics 
            WHERE table_schema = DATABASE() 
            AND table_name = 'user_activity_sessions' 
            AND index_name = 'idx_user_activity_session_start'
        ),
        'ALTER TABLE user_activity_sessions ADD INDEX idx_user_activity_session_start (session_start)',
        'SELECT "Index idx_user_activity_session_start already exists" as message'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check and add idx_user_activity_session_end
SET @sql = (
    SELECT IF(
        NOT EXISTS(
            SELECT 1 
            FROM information_schema.statistics 
            WHERE table_schema = DATABASE() 
            AND table_name = 'user_activity_sessions' 
            AND index_name = 'idx_user_activity_session_end'
        ),
        'ALTER TABLE user_activity_sessions ADD INDEX idx_user_activity_session_end (session_end)',
        'SELECT "Index idx_user_activity_session_end already exists" as message'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check and add idx_user_activity_user_date
SET @sql = (
    SELECT IF(
        NOT EXISTS(
            SELECT 1 
            FROM information_schema.statistics 
            WHERE table_schema = DATABASE() 
            AND table_name = 'user_activity_sessions' 
            AND index_name = 'idx_user_activity_user_date'
        ),
        'ALTER TABLE user_activity_sessions ADD INDEX idx_user_activity_user_date (user_id, session_start)',
        'SELECT "Index idx_user_activity_user_date already exists" as message'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check and add idx_user_activity_active
SET @sql = (
    SELECT IF(
        NOT EXISTS(
            SELECT 1 
            FROM information_schema.statistics 
            WHERE table_schema = DATABASE() 
            AND table_name = 'user_activity_sessions' 
            AND index_name = 'idx_user_activity_active'
        ),
        'ALTER TABLE user_activity_sessions ADD INDEX idx_user_activity_active (is_active)',
        'SELECT "Index idx_user_activity_active already exists" as message'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add comment to document the table purpose
ALTER TABLE user_activity_sessions COMMENT = 'Tracks user activity sessions for calculating active hours and presence';

-- Note: This version safely handles existing indexes and won't cause duplicate key errors
