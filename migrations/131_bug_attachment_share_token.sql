-- Stable public preview tokens for bug images, videos, and files.
-- Additive and re-runnable. NULL stays allowed so existing rows are unchanged.

ALTER TABLE `bug_attachments`
  ADD COLUMN IF NOT EXISTS `share_token` VARCHAR(64) NULL DEFAULT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS `idx_bug_attachments_share_token`
  ON `bug_attachments` (`share_token`);
