-- User Activity Tracking System
-- Tracks user activity sessions for calculating active hours

-- First, check if the users table exists and get its structure
-- This will help us understand the correct data types

-- Create user_activity_sessions table to track detailed activity
-- Note: We'll create the table first without foreign key, then add it separately
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

-- Add indexes for performance
ALTER TABLE user_activity_sessions 
ADD INDEX idx_user_activity_user_id (user_id),
ADD INDEX idx_user_activity_session_start (session_start),
ADD INDEX idx_user_activity_session_end (session_end),
ADD INDEX idx_user_activity_user_date (user_id, session_start),
ADD INDEX idx_user_activity_active (is_active);

-- Add foreign key constraint only if the users table exists and has the correct structure
-- This will be added conditionally to avoid errors
SET @sql = (
    SELECT IF(
        EXISTS(
            SELECT 1 
            FROM information_schema.tables 
            WHERE table_schema = DATABASE() 
            AND table_name = 'users'
        ),
        'ALTER TABLE user_activity_sessions ADD CONSTRAINT fk_user_activity_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE',
        'SELECT "Users table not found - skipping foreign key constraint" as message'
    )
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add comment to document the table purpose
ALTER TABLE user_activity_sessions COMMENT = 'Tracks user activity sessions for calculating active hours and presence';
