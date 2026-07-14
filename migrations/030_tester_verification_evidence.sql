-- Tester verification notes + optional attachment context (idempotent)

ALTER TABLE `bugs`
  ADD COLUMN IF NOT EXISTS `tester_verification_notes` TEXT NULL
    COMMENT 'Notes from admin/tester during post-fix verification';

ALTER TABLE `bug_attachments`
  ADD COLUMN IF NOT EXISTS `upload_context` VARCHAR(32) NULL DEFAULT NULL
    COMMENT 'report|verification — source of upload';

CREATE INDEX IF NOT EXISTS `idx_bug_attachments_context`
  ON `bug_attachments` (`upload_context`);
