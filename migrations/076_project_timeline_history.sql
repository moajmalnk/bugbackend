-- Audit trail for project timeline date/time changes (who, from, to, when).

CREATE TABLE IF NOT EXISTS `project_timeline_history` (
  `id` varchar(36) NOT NULL,
  `project_id` varchar(36) NOT NULL,
  `field_key` varchar(64) NOT NULL,
  `old_value` datetime DEFAULT NULL,
  `new_value` datetime DEFAULT NULL,
  `changed_by` varchar(36) NOT NULL,
  `changed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pth_project_changed` (`project_id`, `changed_at`),
  KEY `idx_pth_project_field` (`project_id`, `field_key`, `changed_at`),
  KEY `idx_pth_changed_by` (`changed_by`),
  CONSTRAINT `project_timeline_history_project`
    FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `project_timeline_history_user`
    FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
