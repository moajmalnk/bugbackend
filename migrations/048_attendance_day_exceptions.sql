-- =============================================================================
-- BugRicer Migration 048 — Per-day attendance exceptions (admin)
-- =============================================================================
-- allow_wfh: user may check in as WFH even during Office-only week
-- forgive_late: day is not marked late / late strike is cleared
-- Safe to re-run.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `attendance_day_exceptions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` VARCHAR(64) NOT NULL,
  `exception_date` DATE NOT NULL,
  `allow_wfh` TINYINT(1) NOT NULL DEFAULT 0,
  `forgive_late` TINYINT(1) NOT NULL DEFAULT 0,
  `admin_note` VARCHAR(255) NULL DEFAULT NULL,
  `created_by` VARCHAR(64) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_exception_date` (`user_id`, `exception_date`),
  KEY `idx_exception_date` (`exception_date`),
  KEY `idx_user_flags` (`user_id`, `allow_wfh`, `forgive_late`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
