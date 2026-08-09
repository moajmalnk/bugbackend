-- Why: Sheets/Docs can grant access to specific users in addition to role-based audience.
-- Store comma-separated user UUIDs (same pattern as multi project_id). Safe to re-run.

SET @db := DATABASE();

SET @exist_sheets := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_sheets' AND COLUMN_NAME = 'allowed_user_ids'
);
SET @sql_sheets := IF(@exist_sheets = 0,
  'ALTER TABLE `user_sheets` ADD COLUMN `allowed_user_ids` TEXT NULL DEFAULT NULL COMMENT ''Comma-separated user UUIDs with explicit access (OR with role)'' AFTER `role`',
  'SELECT 1');
PREPARE s FROM @sql_sheets; EXECUTE s; DEALLOCATE PREPARE s;

SET @exist_docs := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_documents' AND COLUMN_NAME = 'allowed_user_ids'
);
SET @sql_docs := IF(@exist_docs = 0,
  'ALTER TABLE `user_documents` ADD COLUMN `allowed_user_ids` TEXT NULL DEFAULT NULL COMMENT ''Comma-separated user UUIDs with explicit access (OR with role)'' AFTER `role`',
  'SELECT 1');
PREPARE s FROM @sql_docs; EXECUTE s; DEALLOCATE PREPARE s;
