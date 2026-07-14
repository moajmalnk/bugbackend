-- Tester / admin verification after a developer marks a bug fixed
-- Idempotent for Hostinger/MariaDB (safe to re-run).
-- If you see #1060 Duplicate column — that column is already there; skip it.

ALTER TABLE `bugs`
  ADD COLUMN IF NOT EXISTS `tester_retested` TINYINT(1) NULL DEFAULT NULL
    COMMENT 'NULL=pending, 0=not retested, 1=retested again';

ALTER TABLE `bugs`
  ADD COLUMN IF NOT EXISTS `tester_issue_fixed` TINYINT(1) NULL DEFAULT NULL
    COMMENT 'NULL when N/A or pending, 0=still broken, 1=confirmed fixed';

ALTER TABLE `bugs`
  ADD COLUMN IF NOT EXISTS `tester_verified_by` VARCHAR(36) NULL DEFAULT NULL;

ALTER TABLE `bugs`
  ADD COLUMN IF NOT EXISTS `tester_verified_at` DATETIME NULL DEFAULT NULL;

-- Indexes (ignore "Duplicate key name" if already created)
CREATE INDEX IF NOT EXISTS `idx_bugs_tester_retested` ON `bugs` (`tester_retested`);
CREATE INDEX IF NOT EXISTS `idx_bugs_tester_verified_by` ON `bugs` (`tester_verified_by`);
