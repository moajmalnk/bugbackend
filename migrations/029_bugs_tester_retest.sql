-- Tester / admin verification after a developer marks a bug fixed

ALTER TABLE `bugs`
  ADD COLUMN `tester_retested` TINYINT(1) NULL DEFAULT NULL
    COMMENT 'NULL=pending, 0=not retested, 1=retested again'
    AFTER `bug_level`,
  ADD COLUMN `tester_issue_fixed` TINYINT(1) NULL DEFAULT NULL
    COMMENT 'NULL when not applicable; 0=still broken, 1=confirmed fixed (only when retested=1)'
    AFTER `tester_retested`,
  ADD COLUMN `tester_verified_by` VARCHAR(36) NULL DEFAULT NULL
    AFTER `tester_issue_fixed`,
  ADD COLUMN `tester_verified_at` DATETIME NULL DEFAULT NULL
    AFTER `tester_verified_by`;

ALTER TABLE `bugs`
  ADD KEY `idx_bugs_tester_retested` (`tester_retested`),
  ADD KEY `idx_bugs_tester_verified_by` (`tester_verified_by`);
