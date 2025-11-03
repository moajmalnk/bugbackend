-- Safe SQL Backfill Script (No project_id dependency)
-- Links existing notifications to users based on their roles
-- Run this ONCE in your production database

START TRANSACTION;

-- Step 1: Link bug_created/new_bug notifications to all developers and admins
INSERT INTO user_notifications (notification_id, user_id, `read`, created_at)
SELECT DISTINCT n.id, u.id, 0, n.created_at
FROM notifications n
CROSS JOIN users u
WHERE n.type IN ('bug_created', 'new_bug')
  AND u.role IN ('developer', 'admin')
  AND NOT EXISTS (
    SELECT 1 FROM user_notifications un 
    WHERE un.notification_id = n.id AND un.user_id = u.id
  );

-- Step 2: Link bug_fixed/status_change notifications to all testers and admins
INSERT INTO user_notifications (notification_id, user_id, `read`, created_at)
SELECT DISTINCT n.id, u.id, 0, n.created_at
FROM notifications n
CROSS JOIN users u
WHERE n.type IN ('bug_fixed', 'status_change')
  AND u.role IN ('tester', 'admin')
  AND NOT EXISTS (
    SELECT 1 FROM user_notifications un 
    WHERE un.notification_id = n.id AND un.user_id = u.id
  );

-- Step 3: Link update_created/task_created/meet_created/doc_created/project_created to all admins
-- (Simplified - no project_id dependency)
INSERT INTO user_notifications (notification_id, user_id, `read`, created_at)
SELECT DISTINCT n.id, u.id, 0, n.created_at
FROM notifications n
CROSS JOIN users u
WHERE n.type IN ('update_created', 'task_created', 'meet_created', 'doc_created', 'project_created', 'new_update')
  AND u.role = 'admin'
  AND NOT EXISTS (
    SELECT 1 FROM user_notifications un 
    WHERE un.notification_id = n.id AND un.user_id = u.id
  );

-- Step 4: Link all other notification types to all admins (fallback)
INSERT INTO user_notifications (notification_id, user_id, `read`, created_at)
SELECT DISTINCT n.id, u.id, 0, n.created_at
FROM notifications n
CROSS JOIN users u
WHERE u.role = 'admin'
  AND NOT EXISTS (
    SELECT 1 FROM user_notifications un 
    WHERE un.notification_id = n.id AND un.user_id = u.id
  );

COMMIT;

-- Verify the results
SELECT 
    'Total notifications' as metric,
    COUNT(*) as count
FROM notifications
UNION ALL
SELECT 
    'User notifications created' as metric,
    COUNT(*) as count
FROM user_notifications
UNION ALL
SELECT 
    'Unread notifications' as metric,
    COUNT(*) as count
FROM user_notifications
WHERE `read` = 0;

