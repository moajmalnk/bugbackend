-- Leave policy update: drop Personal Leave; keep Paid 1, Sick 1, Unpaid max 5 per month.

UPDATE leave_types
SET
  name = 'Paid Leave',
  monthly_quota = 1.00,
  is_active = 1
WHERE code = 'paid';

UPDATE leave_types
SET
  name = 'Sick Leave',
  monthly_quota = 1.00,
  is_active = 1
WHERE code = 'sick';

UPDATE leave_types
SET
  name = 'Unpaid Leave',
  monthly_quota = 5.00,
  is_active = 1
WHERE code = 'unpaid';

-- Hide Personal Leave from request UI / balances (keep row for historical requests).
UPDATE leave_types
SET is_active = 0
WHERE code = 'personal';

-- Ensure Unpaid exists on older DBs that never ran 021.
INSERT INTO leave_types (code, name, monthly_quota, is_active)
VALUES ('unpaid', 'Unpaid Leave', 5.00, 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  monthly_quota = VALUES(monthly_quota),
  is_active = VALUES(is_active);
