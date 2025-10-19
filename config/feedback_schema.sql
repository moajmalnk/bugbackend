-- Feedback System Schema for BugRicer
-- Tables: user_feedback, feedback_ratings

CREATE TABLE IF NOT EXISTS user_feedback (
    id VARCHAR(36) NOT NULL PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    rating TINYINT(1) NOT NULL COMMENT 'Rating from 1-5 (1=angry, 2=sad, 3=neutral, 4=happy, 5=star-struck)',
    feedback_text TEXT NULL COMMENT 'Optional text feedback',
    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    -- Indexes for performance
    INDEX idx_user_feedback_user_id (user_id),
    INDEX idx_user_feedback_submitted_at (submitted_at),
    INDEX idx_user_feedback_rating (rating),
    
    -- Foreign key constraint
    CONSTRAINT fk_user_feedback_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table to track if user has submitted feedback (one-time submission tracking)
CREATE TABLE IF NOT EXISTS user_feedback_tracking (
    user_id VARCHAR(36) NOT NULL PRIMARY KEY,
    has_submitted_feedback BOOLEAN NOT NULL DEFAULT FALSE,
    first_submission_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Foreign key constraint
    CONSTRAINT fk_user_feedback_tracking_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert initial tracking records for existing users
INSERT IGNORE INTO user_feedback_tracking (user_id, has_submitted_feedback, first_submission_at)
SELECT id, FALSE, NULL FROM users;

-- Add trigger to automatically create tracking record for new users
DELIMITER $$
CREATE TRIGGER tr_create_feedback_tracking_after_user_insert
AFTER INSERT ON users
FOR EACH ROW
BEGIN
    INSERT INTO user_feedback_tracking (user_id, has_submitted_feedback, first_submission_at)
    VALUES (NEW.id, FALSE, NULL);
END$$
DELIMITER ;
