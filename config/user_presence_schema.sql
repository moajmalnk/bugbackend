-- User Presence Heartbeat System Schema
-- Adds last_active_at column to track user activity for presence status

-- Add last_active_at column to users table
ALTER TABLE users ADD COLUMN last_active_at DATETIME DEFAULT NULL;

-- Add index for optimized status queries
CREATE INDEX idx_users_last_active_at ON users(last_active_at);

-- Optional: Add comment to document the column purpose
ALTER TABLE users MODIFY COLUMN last_active_at DATETIME DEFAULT NULL COMMENT 'Timestamp of last heartbeat for presence tracking';
