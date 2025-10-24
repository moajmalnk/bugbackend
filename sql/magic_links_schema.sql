-- Magic Links Table for Passwordless Authentication
-- This table stores magic link tokens for passwordless email authentication

-- First, create the table without foreign key constraint
CREATE TABLE IF NOT EXISTS magic_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token (token),
    INDEX idx_user_id (user_id),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add index for cleanup of expired tokens
CREATE INDEX IF NOT EXISTS idx_cleanup ON magic_links (expires_at, used_at);

-- Add comment to table
ALTER TABLE magic_links COMMENT = 'Stores magic link tokens for passwordless email authentication';

-- Add foreign key constraint only if users table exists and has the correct structure
-- This will be handled by the application logic instead of database constraints
-- to avoid production deployment issues
