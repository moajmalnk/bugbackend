-- Extended project metadata, client details, timeline, and attachments

ALTER TABLE `projects`
  ADD COLUMN `client_name` VARCHAR(255) DEFAULT NULL AFTER `description`,
  ADD COLUMN `client_location` VARCHAR(255) DEFAULT NULL AFTER `client_name`,
  ADD COLUMN `client_contact_name` VARCHAR(255) DEFAULT NULL AFTER `client_location`,
  ADD COLUMN `client_email` VARCHAR(255) DEFAULT NULL AFTER `client_contact_name`,
  ADD COLUMN `client_phone` VARCHAR(50) DEFAULT NULL AFTER `client_email`,
  ADD COLUMN `client_account_status` ENUM('active','inactive') NOT NULL DEFAULT 'active' AFTER `client_phone`,
  ADD COLUMN `technology_stack` TEXT DEFAULT NULL AFTER `client_account_status`,
  ADD COLUMN `start_date` DATE DEFAULT NULL AFTER `technology_stack`,
  ADD COLUMN `deadline_date` DATE DEFAULT NULL AFTER `start_date`,
  ADD COLUMN `expected_publish_date` DATE DEFAULT NULL AFTER `deadline_date`,
  ADD COLUMN `testing_start_date` DATE DEFAULT NULL AFTER `expected_publish_date`,
  ADD COLUMN `testing_end_date` DATE DEFAULT NULL AFTER `testing_start_date`,
  ADD COLUMN `frontend_finish_date` DATE DEFAULT NULL AFTER `testing_end_date`,
  ADD COLUMN `backend_finish_date` DATE DEFAULT NULL AFTER `frontend_finish_date`;

CREATE TABLE IF NOT EXISTS `project_attachments` (
  `id` varchar(36) NOT NULL,
  `project_id` varchar(36) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_type` varchar(100) DEFAULT NULL,
  `uploaded_by` varchar(36) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_project_attachments_project_id` (`project_id`),
  KEY `idx_project_attachments_uploaded_by` (`uploaded_by`),
  CONSTRAINT `project_attachments_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `project_attachments_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
