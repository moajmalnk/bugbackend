-- =============================================================================
-- BugRicer Migration 069 — Birthday wishes (one per sender/celebrant/day)
-- =============================================================================
-- Safe to re-run.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `birthday_wishes` (
  `id` CHAR(36) NOT NULL,
  `from_user_id` VARCHAR(64) NOT NULL,
  `to_user_id` VARCHAR(64) NOT NULL,
  `wish_date` DATE NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_birthday_wish_day` (`from_user_id`, `to_user_id`, `wish_date`),
  KEY `idx_birthday_wishes_to_date` (`to_user_id`, `wish_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
