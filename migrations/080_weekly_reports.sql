-- =============================================================================
-- BugRicer Migration 080 — Saturday weekly reports (checkout gate)
-- =============================================================================
-- One professional weekly report per user per Mon–Sat week. Collected before
-- Saturday checkout; WhatsApp/email go out with checkout (notified_at).
-- Safe to re-run.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `weekly_reports` (
  `id` VARCHAR(36) NOT NULL,
  `user_id` VARCHAR(36) NOT NULL,
  `week_start` DATE NOT NULL COMMENT 'Monday of the work week',
  `week_end` DATE NOT NULL COMMENT 'Saturday of the work week',
  `report_date` DATE NOT NULL COMMENT 'Saturday the report was filed',
  `work_completed` MEDIUMTEXT NOT NULL,
  `work_in_progress` MEDIUMTEXT NOT NULL,
  `issues_blockers` MEDIUMTEXT NULL DEFAULT NULL,
  `plan_next_week` MEDIUMTEXT NOT NULL,
  `notified_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_weekly_reports_user_week` (`user_id`, `week_start`),
  KEY `idx_weekly_reports_user_id` (`user_id`),
  KEY `idx_weekly_reports_week_start` (`week_start`),
  KEY `idx_weekly_reports_report_date` (`report_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
