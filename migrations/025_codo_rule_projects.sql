-- Link Common CODO project-phase rules to one or more projects.
CREATE TABLE IF NOT EXISTS `codo_rule_projects` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rule_id` INT UNSIGNED NOT NULL,
  `project_id` VARCHAR(36) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_codo_rule_project` (`rule_id`, `project_id`),
  KEY `idx_codo_rule_projects_rule` (`rule_id`),
  KEY `idx_codo_rule_projects_project` (`project_id`),
  CONSTRAINT `fk_codo_rule_projects_rule`
    FOREIGN KEY (`rule_id`) REFERENCES `codo_common_rules` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
