-- =============================================================================
-- BugRicer Migration 081 — Admin Recycle Bin (soft-delete registry)
-- =============================================================================
-- Creates recycle_bin_items registry and deleted_at/deleted_by on deletable tables.
-- Permissions: RECYCLE_BIN_VIEW, RECYCLE_BIN_MANAGE (granted to Admin role_id = 1)
-- Safe to re-run (guards with IF NOT EXISTS / information_schema checks).
-- =============================================================================

SET @db := DATABASE();

-- ----------------------------------------------------------------
-- 1. Central recycle bin registry
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `recycle_bin_items` (
  `id`          VARCHAR(36)  NOT NULL,
  `entity_type` VARCHAR(32)  NOT NULL,
  `entity_id`   VARCHAR(64)  NOT NULL,
  `title`       VARCHAR(255) NOT NULL,
  `subtitle`    VARCHAR(255) NULL DEFAULT NULL,
  `project_id`  VARCHAR(36)  NULL DEFAULT NULL,
  `metadata`    JSON         NULL DEFAULT NULL,
  `deleted_by`  VARCHAR(36)  NOT NULL,
  `deleted_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `restored_at` DATETIME     NULL DEFAULT NULL,
  `purged_at`   DATETIME     NULL DEFAULT NULL,
  `expires_at`  DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_rb_deleted_at` (`deleted_at` DESC),
  KEY `idx_rb_entity_type` (`entity_type`, `deleted_at` DESC),
  KEY `idx_rb_active` (`purged_at`, `restored_at`, `deleted_at`),
  KEY `idx_rb_entity_lookup` (`entity_type`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Unified admin recycle bin index for soft-deleted entities';

-- ----------------------------------------------------------------
-- 2. Helper: add deleted_at + deleted_by + index to a table
--    Usage: SET @tbl = 'bugs'; then run blocks below (repeated per table)
-- ----------------------------------------------------------------

-- bugs
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'bugs' AND COLUMN_NAME = 'deleted_at');
SET @sql := IF(@exist = 0, 'ALTER TABLE `bugs` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'bugs' AND COLUMN_NAME = 'deleted_by');
SET @sql := IF(@exist = 0, 'ALTER TABLE `bugs` ADD COLUMN `deleted_by` VARCHAR(36) NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'bugs' AND INDEX_NAME = 'idx_bugs_deleted_at');
SET @sql := IF(@idx = 0, 'CREATE INDEX `idx_bugs_deleted_at` ON `bugs` (`deleted_at`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- projects
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'projects' AND COLUMN_NAME = 'deleted_at');
SET @sql := IF(@exist = 0, 'ALTER TABLE `projects` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'projects' AND COLUMN_NAME = 'deleted_by');
SET @sql := IF(@exist = 0, 'ALTER TABLE `projects` ADD COLUMN `deleted_by` VARCHAR(36) NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'projects' AND INDEX_NAME = 'idx_projects_deleted_at');
SET @sql := IF(@idx = 0, 'CREATE INDEX `idx_projects_deleted_at` ON `projects` (`deleted_at`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- updates
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'updates' AND COLUMN_NAME = 'deleted_at');
SET @sql := IF(@exist = 0, 'ALTER TABLE `updates` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'updates' AND COLUMN_NAME = 'deleted_by');
SET @sql := IF(@exist = 0, 'ALTER TABLE `updates` ADD COLUMN `deleted_by` VARCHAR(36) NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'updates' AND INDEX_NAME = 'idx_updates_deleted_at');
SET @sql := IF(@idx = 0, 'CREATE INDEX `idx_updates_deleted_at` ON `updates` (`deleted_at`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- users
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'deleted_at');
SET @sql := IF(@exist = 0, 'ALTER TABLE `users` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'deleted_by');
SET @sql := IF(@exist = 0, 'ALTER TABLE `users` ADD COLUMN `deleted_by` VARCHAR(36) NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_deleted_at');
SET @sql := IF(@idx = 0, 'CREATE INDEX `idx_users_deleted_at` ON `users` (`deleted_at`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- clients
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clients' AND COLUMN_NAME = 'deleted_at');
SET @sql := IF(@exist = 0, 'ALTER TABLE `clients` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clients' AND COLUMN_NAME = 'deleted_by');
SET @sql := IF(@exist = 0, 'ALTER TABLE `clients` ADD COLUMN `deleted_by` VARCHAR(36) NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clients' AND INDEX_NAME = 'idx_clients_deleted_at');
SET @sql := IF(@idx = 0, 'CREATE INDEX `idx_clients_deleted_at` ON `clients` (`deleted_at`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- weekly_reports
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'weekly_reports' AND COLUMN_NAME = 'deleted_at');
SET @sql := IF(@exist = 0, 'ALTER TABLE `weekly_reports` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'weekly_reports' AND COLUMN_NAME = 'deleted_by');
SET @sql := IF(@exist = 0, 'ALTER TABLE `weekly_reports` ADD COLUMN `deleted_by` VARCHAR(36) NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'weekly_reports' AND INDEX_NAME = 'idx_weekly_reports_deleted_at');
SET @sql := IF(@idx = 0, 'CREATE INDEX `idx_weekly_reports_deleted_at` ON `weekly_reports` (`deleted_at`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- announcements
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'announcements' AND COLUMN_NAME = 'deleted_at');
SET @sql := IF(@exist = 0, 'ALTER TABLE `announcements` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'announcements' AND COLUMN_NAME = 'deleted_by');
SET @sql := IF(@exist = 0, 'ALTER TABLE `announcements` ADD COLUMN `deleted_by` VARCHAR(36) NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'announcements' AND INDEX_NAME = 'idx_announcements_deleted_at');
SET @sql := IF(@idx = 0, 'CREATE INDEX `idx_announcements_deleted_at` ON `announcements` (`deleted_at`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- user_feedback
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_feedback' AND COLUMN_NAME = 'deleted_at');
SET @sql := IF(@exist = 0, 'ALTER TABLE `user_feedback` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_feedback' AND COLUMN_NAME = 'deleted_by');
SET @sql := IF(@exist = 0, 'ALTER TABLE `user_feedback` ADD COLUMN `deleted_by` VARCHAR(36) NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_feedback' AND INDEX_NAME = 'idx_user_feedback_deleted_at');
SET @sql := IF(@idx = 0, 'CREATE INDEX `idx_user_feedback_deleted_at` ON `user_feedback` (`deleted_at`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- shorts
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'shorts' AND COLUMN_NAME = 'deleted_at');
SET @sql := IF(@exist = 0, 'ALTER TABLE `shorts` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'shorts' AND COLUMN_NAME = 'deleted_by');
SET @sql := IF(@exist = 0, 'ALTER TABLE `shorts` ADD COLUMN `deleted_by` VARCHAR(36) NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'shorts' AND INDEX_NAME = 'idx_shorts_deleted_at');
SET @sql := IF(@idx = 0, 'CREATE INDEX `idx_shorts_deleted_at` ON `shorts` (`deleted_at`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- project_activities
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'project_activities' AND COLUMN_NAME = 'deleted_at');
SET @sql := IF(@exist = 0, 'ALTER TABLE `project_activities` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'project_activities' AND COLUMN_NAME = 'deleted_by');
SET @sql := IF(@exist = 0, 'ALTER TABLE `project_activities` ADD COLUMN `deleted_by` VARCHAR(36) NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'project_activities' AND INDEX_NAME = 'idx_project_activities_deleted_at');
SET @sql := IF(@idx = 0, 'CREATE INDEX `idx_project_activities_deleted_at` ON `project_activities` (`deleted_at`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- user_documents
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_documents' AND COLUMN_NAME = 'deleted_at');
SET @sql := IF(@exist = 0, 'ALTER TABLE `user_documents` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_documents' AND COLUMN_NAME = 'deleted_by');
SET @sql := IF(@exist = 0, 'ALTER TABLE `user_documents` ADD COLUMN `deleted_by` VARCHAR(36) NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_documents' AND INDEX_NAME = 'idx_user_documents_deleted_at');
SET @sql := IF(@idx = 0, 'CREATE INDEX `idx_user_documents_deleted_at` ON `user_documents` (`deleted_at`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- user_sheets
SET @tbl_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_sheets');
SET @exist := IF(@tbl_exists > 0, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_sheets' AND COLUMN_NAME = 'deleted_at'), 1);
SET @sql := IF(@tbl_exists > 0 AND @exist = 0, 'ALTER TABLE `user_sheets` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @exist := IF(@tbl_exists > 0, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_sheets' AND COLUMN_NAME = 'deleted_by'), 1);
SET @sql := IF(@tbl_exists > 0 AND @exist = 0, 'ALTER TABLE `user_sheets` ADD COLUMN `deleted_by` VARCHAR(36) NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @idx := IF(@tbl_exists > 0, (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_sheets' AND INDEX_NAME = 'idx_user_sheets_deleted_at'), 1);
SET @sql := IF(@tbl_exists > 0 AND @idx = 0, 'CREATE INDEX `idx_user_sheets_deleted_at` ON `user_sheets` (`deleted_at`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- roles
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'roles' AND COLUMN_NAME = 'deleted_at');
SET @sql := IF(@exist = 0, 'ALTER TABLE `roles` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'roles' AND COLUMN_NAME = 'deleted_by');
SET @sql := IF(@exist = 0, 'ALTER TABLE `roles` ADD COLUMN `deleted_by` VARCHAR(36) NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'roles' AND INDEX_NAME = 'idx_roles_deleted_at');
SET @sql := IF(@idx = 0, 'CREATE INDEX `idx_roles_deleted_at` ON `roles` (`deleted_at`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- performance_reviews
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'performance_reviews' AND COLUMN_NAME = 'deleted_at');
SET @sql := IF(@exist = 0, 'ALTER TABLE `performance_reviews` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'performance_reviews' AND COLUMN_NAME = 'deleted_by');
SET @sql := IF(@exist = 0, 'ALTER TABLE `performance_reviews` ADD COLUMN `deleted_by` VARCHAR(36) NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'performance_reviews' AND INDEX_NAME = 'idx_performance_reviews_deleted_at');
SET @sql := IF(@idx = 0, 'CREATE INDEX `idx_performance_reviews_deleted_at` ON `performance_reviews` (`deleted_at`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- work_submissions
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_submissions' AND COLUMN_NAME = 'deleted_at');
SET @sql := IF(@exist = 0, 'ALTER TABLE `work_submissions` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_submissions' AND COLUMN_NAME = 'deleted_by');
SET @sql := IF(@exist = 0, 'ALTER TABLE `work_submissions` ADD COLUMN `deleted_by` VARCHAR(36) NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_submissions' AND INDEX_NAME = 'idx_work_submissions_deleted_at');
SET @sql := IF(@idx = 0, 'CREATE INDEX `idx_work_submissions_deleted_at` ON `work_submissions` (`deleted_at`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- shared_tasks
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'shared_tasks' AND COLUMN_NAME = 'deleted_at');
SET @sql := IF(@exist = 0, 'ALTER TABLE `shared_tasks` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'shared_tasks' AND COLUMN_NAME = 'deleted_by');
SET @sql := IF(@exist = 0, 'ALTER TABLE `shared_tasks` ADD COLUMN `deleted_by` VARCHAR(36) NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'shared_tasks' AND INDEX_NAME = 'idx_shared_tasks_deleted_at');
SET @sql := IF(@idx = 0, 'CREATE INDEX `idx_shared_tasks_deleted_at` ON `shared_tasks` (`deleted_at`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- user_tasks
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_tasks' AND COLUMN_NAME = 'deleted_at');
SET @sql := IF(@exist = 0, 'ALTER TABLE `user_tasks` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_tasks' AND COLUMN_NAME = 'deleted_by');
SET @sql := IF(@exist = 0, 'ALTER TABLE `user_tasks` ADD COLUMN `deleted_by` VARCHAR(36) NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_tasks' AND INDEX_NAME = 'idx_user_tasks_deleted_at');
SET @sql := IF(@idx = 0, 'CREATE INDEX `idx_user_tasks_deleted_at` ON `user_tasks` (`deleted_at`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- codo_common_rules
SET @tbl_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'codo_common_rules');
SET @exist := IF(@tbl_exists > 0, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'codo_common_rules' AND COLUMN_NAME = 'deleted_at'), 1);
SET @sql := IF(@tbl_exists > 0 AND @exist = 0, 'ALTER TABLE `codo_common_rules` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @exist := IF(@tbl_exists > 0, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'codo_common_rules' AND COLUMN_NAME = 'deleted_by'), 1);
SET @sql := IF(@tbl_exists > 0 AND @exist = 0, 'ALTER TABLE `codo_common_rules` ADD COLUMN `deleted_by` VARCHAR(36) NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @idx := IF(@tbl_exists > 0, (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'codo_common_rules' AND INDEX_NAME = 'idx_codo_common_rules_deleted_at'), 1);
SET @sql := IF(@tbl_exists > 0 AND @idx = 0, 'CREATE INDEX `idx_codo_common_rules_deleted_at` ON `codo_common_rules` (`deleted_at`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ----------------------------------------------------------------
-- 3. Permissions
-- ----------------------------------------------------------------
INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'RECYCLE_BIN_VIEW', 'View Recycle Bin', 'Recycle Bin', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (
  SELECT 1 FROM `permissions` WHERE `permission_key` = 'RECYCLE_BIN_VIEW'
);

INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'RECYCLE_BIN_MANAGE', 'Manage Recycle Bin', 'Recycle Bin', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (
  SELECT 1 FROM `permissions` WHERE `permission_key` = 'RECYCLE_BIN_MANAGE'
);

INSERT INTO `role_permissions` (`role_id`, `permission_id`, `created_at`)
SELECT 1, p.id, NOW()
FROM `permissions` p
WHERE p.permission_key IN ('RECYCLE_BIN_VIEW', 'RECYCLE_BIN_MANAGE')
AND NOT EXISTS (
  SELECT 1 FROM `role_permissions` rp
  WHERE rp.role_id = 1 AND rp.permission_id = p.id
);
