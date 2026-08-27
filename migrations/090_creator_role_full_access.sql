-- Creator as a first-class audience + docs/sheets create permissions (safe to re-run).

INSERT INTO `role_permissions` (`role_id`, `permission_id`, `created_at`)
SELECT r.id, p.id, NOW()
FROM `roles` r
CROSS JOIN `permissions` p
WHERE LOWER(r.role_name) = 'creator'
AND p.permission_key IN (
  'DOCS_VIEW',
  'DOCS_CREATE',
  'SHEETS_VIEW',
  'SHEETS_MANAGE',
  'MEETINGS_JOIN',
  'MEETINGS_CREATE',
  'TASKS_VIEW_ASSIGNED',
  'TASKS_CREATE',
  'DAILY_UPDATE_VIEW',
  'DAILY_UPDATE_CREATE',
  'LEAVE_VIEW',
  'MESSAGING_VIEW',
  'CODO_VIEW',
  'CREATIVE_VIEW',
  'CREATIVE_CREATE',
  'PROJECTS_VIEW_ASSIGNED'
)
AND NOT EXISTS (
  SELECT 1 FROM `role_permissions` rp
  WHERE rp.role_id = r.id AND rp.permission_id = p.id
);
