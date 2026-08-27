-- Creator system role + BugCreative asset pipeline (safe to re-run).

ALTER TABLE `users`
  MODIFY COLUMN `role` ENUM('admin','developer','tester','user','creator') NOT NULL DEFAULT 'user';

ALTER TABLE `project_members`
  MODIFY COLUMN `role` ENUM('manager','developer','tester','creator') NOT NULL;

INSERT INTO `roles` (`role_name`, `description`, `is_system_role`)
SELECT 'Creator', 'Design and creative asset production', 1
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `roles` WHERE LOWER(`role_name`) = 'creator'
);

CREATE TABLE IF NOT EXISTS `creative_assets` (
  `id` VARCHAR(36) NOT NULL,
  `project_id` VARCHAR(36) NULL,
  `creator_id` VARCHAR(36) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `material_type` ENUM(
    'Poster',
    'Reel',
    'Carousel',
    'Mockup Web',
    'Mockup App',
    'Tips',
    'Document',
    'Logo',
    'Brochure',
    'Other'
  ) NOT NULL,
  `platform` ENUM('Insta','Web','YouTube','LinkedIn','Other') NOT NULL DEFAULT 'Insta',
  `hook_content` TEXT NULL,
  `asset_source` ENUM('link','upload') NOT NULL DEFAULT 'link',
  `drive_link` TEXT NULL,
  `uploaded_file_path` TEXT NULL,
  `preview_thumbnail_url` TEXT NULL,
  `status` ENUM('Draft','In Review','Completed','Published','Rejected') NOT NULL DEFAULT 'Draft',
  `admin_feedback` TEXT NULL,
  `scheduled_date` DATE NULL,
  `published_date` DATE NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_creative_creator` (`creator_id`),
  KEY `idx_creative_project` (`project_id`),
  KEY `idx_creative_status` (`status`),
  KEY `idx_creative_material` (`material_type`),
  KEY `idx_creative_created` (`created_at`),
  KEY `idx_creative_scheduled` (`scheduled_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `creative_reviews` (
  `id` VARCHAR(36) NOT NULL,
  `asset_id` VARCHAR(36) NOT NULL,
  `reviewer_id` VARCHAR(36) NOT NULL,
  `status` ENUM('Approved','Changes Requested','Rejected') NOT NULL,
  `comments` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_creative_rev_asset` (`asset_id`),
  KEY `idx_creative_rev_created` (`created_at`),
  CONSTRAINT `fk_creative_rev_asset`
    FOREIGN KEY (`asset_id`) REFERENCES `creative_assets`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'CREATIVE_VIEW', 'View BugCreative', 'Creative', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (
  SELECT 1 FROM `permissions` WHERE `permission_key` = 'CREATIVE_VIEW'
);

INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'CREATIVE_CREATE', 'Create BugCreative Assets', 'Creative', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (
  SELECT 1 FROM `permissions` WHERE `permission_key` = 'CREATIVE_CREATE'
);

INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'CREATIVE_REVIEW', 'Review BugCreative Assets', 'Creative', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (
  SELECT 1 FROM `permissions` WHERE `permission_key` = 'CREATIVE_REVIEW'
);

INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'CREATIVE_MANAGE', 'Manage BugCreative Assets', 'Creative', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (
  SELECT 1 FROM `permissions` WHERE `permission_key` = 'CREATIVE_MANAGE'
);

INSERT INTO `role_permissions` (`role_id`, `permission_id`, `created_at`)
SELECT 1, p.id, NOW()
FROM `permissions` p
WHERE p.permission_key IN (
  'CREATIVE_VIEW', 'CREATIVE_CREATE', 'CREATIVE_REVIEW', 'CREATIVE_MANAGE'
)
AND NOT EXISTS (
  SELECT 1 FROM `role_permissions` rp
  WHERE rp.role_id = 1 AND rp.permission_id = p.id
);

INSERT INTO `role_permissions` (`role_id`, `permission_id`, `created_at`)
SELECT r.id, p.id, NOW()
FROM `roles` r
CROSS JOIN `permissions` p
WHERE LOWER(r.role_name) = 'creator'
AND p.permission_key IN (
  'CREATIVE_VIEW',
  'CREATIVE_CREATE',
  'PROJECTS_VIEW_ASSIGNED',
  'DOCS_VIEW',
  'SHEETS_VIEW',
  'MEETINGS_JOIN',
  'TASKS_VIEW_ASSIGNED',
  'TASKS_CREATE',
  'DAILY_UPDATE_VIEW',
  'DAILY_UPDATE_CREATE',
  'LEAVE_VIEW',
  'MESSAGING_VIEW',
  'CODO_VIEW'
)
AND NOT EXISTS (
  SELECT 1 FROM `role_permissions` rp
  WHERE rp.role_id = r.id AND rp.permission_id = p.id
);
