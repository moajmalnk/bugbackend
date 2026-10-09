-- =============================================================================
-- BugRicer Migration 130 — Leave request activity trail
-- =============================================================================
-- Append-only log of who requested / approved / rejected / cancelled /
-- granted / updated each leave and when. leave_requests.reviewed_* only holds
-- the latest reviewer and is overwritten by later admin edits.
-- Also auto-created by backend/utils/leave_activity.php. Safe to re-run.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `leave_request_events` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `leave_request_id` INT UNSIGNED NOT NULL,
  `event` VARCHAR(24) NOT NULL,
  `actor_id` VARCHAR(36) NULL,
  `impersonated_by` VARCHAR(36) NULL,
  `note` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_leave_events_request` (`leave_request_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
