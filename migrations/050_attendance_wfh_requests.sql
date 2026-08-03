-- =============================================================================
-- BugRicer Migration 050 — Same-day WFH requests (pending → approve/reject)
-- =============================================================================
-- Employee requests WFH for a date; admin approves (writes allow_wfh exception)
-- or rejects. Safe to re-run.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `attendance_wfh_requests` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` VARCHAR(64) NOT NULL,
  `request_date` DATE NOT NULL,
  `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `user_note` VARCHAR(255) NULL DEFAULT NULL,
  `admin_note` VARCHAR(255) NULL DEFAULT NULL,
  `reviewed_by` VARCHAR(64) NULL DEFAULT NULL,
  `reviewed_at` DATETIME NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_request_date` (`user_id`, `request_date`),
  KEY `idx_status_date` (`status`, `request_date`),
  KEY `idx_request_date` (`request_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
