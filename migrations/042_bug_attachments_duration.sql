-- Persist recorded voice-note length for bug attachments (idempotent)

ALTER TABLE `bug_attachments`
  ADD COLUMN IF NOT EXISTS `duration` INT NULL DEFAULT NULL
    COMMENT 'Duration in seconds for voice notes';
