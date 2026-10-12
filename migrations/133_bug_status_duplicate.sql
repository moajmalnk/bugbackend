-- Why: Testers re-file the same bug. Duplicate is a closed status
-- and points at the original bug in the same project.
-- Additive and re-runnable. Existing statuses stay in the enum.

ALTER TABLE `bugs`
  MODIFY COLUMN `status` ENUM(
    'pending',
    'in_progress',
    'fixed',
    'declined',
    'rejected',
    'duplicate'
  ) NOT NULL DEFAULT 'pending';

ALTER TABLE `bugs`
  ADD COLUMN IF NOT EXISTS `duplicate_of` VARCHAR(36) NULL DEFAULT NULL;

CREATE INDEX IF NOT EXISTS `idx_bugs_duplicate_of`
  ON `bugs` (`duplicate_of`);
