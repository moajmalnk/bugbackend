-- =============================================================================
-- BugRicer Migration 135 — Super Admin hierarchy
-- =============================================================================
-- Why: Official Super Admin (bugricer) can create Admins + all roles.
-- Regular Admins manage workforce only (developer / tester / creator).
-- JWT role stays `admin` for both so /admin routes are unchanged.
--
-- - Adds users.is_super_admin TINYINT(1)
-- - Seeds bugricer as Super Admin + official profile fields
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

-- 3. Official Super Admin account (bugricer)
UPDATE `users`
SET
  `is_super_admin` = 1,
  `role` = 'admin',
  `created_at` = '2024-06-05 00:00:00',
  `joining_date` = COALESCE(`joining_date`, '2024-06-05'),
  `job_title` = COALESCE(NULLIF(TRIM(`job_title`), ''), 'Platform Owner'),
  `job_level` = COALESCE(NULLIF(TRIM(`job_level`), ''), 'Founder'),
  `department` = COALESCE(NULLIF(TRIM(`department`), ''), 'BugRicer')
WHERE `id` = 'e17b6fcf-04f7-4ad5-a9f0-3c8c910a8efc'
   OR LOWER(`username`) = 'bugricer';

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
