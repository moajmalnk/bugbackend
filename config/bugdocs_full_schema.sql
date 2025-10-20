-- ============================================================================
-- BugDocs Full Feature Schema
-- Complete CRUD operations for general documents and bug-integrated reports
-- ============================================================================

-- Table: doc_templates
-- Stores reusable document templates
CREATE TABLE IF NOT EXISTS `doc_templates` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `template_name` VARCHAR(255) NOT NULL COMMENT 'Human-readable template name (e.g., Bug Report, Meeting Notes)',
  `google_doc_id` VARCHAR(255) NOT NULL COMMENT 'Google Document ID of the template file',
  `description` TEXT NULL COMMENT 'Template description',
  `category` VARCHAR(100) DEFAULT 'general' COMMENT 'Template category: bug, general, meeting, etc.',
  `is_active` TINYINT(1) DEFAULT 1 COMMENT 'Whether template is active and available for use',
  `created_by` VARCHAR(255) NULL COMMENT 'User who created the template',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_category` (`category`),
  INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Document templates for BugDocs';

-- Table: user_documents
-- Stores general user-created documents (outside of bug context)
CREATE TABLE IF NOT EXISTS `user_documents` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `doc_title` VARCHAR(500) NOT NULL COMMENT 'Document title',
  `google_doc_id` VARCHAR(255) NOT NULL UNIQUE COMMENT 'Google Document ID',
  `google_doc_url` TEXT NOT NULL COMMENT 'Full Google Docs edit URL',
  `creator_user_id` VARCHAR(255) NOT NULL COMMENT 'BugRicer user ID (UUID)',
  `template_id` INT NULL COMMENT 'Reference to doc_templates if created from template',
  `doc_type` VARCHAR(50) DEFAULT 'general' COMMENT 'Document type: general, meeting, notes, etc.',
  `is_archived` TINYINT(1) DEFAULT 0 COMMENT 'Whether document is archived',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_accessed_at` TIMESTAMP NULL COMMENT 'Last time document was opened',
  INDEX `idx_creator` (`creator_user_id`),
  INDEX `idx_template` (`template_id`),
  INDEX `idx_type` (`doc_type`),
  INDEX `idx_archived` (`is_archived`),
  FOREIGN KEY (`template_id`) REFERENCES `doc_templates`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='User-created general documents';

-- Enhanced bug_documents table (update existing)
-- Add template support to bug documents
ALTER TABLE `bug_documents` 
ADD COLUMN `template_id` INT NULL COMMENT 'Reference to doc_templates if created from template' AFTER `created_by`,
ADD COLUMN `last_accessed_at` TIMESTAMP NULL COMMENT 'Last time document was opened' AFTER `updated_at`,
ADD INDEX `idx_template` (`template_id`);

-- Add foreign key if doc_templates exists
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'doc_templates') > 0,
  'ALTER TABLE `bug_documents` ADD CONSTRAINT `fk_bug_documents_template` FOREIGN KEY (`template_id`) REFERENCES `doc_templates`(`id`) ON DELETE SET NULL',
  'SELECT "doc_templates table does not exist yet, skipping foreign key" as message'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- Insert Default Templates
-- ============================================================================

-- Note: You'll need to create actual Google Docs templates and replace these IDs
-- For now, these are placeholder IDs that you should update after creating templates

INSERT INTO `doc_templates` (`template_name`, `google_doc_id`, `description`, `category`, `is_active`) VALUES
('Bug Report Template', 'TEMPLATE_BUG_REPORT_ID', 'Professional bug investigation and reporting template with placeholders for bug details', 'bug', 1),
('Meeting Notes Template', 'TEMPLATE_MEETING_NOTES_ID', 'Structured meeting notes with agenda, attendees, and action items', 'meeting', 1),
('General Document', 'TEMPLATE_GENERAL_DOC_ID', 'Blank document for general purposes', 'general', 1),
('Technical Specification', 'TEMPLATE_TECH_SPEC_ID', 'Technical specification document template', 'technical', 1)
ON DUPLICATE KEY UPDATE `updated_at` = CURRENT_TIMESTAMP;

-- ============================================================================
-- Sample Data for Testing (Optional)
-- ============================================================================

-- Uncomment to insert sample data for testing
-- INSERT INTO `user_documents` (`doc_title`, `google_doc_id`, `google_doc_url`, `creator_user_id`, `template_id`, `doc_type`) VALUES
-- ('Project Planning Notes', 'SAMPLE_DOC_ID_1', 'https://docs.google.com/document/d/SAMPLE_DOC_ID_1/edit', 'sample-user-uuid', 2, 'meeting'),
-- ('API Documentation', 'SAMPLE_DOC_ID_2', 'https://docs.google.com/document/d/SAMPLE_DOC_ID_2/edit', 'sample-user-uuid', 4, 'technical');

