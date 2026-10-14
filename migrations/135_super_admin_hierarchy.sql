-- =============================================================================
-- BugRicer Migration 135 — Super Admin hierarchy
-- =============================================================================
-- Why: Official Super Admin (bugricer) can create Admins + all roles.
-- Regular Admins manage workforce only (developer / tester / creator).
-- JWT role stays `admin` for both so /admin routes are unchanged.
--
-- - Adds users.is_super_admin TINYINT(1)
-- - Seeds bugricer as Super Admin + official profile fields (when columns exist)
-- - Removes SUPER_ADMIN from Admin role_permissions (role_id = 1)
-- - Grants SUPER_ADMIN via user_permissions only for is_super_admin = 1
-- Safe to re-run (information_schema guards / NOT EXISTS).
-- =============================================================================

SET @db := DATABASE();

-- 1. Column
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_super_admin'
);
SET @sql := IF(
  @exist = 0,
  'ALTER TABLE `users` ADD COLUMN `is_super_admin` TINYINT(1) NOT NULL DEFAULT 0 AFTER `role`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Index
SET @exist := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_is_super_admin'
);
SET @sql := IF(
  @exist = 0,
  'ALTER TABLE `users` ADD INDEX `idx_users_is_super_admin` (`is_super_admin`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. Official Super Admin flag + role + member-since date
UPDATE `users`
SET
  `is_super_admin` = 1,
  `role` = 'admin',
  `created_at` = '2024-06-05 00:00:00'
WHERE `id` = 'e17b6fcf-04f7-4ad5-a9f0-3c8c910a8efc'
   OR LOWER(`username`) = 'bugricer';

-- 3b. Optional HR polish (skip silently when columns are missing)
SET @has_joining := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'joining_date'
);
SET @sql := IF(
  @has_joining > 0,
  'UPDATE `users` SET `joining_date` = COALESCE(`joining_date`, ''2024-06-05'') WHERE `id` = ''e17b6fcf-04f7-4ad5-a9f0-3c8c910a8efc'' OR LOWER(`username`) = ''bugricer''',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_title := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'job_title'
);
SET @sql := IF(
  @has_title > 0,
  'UPDATE `users` SET `job_title` = COALESCE(NULLIF(TRIM(`job_title`), ''''), ''Platform Owner'') WHERE `id` = ''e17b6fcf-04f7-4ad5-a9f0-3c8c910a8efc'' OR LOWER(`username`) = ''bugricer''',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_level := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'job_level'
);
SET @sql := IF(
  @has_level > 0,
  'UPDATE `users` SET `job_level` = COALESCE(NULLIF(TRIM(`job_level`), ''''), ''Founder'') WHERE `id` = ''e17b6fcf-04f7-4ad5-a9f0-3c8c910a8efc'' OR LOWER(`username`) = ''bugricer''',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_dept := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'department'
);
SET @sql := IF(
  @has_dept > 0,
  'UPDATE `users` SET `department` = COALESCE(NULLIF(TRIM(`department`), ''''), ''BugRicer'') WHERE `id` = ''e17b6fcf-04f7-4ad5-a9f0-3c8c910a8efc'' OR LOWER(`username`) = ''bugricer''',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. Remove SUPER_ADMIN from Admin role (role_id = 1)
DELETE rp FROM `role_permissions` rp
INNER JOIN `permissions` p ON p.id = rp.permission_id
WHERE rp.role_id = 1
  AND p.permission_key = 'SUPER_ADMIN';

-- 5. Grant SUPER_ADMIN user permission to every is_super_admin = 1 user
INSERT INTO `user_permissions` (`user_id`, `permission_id`, `project_id`, `granted`, `created_at`)
SELECT u.id, p.id, NULL, 1, NOW()
FROM `users` u
CROSS JOIN `permissions` p
WHERE u.is_super_admin = 1
  AND p.permission_key = 'SUPER_ADMIN'
  AND NOT EXISTS (
    SELECT 1 FROM `user_permissions` up
    WHERE up.user_id = u.id
      AND up.permission_id = p.id
      AND (up.project_id IS NULL OR up.project_id = '')
  );

-- 6. Revoke SUPER_ADMIN user permission from non–Super Admins
UPDATE `user_permissions` up
INNER JOIN `permissions` p ON p.id = up.permission_id
INNER JOIN `users` u ON u.id = up.user_id
SET up.granted = 0
WHERE p.permission_key = 'SUPER_ADMIN'
  AND COALESCE(u.is_super_admin, 0) = 0;
