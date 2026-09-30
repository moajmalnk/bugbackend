-- =============================================================================
-- BugRicer Migration 115 — Tester type (CODO in-house vs Client)
-- =============================================================================
-- Why: Testers are either CODO in-house staff (workforce: BugUpdate, check-in,
-- weekly report, leave) or external client reviewers (bug reporting only).
-- Access to workforce features is decided by users.tester_type = 'codo'.
--
-- - Adds users.tester_type ENUM('codo','client') NULL (NULL for non-testers)
-- - Backfills every existing tester to 'client' (least privilege); admins then
--   mark in-house testers as CODO from the Users page.
-- - Adds (role, tester_type) index for roster / attendance filters.
-- - Grants DAILY_UPDATE_CREATE to Tester (role_id = 3); client testers remain
--   blocked by the tester_type gate on the backend. DAILY_UPDATE_VIEW is NOT
--   granted: it unlocks the team-wide weekly report view.
-- Safe to re-run (information_schema guards / NOT EXISTS seeds).
-- =============================================================================

SET @db := DATABASE();

-- 1. Column
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'tester_type'
);
SET @sql := IF(
  @exist = 0,
  'ALTER TABLE `users` ADD COLUMN `tester_type` ENUM(''codo'',''client'') NULL DEFAULT NULL AFTER `role`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Backfill existing testers (least privilege)
UPDATE `users` SET `tester_type` = 'client'
WHERE `role` = 'tester' AND `tester_type` IS NULL;

-- 3. Index
SET @exist := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_role_tester_type'
);
SET @sql := IF(
  @exist = 0,
  'ALTER TABLE `users` ADD INDEX `idx_users_role_tester_type` (`role`, `tester_type`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. Tester role permissions for daily work updates
INSERT INTO `role_permissions` (`role_id`, `permission_id`, `created_at`)
SELECT 3, p.id, NOW()
FROM `permissions` p
WHERE p.permission_key IN ('DAILY_UPDATE_CREATE', 'LEAVE_VIEW')
AND NOT EXISTS (
  SELECT 1 FROM `role_permissions` rp
  WHERE rp.role_id = 3 AND rp.permission_id = p.id
);
