-- =============================================================================
-- BugRicer Migration 107 — assets_emails.signed_in_from (safe to re-run)
-- Chrome profile / workstation label for who signed into the mailbox.
-- =============================================================================

SET @db := DATABASE();

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'assets_emails'
    AND COLUMN_NAME = 'signed_in_from'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `assets_emails` ADD COLUMN `signed_in_from` VARCHAR(150) NULL DEFAULT NULL AFTER `assigned_contact`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'assets_emails'
    AND INDEX_NAME = 'idx_emails_signed_in_from'
);
SET @sql := IF(@idx = 0,
  'ALTER TABLE `assets_emails` ADD KEY `idx_emails_signed_in_from` (`signed_in_from`)',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx2 := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'assets_emails'
    AND INDEX_NAME = 'idx_emails_status_assigned'
);
SET @sql := IF(@idx2 = 0,
  'ALTER TABLE `assets_emails` ADD KEY `idx_emails_status_assigned` (`status`, `assigned_user_id`)',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
