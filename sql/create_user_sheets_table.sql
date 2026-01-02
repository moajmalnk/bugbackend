-- ============================================================
-- Create user_sheets table for BugSheets functionality
-- This table mirrors the user_documents table structure
-- ============================================================

-- Create user_sheets table
CREATE TABLE IF NOT EXISTS `user_sheets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sheet_title` varchar(500) NOT NULL COMMENT 'Sheet title',
  `google_sheet_id` varchar(255) NOT NULL COMMENT 'Google Sheet ID',
  `google_sheet_url` text NOT NULL COMMENT 'Full Google Sheets edit URL',
  `creator_user_id` varchar(255) NOT NULL COMMENT 'BugRicer user ID (UUID)',
  `template_id` int(11) DEFAULT NULL COMMENT 'Reference to sheet_templates or doc_templates if created from template',
  `sheet_type` varchar(50) DEFAULT 'general' COMMENT 'Sheet type: general, meeting, notes, etc.',
  `is_archived` tinyint(1) DEFAULT 0 COMMENT 'Whether sheet is archived',
  `project_id` varchar(500) DEFAULT NULL COMMENT 'Reference to projects.id (comma-separated for multiple projects)',
  `role` varchar(100) DEFAULT 'all' COMMENT 'Role access: all, admins, developers, testers (comma-separated for multiple)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_accessed_at` timestamp NULL DEFAULT NULL COMMENT 'Last time sheet was opened',
  PRIMARY KEY (`id`),
  UNIQUE KEY `google_sheet_id` (`google_sheet_id`),
  KEY `idx_creator` (`creator_user_id`),
  KEY `idx_template` (`template_id`),
  KEY `idx_type` (`sheet_type`),
  KEY `idx_archived` (`is_archived`),
  KEY `idx_project` (`project_id`(255)),
  KEY `idx_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='User-created general Google Sheets';

-- ============================================================
-- Optional: Create sheet_templates table (if you want separate templates for sheets)
-- If you prefer to reuse doc_templates, you can skip this section
-- ============================================================

-- Create sheet_templates table (optional - can reuse doc_templates instead)
CREATE TABLE IF NOT EXISTS `sheet_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `template_name` varchar(255) NOT NULL COMMENT 'Template name',
  `google_sheet_id` varchar(255) NOT NULL COMMENT 'Google Sheet ID of the template',
  `category` varchar(100) DEFAULT NULL COMMENT 'Template category',
  `description` text DEFAULT NULL COMMENT 'Template description',
  `is_active` tinyint(1) DEFAULT 1 COMMENT 'Whether template is active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `template_name` (`template_name`),
  KEY `idx_category` (`category`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Google Sheets templates';

-- ============================================================
-- Optional: Create bug_sheets table (if you want to track sheets created for bugs)
-- ============================================================

CREATE TABLE IF NOT EXISTS `bug_sheets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bug_id` varchar(255) NOT NULL COMMENT 'Reference to bugs table (UUID)',
  `google_sheet_id` varchar(255) NOT NULL COMMENT 'Google Sheet ID',
  `google_sheet_url` text NOT NULL COMMENT 'Full URL to the Google Sheet',
  `sheet_name` varchar(500) NOT NULL COMMENT 'Name of the sheet',
  `created_by` varchar(255) NOT NULL COMMENT 'User who created the sheet (UUID)',
  `template_id` int(11) DEFAULT NULL COMMENT 'Reference to sheet_templates or doc_templates if created from template',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_accessed_at` timestamp NULL DEFAULT NULL COMMENT 'Last time sheet was opened',
  PRIMARY KEY (`id`),
  UNIQUE KEY `google_sheet_id` (`google_sheet_id`),
  KEY `idx_bug` (`bug_id`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_template` (`template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Sheets created for bugs';

