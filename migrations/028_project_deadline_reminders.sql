-- Track sent project timeline / deadline reminders (idempotent per day + milestone + offset)

CREATE TABLE IF NOT EXISTS `project_deadline_reminders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` VARCHAR(36) NOT NULL,
  `milestone_key` VARCHAR(64) NOT NULL,
  `reminder_offset` INT NOT NULL COMMENT 'Days relative to milestone: 7/3/1 before, 0 due today, -1 overdue',
  `milestone_date` DATE NOT NULL,
  `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_project_deadline_reminder` (`project_id`, `milestone_key`, `reminder_offset`, `milestone_date`),
  KEY `idx_deadline_reminders_project` (`project_id`),
  KEY `idx_deadline_reminders_sent` (`sent_at`),
  CONSTRAINT `fk_deadline_reminders_project`
    FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
