-- Why: BugCreative needs a shared folder tree so creators/admins can organize
-- assets (browse, create folders, copy/move) without losing ownership rules.

CREATE TABLE IF NOT EXISTS `creative_folders` (
  `id` VARCHAR(36) NOT NULL,
  `parent_id` VARCHAR(36) NULL,
  `name` VARCHAR(100) NOT NULL,
  `created_by` VARCHAR(36) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_creative_folders_parent` (`parent_id`),
  KEY `idx_creative_folders_created_by` (`created_by`),
  KEY `idx_creative_folders_created` (`created_at`),
  KEY `idx_creative_folders_name` (`name`),
  CONSTRAINT `fk_creative_folders_parent`
    FOREIGN KEY (`parent_id`) REFERENCES `creative_folders`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET @exist := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'creative_assets'
    AND COLUMN_NAME = 'folder_id'
);
SET @sql := IF(
  @exist = 0,
  'ALTER TABLE `creative_assets` ADD COLUMN `folder_id` VARCHAR(36) NULL DEFAULT NULL AFTER `project_id`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'creative_assets'
    AND INDEX_NAME = 'idx_creative_assets_folder_id'
);
SET @sql := IF(
  @exist = 0,
  'ALTER TABLE `creative_assets` ADD KEY `idx_creative_assets_folder_id` (`folder_id`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'creative_assets'
    AND CONSTRAINT_NAME = 'fk_creative_assets_folder'
);
SET @sql := IF(
  @exist = 0,
  'ALTER TABLE `creative_assets` ADD CONSTRAINT `fk_creative_assets_folder` FOREIGN KEY (`folder_id`) REFERENCES `creative_folders`(`id`) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
