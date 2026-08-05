-- Why: Each bug type can suggest a default bug priority when raising bugs.
-- Safe to re-run: ADD COLUMN guarded via information_schema.

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'bug_types'
    AND COLUMN_NAME = 'default_priority'
);

SET @sql := IF(
  @col_exists = 0,
  "ALTER TABLE bug_types
     ADD COLUMN default_priority ENUM('low','medium','high') NOT NULL DEFAULT 'medium'
     AFTER sort_order,
     ADD KEY idx_bug_types_default_priority (default_priority)",
  "SELECT 1"
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Sensible defaults for known seed types (idempotent by slug)
UPDATE bug_types SET default_priority = 'high'
WHERE slug IN ('security_issue', 'crash_issue', 'data_issue', 'permission_issue')
  AND default_priority = 'medium';

UPDATE bug_types SET default_priority = 'high'
WHERE slug IN ('performance_issue', 'api_issue', 'regression', 'functional_issue')
  AND default_priority = 'medium';

UPDATE bug_types SET default_priority = 'low'
WHERE slug IN ('typo_content', 'design_mismatch')
  AND default_priority = 'medium';
