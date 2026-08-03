-- Project categories (WEB,PWA,APP,SEO,CREATIVE), APP publisher meta,
-- and attachment category/folder tagging. Safe to re-run.

SET @db := DATABASE();

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'projects' AND COLUMN_NAME = 'project_categories'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `projects` ADD COLUMN `project_categories` VARCHAR(100) DEFAULT NULL COMMENT ''Comma-separated: WEB,PWA,APP,SEO,CREATIVE'' AFTER `platforms`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'projects' AND COLUMN_NAME = 'app_publisher_meta'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `projects` ADD COLUMN `app_publisher_meta` TEXT DEFAULT NULL COMMENT ''JSON Play Store / publisher fields for APP'' AFTER `project_categories`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'project_attachments' AND COLUMN_NAME = 'category'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `project_attachments` ADD COLUMN `category` VARCHAR(32) DEFAULT NULL COMMENT ''WEB|PWA|APP|SEO|CREATIVE|GENERAL'' AFTER `file_type`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'project_attachments' AND COLUMN_NAME = 'folder'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `project_attachments` ADD COLUMN `folder` VARCHAR(100) DEFAULT NULL COMMENT ''Logical folder e.g. app_files, key_store, builds'' AFTER `category`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'project_attachments' AND INDEX_NAME = 'idx_project_attachments_category'
);
SET @sql := IF(@idx = 0,
  'CREATE INDEX `idx_project_attachments_category` ON `project_attachments` (`project_id`, `category`)',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
