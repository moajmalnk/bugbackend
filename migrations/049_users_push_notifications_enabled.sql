-- Admin-controlled push notification preference per user. Safe to re-run.

SET @db := DATABASE();

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'push_notifications_enabled'
);
SET @has_account := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'account_active'
);
SET @sql := IF(@exist = 0,
  IF(@has_account > 0,
    'ALTER TABLE `users` ADD COLUMN `push_notifications_enabled` TINYINT(1) NOT NULL DEFAULT 1 COMMENT ''Admin: allow FCM push for this user'' AFTER `account_active`',
    'ALTER TABLE `users` ADD COLUMN `push_notifications_enabled` TINYINT(1) NOT NULL DEFAULT 1 COMMENT ''Admin: allow FCM push for this user'''
  ),
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
