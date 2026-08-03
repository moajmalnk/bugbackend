-- Drive / cloud folder links per project category. Safe to re-run.

SET @db := DATABASE();

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'projects' AND COLUMN_NAME = 'category_asset_links'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `projects` ADD COLUMN `category_asset_links` TEXT DEFAULT NULL COMMENT ''JSON map of category → Drive/cloud folder URL'' AFTER `app_publisher_meta`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
