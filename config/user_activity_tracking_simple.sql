-- User Activity Tracking System (Simple Version)
-- Tracks user activity sessions for calculating active hours
-- This version doesn't use foreign key constraints to avoid hosting issues

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

-- Add indexes for performance
ALTER TABLE user_activity_sessions 
ADD INDEX idx_user_activity_user_id (user_id),
ADD INDEX idx_user_activity_session_start (session_start),
ADD INDEX idx_user_activity_session_end (session_end),
ADD INDEX idx_user_activity_user_date (user_id, session_start),
ADD INDEX idx_user_activity_active (is_active);

-- Add comment to document the table purpose
ALTER TABLE user_activity_sessions COMMENT = 'Tracks user activity sessions for calculating active hours and presence';

-- Note: Foreign key constraints are omitted to avoid hosting environment issues
-- The application will handle referential integrity through application logic
