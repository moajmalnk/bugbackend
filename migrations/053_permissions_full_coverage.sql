-- Full permission catalog coverage for custom roles
-- Idempotent: safe to re-run. Omits permission_description (not on all deployments).

-- Messaging (FE/API canonical keys; keep existing MESSAGING_SEND / MESSAGING_MANAGE_GROUPS)
INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'MESSAGING_CREATE', 'Create / Send WhatsApp & Messages', 'Messaging', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `permission_key` = 'MESSAGING_CREATE');

INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'MESSAGING_MANAGE', 'Manage Messaging Groups', 'Messaging', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `permission_key` = 'MESSAGING_MANAGE');

-- Activity
INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'ACTIVITY_VIEW', 'View Activity Log', 'Activity', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `permission_key` = 'ACTIVITY_VIEW');

INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'ACTIVITY_DELETE', 'Delete Activity Entries', 'Activity', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `permission_key` = 'ACTIVITY_DELETE');

-- Feedback
INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'FEEDBACK_VIEW', 'View Feedback Stats', 'Feedback', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `permission_key` = 'FEEDBACK_VIEW');

-- Daily work / BugUpdate
INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'DAILY_UPDATE_VIEW', 'View Daily Work Updates', 'Daily Update', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `permission_key` = 'DAILY_UPDATE_VIEW');

INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'DAILY_UPDATE_CREATE', 'Create Daily Work Updates', 'Daily Update', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `permission_key` = 'DAILY_UPDATE_CREATE');

INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'DAILY_UPDATE_MANAGE', 'Manage All Daily Work Updates', 'Daily Update', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `permission_key` = 'DAILY_UPDATE_MANAGE');

-- Leave / Overtime / Attendance
INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'LEAVE_VIEW', 'View Own Leave', 'Leave', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `permission_key` = 'LEAVE_VIEW');

INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'LEAVE_MANAGE', 'Manage Leave Requests', 'Leave', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `permission_key` = 'LEAVE_MANAGE');

INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'OVERTIME_VIEW', 'View Own Overtime', 'Overtime', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `permission_key` = 'OVERTIME_VIEW');

INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'OVERTIME_MANAGE', 'Manage Overtime Requests', 'Overtime', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `permission_key` = 'OVERTIME_MANAGE');

INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'ATTENDANCE_MANAGE', 'Manage Attendance Exceptions', 'Attendance', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `permission_key` = 'ATTENDANCE_MANAGE');

-- Docs products / admin surfaces
INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'SHEETS_VIEW', 'View BugSheets', 'Sheets', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `permission_key` = 'SHEETS_VIEW');

INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'SHEETS_MANAGE', 'Manage BugSheets', 'Sheets', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `permission_key` = 'SHEETS_MANAGE');

INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'BACKUP_MANAGE', 'Manage BugBackup', 'Backup', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `permission_key` = 'BACKUP_MANAGE');

INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'SHORTS_MANAGE', 'Manage Shorts', 'Shorts', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `permission_key` = 'SHORTS_MANAGE');

INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'COMMON_BUGS_VIEW', 'View Common Bugs', 'Common Bugs', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `permission_key` = 'COMMON_BUGS_VIEW');

INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'COMMON_BUGS_MANAGE', 'Manage Common Bugs', 'Common Bugs', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `permission_key` = 'COMMON_BUGS_MANAGE');

INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'CODO_VIEW', 'View CODO Rules', 'CODO', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `permission_key` = 'CODO_VIEW');

INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'CODO_MANAGE', 'Manage CODO Rules', 'CODO', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `permission_key` = 'CODO_MANAGE');

INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'PUSH_COVERAGE_VIEW', 'View Push Coverage', 'Push', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `permission_key` = 'PUSH_COVERAGE_VIEW');

INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'REPORTS_VIEW', 'View Reports', 'Reports', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `permission_key` = 'REPORTS_VIEW');

INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'DASHBOARD_VIEW', 'View Admin Dashboard', 'Dashboard', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `permission_key` = 'DASHBOARD_VIEW');

-- Ensure CLIENTS_* exist (also added in 027)
INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'CLIENTS_VIEW', 'View Clients', 'Clients', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `permission_key` = 'CLIENTS_VIEW');

INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'CLIENTS_CREATE', 'Create Clients', 'Clients', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `permission_key` = 'CLIENTS_CREATE');

INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'CLIENTS_EDIT', 'Edit Clients', 'Clients', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `permission_key` = 'CLIENTS_EDIT');

INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'CLIENTS_DELETE', 'Delete Clients', 'Clients', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `permission_key` = 'CLIENTS_DELETE');

-- Grant ALL new keys to Admin (role_id = 1)
INSERT INTO `role_permissions` (`role_id`, `permission_id`, `created_at`)
SELECT 1, p.id, NOW()
FROM `permissions` p
WHERE p.permission_key IN (
  'MESSAGING_CREATE', 'MESSAGING_MANAGE',
  'ACTIVITY_VIEW', 'ACTIVITY_DELETE',
  'FEEDBACK_VIEW',
  'DAILY_UPDATE_VIEW', 'DAILY_UPDATE_CREATE', 'DAILY_UPDATE_MANAGE',
  'LEAVE_VIEW', 'LEAVE_MANAGE',
  'OVERTIME_VIEW', 'OVERTIME_MANAGE',
  'ATTENDANCE_MANAGE',
  'SHEETS_VIEW', 'SHEETS_MANAGE',
  'BACKUP_MANAGE', 'SHORTS_MANAGE',
  'COMMON_BUGS_VIEW', 'COMMON_BUGS_MANAGE',
  'CODO_VIEW', 'CODO_MANAGE',
  'PUSH_COVERAGE_VIEW', 'REPORTS_VIEW', 'DASHBOARD_VIEW',
  'CLIENTS_VIEW', 'CLIENTS_CREATE', 'CLIENTS_EDIT', 'CLIENTS_DELETE'
)
AND NOT EXISTS (
  SELECT 1 FROM `role_permissions` rp
  WHERE rp.role_id = 1 AND rp.permission_id = p.id
);

-- Developer (role_id = 2): view/create daily work, leave self, sheets view, common bugs/codo view, messaging view tools
INSERT INTO `role_permissions` (`role_id`, `permission_id`, `created_at`)
SELECT 2, p.id, NOW()
FROM `permissions` p
WHERE p.permission_key IN (
  'MESSAGING_CREATE', 'MESSAGING_MANAGE',
  'DAILY_UPDATE_VIEW', 'DAILY_UPDATE_CREATE',
  'LEAVE_VIEW', 'OVERTIME_VIEW',
  'SHEETS_VIEW',
  'MEETINGS_JOIN', 'MEETINGS_CREATE',
  'COMMON_BUGS_VIEW', 'CODO_VIEW',
  'REPORTS_VIEW'
)
AND NOT EXISTS (
  SELECT 1 FROM `role_permissions` rp
  WHERE rp.role_id = 2 AND rp.permission_id = p.id
);

-- Tester (role_id = 3): leave self, common bugs/codo view, meetings already seeded elsewhere
INSERT INTO `role_permissions` (`role_id`, `permission_id`, `created_at`)
SELECT 3, p.id, NOW()
FROM `permissions` p
WHERE p.permission_key IN (
  'LEAVE_VIEW', 'OVERTIME_VIEW',
  'COMMON_BUGS_VIEW', 'CODO_VIEW'
)
AND NOT EXISTS (
  SELECT 1 FROM `role_permissions` rp
  WHERE rp.role_id = 3 AND rp.permission_id = p.id
);
