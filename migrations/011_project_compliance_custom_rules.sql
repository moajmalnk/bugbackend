-- Project-specific custom compliance rules (developer / tester phases)
CREATE TABLE IF NOT EXISTS `project_compliance_custom_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` varchar(36) NOT NULL,
  `phase` enum('developer','tester') NOT NULL,
  `rule_key` varchar(64) NOT NULL,
  `title` varchar(255) NOT NULL,
  `subtitle` varchar(255) DEFAULT NULL,
  `description` text NOT NULL,
  `created_by` varchar(36) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_project_custom_rule` (`project_id`, `phase`, `rule_key`),
  KEY `idx_custom_rules_project_phase` (`project_id`, `phase`),
  CONSTRAINT `fk_custom_rules_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
