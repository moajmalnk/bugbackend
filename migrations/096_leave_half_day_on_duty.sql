-- Leave half-day fields + Client On-Duty type. Safe to re-run.

SET @db := DATABASE();

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'is_half_day'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE leave_requests ADD COLUMN is_half_day TINYINT(1) NOT NULL DEFAULT 0 AFTER days_count',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'half_day_type'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE leave_requests ADD COLUMN half_day_type ENUM(''first_half'',''second_half'') NULL AFTER is_half_day',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'emergency_contact'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE leave_requests ADD COLUMN emergency_contact VARCHAR(50) NULL AFTER reason',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO leave_types (code, name, monthly_quota, is_active)
VALUES ('on_duty', 'Client On-Duty', 0.00, 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  is_active = 1;

-- UI label: Casual Leave maps to existing paid code (balances stay stable).
UPDATE leave_types
SET name = 'Casual Leave'
WHERE code = 'paid' AND name <> 'Casual Leave';
