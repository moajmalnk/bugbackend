-- CODO Master Rules Compliance + release_ready project status
ALTER TABLE `projects`
  MODIFY `status` ENUM('active','completed','archived','release_ready') NOT NULL DEFAULT 'active';

CREATE TABLE IF NOT EXISTS `project_compliance` (
  `project_id` varchar(36) NOT NULL,
  `pipeline_stage` enum('developer_unverified','developer_complete','qa_inspection','qa_complete','admin_ready') NOT NULL DEFAULT 'developer_unverified',
  `developer_completed_at` datetime DEFAULT NULL,
  `developer_completed_by` varchar(36) DEFAULT NULL,
  `tester_completed_at` datetime DEFAULT NULL,
  `tester_completed_by` varchar(36) DEFAULT NULL,
  `emergency_bypass` tinyint(1) NOT NULL DEFAULT 0,
  `emergency_bypass_by` varchar(36) DEFAULT NULL,
  `emergency_bypass_at` datetime DEFAULT NULL,
  `emergency_bypass_reason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`project_id`),
  CONSTRAINT `fk_project_compliance_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `project_compliance_checks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` varchar(36) NOT NULL,
  `phase` enum('developer','tester') NOT NULL,
  `rule_key` varchar(50) NOT NULL,
  `verified` tinyint(1) NOT NULL DEFAULT 0,
  `verified_by` varchar(36) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_project_phase_rule` (`project_id`,`phase`,`rule_key`),
  KEY `idx_project_compliance_checks_project` (`project_id`),
  CONSTRAINT `fk_project_compliance_checks_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
