-- Migration: Add new fields to updates table and create update_attachments table
-- Date: 2024

-- Add expected_date and expected_time to updates table
ALTER TABLE `updates` 
ADD COLUMN `expected_date` DATE NULL DEFAULT NULL AFTER `status`,
ADD COLUMN `expected_time` TIME NULL DEFAULT NULL AFTER `expected_date`;

-- Create update_attachments table (similar to bug_attachments)
CREATE TABLE IF NOT EXISTS `update_attachments` (
  `id` varchar(36) NOT NULL,
  `update_id` varchar(36) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_type` enum('screenshot','attachment','voice_note') NOT NULL DEFAULT 'attachment',
  `file_size` bigint(20) DEFAULT NULL,
  `duration` int(11) DEFAULT NULL COMMENT 'Duration in seconds for voice notes',
  `uploaded_by` varchar(36) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_update_id` (`update_id`),
  KEY `idx_file_type` (`file_type`),
  KEY `idx_uploaded_by` (`uploaded_by`),
  CONSTRAINT `update_attachments_ibfk_1` FOREIGN KEY (`update_id`) REFERENCES `updates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `update_attachments_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

