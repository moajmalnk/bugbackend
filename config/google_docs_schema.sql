-- Google Docs Integration Schema for BugRicer
-- This schema supports Google OAuth tokens and bug document linking

-- First, let's check if the required tables exist and create them if needed
-- This is a safer approach that handles missing parent tables

-- Table to store Google OAuth tokens for each user
CREATE TABLE IF NOT EXISTS `google_tokens` (
  `google_user_id` VARCHAR(255) NOT NULL PRIMARY KEY COMMENT 'Google User ID (from OAuth)',
  `bugricer_user_id` INT NOT NULL COMMENT 'BugRicer user ID reference',
  `refresh_token` TEXT NOT NULL COMMENT 'Google OAuth Refresh Token (long-lived)',
  `access_token_expiry` TIMESTAMP NULL DEFAULT NULL COMMENT 'When the current access token expires',
  `email` VARCHAR(255) DEFAULT NULL COMMENT 'Google account email',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_bugricer_user` (`bugricer_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table to link bugs with their Google Documents
CREATE TABLE IF NOT EXISTS `bug_documents` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `bug_id` INT NOT NULL COMMENT 'Reference to bugs table',
  `google_doc_id` VARCHAR(255) NOT NULL COMMENT 'Google Document ID',
  `google_doc_url` TEXT NOT NULL COMMENT 'Full URL to the Google Document',
  `document_name` VARCHAR(500) NOT NULL COMMENT 'Name of the document',
  `created_by` INT NOT NULL COMMENT 'User who created the document',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_bug_id` (`bug_id`),
  INDEX `idx_created_by` (`created_by`),
  UNIQUE KEY `unique_bug_doc` (`bug_id`, `google_doc_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add foreign key constraints only if the parent tables exist
-- This prevents errors if the users or bugs tables don't exist yet

-- Add foreign key for google_tokens -> users (if users table exists)
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users') > 0,
  'ALTER TABLE `google_tokens` ADD CONSTRAINT `fk_google_tokens_user` FOREIGN KEY (`bugricer_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE',
  'SELECT "users table does not exist, skipping foreign key constraint" as message'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add foreign key for bug_documents -> bugs (if bugs table exists)
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bugs') > 0,
  'ALTER TABLE `bug_documents` ADD CONSTRAINT `fk_bug_documents_bug` FOREIGN KEY (`bug_id`) REFERENCES `bugs`(`id`) ON DELETE CASCADE',
  'SELECT "bugs table does not exist, skipping foreign key constraint" as message'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add foreign key for bug_documents -> users (if users table exists)
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users') > 0,
  'ALTER TABLE `bug_documents` ADD CONSTRAINT `fk_bug_documents_user` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE CASCADE',
  'SELECT "users table does not exist, skipping foreign key constraint" as message'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

