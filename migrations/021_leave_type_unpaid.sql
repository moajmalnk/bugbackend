-- Add Unpaid Leave type with monthly quota of 5 days.

INSERT INTO leave_types (code, name, monthly_quota, is_active)
VALUES ('unpaid', 'Unpaid Leave', 5.00, 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  monthly_quota = VALUES(monthly_quota),
  is_active = VALUES(is_active);
