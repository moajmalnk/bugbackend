-- Corporate Official Leave: admin-granted company holidays (8h credit), no personal quota.

INSERT INTO leave_types (code, name, monthly_quota, is_active)
VALUES ('corporate', 'Official Leave', 0.00, 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  monthly_quota = VALUES(monthly_quota),
  is_active = VALUES(is_active);
