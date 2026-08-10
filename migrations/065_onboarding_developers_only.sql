-- =============================================================================
-- BugRicer Migration 065 — Onboarding required for developers only
-- =============================================================================
-- Testers/admins/custom roles skip the mandatory wizard. Mark incomplete
-- non-developers as completed so existing accounts are not locked out.
-- Safe to re-run.
-- =============================================================================

SET @db := DATABASE();

SET @has_oc := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'onboarding_completed'
);

SET @sql := IF(@has_oc > 0,
  'UPDATE `users`
   SET `onboarding_completed` = 1
   WHERE `onboarding_completed` = 0
     AND LOWER(COALESCE(`role`, '''')) <> ''developer''
     AND (COALESCE(`role_id`, 0) <> 2)',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_msp := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'must_set_password'
);

SET @sql := IF(@has_msp > 0,
  'UPDATE `users`
   SET `must_set_password` = 0
   WHERE `must_set_password` = 1
     AND LOWER(COALESCE(`role`, '''')) <> ''developer''
     AND (COALESCE(`role_id`, 0) <> 2)',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
