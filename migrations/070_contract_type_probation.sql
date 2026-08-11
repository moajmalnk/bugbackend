-- Why: Contract type dropdown needs Probation alongside Full-Time / Intern / etc.
-- Safe to re-run.

SET @db := DATABASE();

SET @has_ct := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'contract_type'
);

SET @sql := IF(
  @has_ct > 0,
  'ALTER TABLE `users` MODIFY COLUMN `contract_type` ENUM(''full_time'',''remote'',''part_time'',''contract'',''intern'',''probation'',''other'') NULL DEFAULT NULL COMMENT ''Employment contract type''',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
