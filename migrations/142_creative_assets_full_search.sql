-- BugCreative full-text search: metadata + OCR / PDF extracted body text.
-- search_text holds indexed words from title/hook/material/platform/filenames/folders
-- plus Gemini-extracted text from uploaded images, thumbnails, and PDFs.
-- Safe to re-run.

SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'creative_assets'
    AND COLUMN_NAME = 'search_text'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `creative_assets` ADD COLUMN `search_text` MEDIUMTEXT NULL AFTER `admin_feedback`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'creative_assets'
    AND COLUMN_NAME = 'search_indexed_at'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `creative_assets` ADD COLUMN `search_indexed_at` TIMESTAMP NULL DEFAULT NULL AFTER `search_text`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'creative_assets'
    AND COLUMN_NAME = 'search_index_hash'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `creative_assets` ADD COLUMN `search_index_hash` VARCHAR(64) NULL DEFAULT NULL AFTER `search_indexed_at`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- FULLTEXT for MATCH … AGAINST (title + hook + extracted body). Falls back to LIKE in PHP if unavailable.
SET @idx_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'creative_assets'
    AND INDEX_NAME = 'ft_creative_assets_search'
);
SET @sql := IF(
  @idx_exists = 0,
  'ALTER TABLE `creative_assets` ADD FULLTEXT INDEX `ft_creative_assets_search` (`title`, `hook_content`, `search_text`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
