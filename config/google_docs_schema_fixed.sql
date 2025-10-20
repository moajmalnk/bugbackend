-- Google Docs Integration Schema for BugRicer (Fixed Version)
-- This version uses VARCHAR for user IDs to support UUID format

-- Drop existing tables if they exist (to recreate with correct schema)
DROP TABLE IF EXISTS `bug_documents`;
DROP TABLE IF EXISTS `google_tokens`;

-- Table to store Google OAuth tokens for each user
CREATE TABLE `google_tokens` (
  `google_user_id` VARCHAR(255) NOT NULL PRIMARY KEY COMMENT 'Google User ID (from OAuth)',
  `bugricer_user_id` VARCHAR(255) NOT NULL COMMENT 'BugRicer user ID reference (UUID)',
  `refresh_token` TEXT NOT NULL COMMENT 'Google OAuth Refresh Token (long-lived)',
  `access_token_expiry` TIMESTAMP NULL DEFAULT NULL COMMENT 'When the current access token expires',
  `email` VARCHAR(255) DEFAULT NULL COMMENT 'Google account email',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_bugricer_user` (`bugricer_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table to link bugs with their Google Documents
CREATE TABLE `bug_documents` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `bug_id` VARCHAR(255) NOT NULL COMMENT 'Reference to bugs table (UUID)',
  `google_doc_id` VARCHAR(255) NOT NULL COMMENT 'Google Document ID',
  `google_doc_url` TEXT NOT NULL COMMENT 'Full URL to the Google Document',
  `document_name` VARCHAR(500) NOT NULL COMMENT 'Name of the document',
  `created_by` VARCHAR(255) NOT NULL COMMENT 'User who created the document (UUID)',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_bug_id` (`bug_id`),
  INDEX `idx_created_by` (`created_by`),
  UNIQUE KEY `unique_bug_doc` (`bug_id`, `google_doc_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Note: Foreign key constraints are intentionally omitted to avoid dependency issues
-- The application logic will handle referential integrity
