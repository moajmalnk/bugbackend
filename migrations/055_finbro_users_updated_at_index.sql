-- Finbro integration: index for users/status ORDER BY updated_at DESC (CODO indexing).
-- Safe to re-run: skips if idx_users_updated_at already exists.

SET @db := DATABASE();
SET @idx_exists := (
  SELECT COUNT(1)
  FROM information_schema.statistics
  WHERE table_schema = @db
    AND table_name = 'users'
    AND index_name = 'idx_users_updated_at'
);

SET @sql := IF(
  @idx_exists = 0,
  'ALTER TABLE `users` ADD KEY `idx_users_updated_at` (`updated_at`)',
  'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
