-- BugRicer Shorts: vertical video links + uploads (admin v1)
CREATE TABLE IF NOT EXISTS `shorts` (
  `id` VARCHAR(36) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `category` ENUM('ui_ux', 'bug', 'project', 'stack', 'other') NOT NULL DEFAULT 'other',
  `source_type` ENUM('youtube', 'instagram', 'facebook', 'upload') NOT NULL,
  `source_url` TEXT NULL,
  `video_path` VARCHAR(512) NULL,
  `thumbnail_path` VARCHAR(512) NULL,
  `project_id` VARCHAR(36) NULL,
  `created_by` VARCHAR(36) NOT NULL,
  `is_published` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_shorts_published_sort` (`is_published`, `sort_order`, `created_at`),
  KEY `idx_shorts_category` (`category`),
  KEY `idx_shorts_created_by` (`created_by`),
  KEY `idx_shorts_project` (`project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
