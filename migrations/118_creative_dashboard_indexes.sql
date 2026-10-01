-- Why: Creator dashboard summary filters published work by published_date and lists
-- recent activity by updated_at. Additive and re-runnable.

SET @exist := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'creative_assets'
    AND INDEX_NAME = 'idx_creative_status_published'
);
SET @sql := IF(
  @exist = 0,
  'ALTER TABLE `creative_assets` ADD KEY `idx_creative_status_published` (`status`, `published_date`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'creative_assets'
    AND INDEX_NAME = 'idx_creative_updated'
);
SET @sql := IF(
  @exist = 0,
  'ALTER TABLE `creative_assets` ADD KEY `idx_creative_updated` (`updated_at`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
