-- Finbro payroll write-back: records when CODOFIN OS commits payroll for BugRicer hours.
-- Why: Reconcile paid periods against BugRicer user + date range (M2M acknowledgement ledger).

CREATE TABLE IF NOT EXISTS `finbro_payroll_acknowledgements` (
  `id` VARCHAR(36) NOT NULL,
  `employee_email` VARCHAR(255) NOT NULL,
  `finbro_employee_id` VARCHAR(64) NULL DEFAULT NULL,
  `bugricer_user_id` VARCHAR(36) NULL DEFAULT NULL,
  `pay_date` DATE NOT NULL,
  `hours_from` DATE NOT NULL,
  `hours_to` DATE NOT NULL,
  `hours_worked` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `hourly_rate` DECIMAL(12,2) NULL DEFAULT NULL,
  `gross_amount` DECIMAL(14,2) NULL DEFAULT NULL,
  `net_amount` DECIMAL(14,2) NULL DEFAULT NULL,
  `bugricer_hours_used` DECIMAL(10,2) NULL DEFAULT NULL,
  `manually_edited` TINYINT(1) NOT NULL DEFAULT 0,
  `narration` TEXT NULL DEFAULT NULL,
  `payroll_entry_id` VARCHAR(128) NOT NULL,
  `source` VARCHAR(32) NOT NULL DEFAULT 'finbro',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_finbro_payroll_entry_id` (`payroll_entry_id`),
  KEY `idx_finbro_payroll_user_range` (`bugricer_user_id`, `hours_from`, `hours_to`),
  KEY `idx_finbro_payroll_email_range` (`employee_email`, `hours_from`, `hours_to`),
  KEY `idx_finbro_payroll_pay_date` (`pay_date` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
