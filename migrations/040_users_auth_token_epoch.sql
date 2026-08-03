-- Auth session epoch for "sign out all devices".
-- Bumping auth_token_epoch invalidates JWTs issued with a prior epoch.
-- Safe to re-run.

SET @db := DATABASE();

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'auth_token_epoch'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `users` ADD COLUMN `auth_token_epoch` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''Incremented to revoke all JWT sessions''',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
