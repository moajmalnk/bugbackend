-- =============================================================================
-- BugRicer Migration 123 — Monthly attendance & salary verification
-- =============================================================================
-- Dual-step (employee → admin) weekly + calendar-month hour verification with
-- estimated salary (hours × rate − adjustments). Safe to re-run.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `user_hourly_rates` (
  `id` VARCHAR(36) NOT NULL,
  `user_id` VARCHAR(36) NOT NULL,
  `hourly_rate` DECIMAL(12,2) NOT NULL,
  `effective_from` DATE NOT NULL,
  `updated_by` VARCHAR(36) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_hourly_rates_user_from` (`user_id`, `effective_from`),
  KEY `idx_user_hourly_rates_user_from` (`user_id`, `effective_from` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `attendance_week_verifications` (
  `id` VARCHAR(36) NOT NULL,
  `user_id` VARCHAR(36) NOT NULL,
  `week_start` DATE NOT NULL COMMENT 'Monday',
  `week_end` DATE NOT NULL COMMENT 'Saturday',
  `year_month` CHAR(7) NOT NULL COMMENT 'YYYY-MM primary month for UI grouping',
  `worked_hours` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `leave_hours` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `ot_hours` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `check_in_days` INT NOT NULL DEFAULT 0,
  `leave_days` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  `office_days` INT NOT NULL DEFAULT 0,
  `wfh_days` INT NOT NULL DEFAULT 0,
  `late_days` INT NOT NULL DEFAULT 0,
  `days_worked` INT NOT NULL DEFAULT 0,
  `employee_status` ENUM('pending','verified','correction_needed') NOT NULL DEFAULT 'pending',
  `employee_note` TEXT NULL DEFAULT NULL,
  `employee_verified_at` DATETIME NULL DEFAULT NULL,
  `admin_status` ENUM('pending','approved','correction_requested') NOT NULL DEFAULT 'pending',
  `admin_note` TEXT NULL DEFAULT NULL,
  `admin_verified_at` DATETIME NULL DEFAULT NULL,
  `admin_id` VARCHAR(36) NULL DEFAULT NULL,
  `snapshot_locked` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_att_week_user_week` (`user_id`, `week_start`),
  KEY `idx_att_week_year_month` (`year_month`),
  KEY `idx_att_week_employee_status` (`employee_status`),
  KEY `idx_att_week_admin_status` (`admin_status`),
  KEY `idx_att_week_user_month` (`user_id`, `year_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `attendance_month_verifications` (
  `id` VARCHAR(36) NOT NULL,
  `user_id` VARCHAR(36) NOT NULL,
  `year_month` CHAR(7) NOT NULL COMMENT 'YYYY-MM',
  `period_start` DATE NOT NULL,
  `period_end` DATE NOT NULL,
  `total_hours` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `worked_days` INT NOT NULL DEFAULT 0,
  `leave_days` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  `leave_hours` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `ot_hours` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `check_in_days` INT NOT NULL DEFAULT 0,
  `tasks_completed` INT NOT NULL DEFAULT 0,
  `hourly_rate_used` DECIMAL(12,2) NULL DEFAULT NULL,
  `gross_estimate` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `adjustments_total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `net_estimate` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `include_ot` TINYINT(1) NOT NULL DEFAULT 0,
  `employee_status` ENUM('pending','verified','correction_needed') NOT NULL DEFAULT 'pending',
  `employee_note` TEXT NULL DEFAULT NULL,
  `employee_verified_at` DATETIME NULL DEFAULT NULL,
  `admin_status` ENUM('pending','approved','correction_requested') NOT NULL DEFAULT 'pending',
  `admin_note` TEXT NULL DEFAULT NULL,
  `admin_verified_at` DATETIME NULL DEFAULT NULL,
  `admin_id` VARCHAR(36) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_att_month_user_ym` (`user_id`, `year_month`),
  KEY `idx_att_month_ym` (`year_month`),
  KEY `idx_att_month_employee_status` (`employee_status`),
  KEY `idx_att_month_admin_status` (`admin_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `attendance_month_adjustments` (
  `id` VARCHAR(36) NOT NULL,
  `month_verification_id` VARCHAR(36) NOT NULL,
  `type` ENUM('advance','deduction','credit','other') NOT NULL DEFAULT 'deduction',
  `amount` DECIMAL(14,2) NOT NULL COMMENT 'Signed: negative reduces net, positive increases',
  `reason` VARCHAR(500) NOT NULL DEFAULT '',
  `created_by` VARCHAR(36) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_att_adj_month` (`month_verification_id`),
  KEY `idx_att_adj_created` (`created_at` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
