-- Quick Fix: Link notification 569 (new_update) to all admins
-- This will make it visible immediately

INSERT INTO user_notifications (notification_id, user_id, `read`, created_at)
SELECT DISTINCT n.id, u.id, 0, n.created_at
FROM notifications n
CROSS JOIN users u
WHERE n.id = 569
  AND n.type = 'new_update'
  AND u.role = 'admin'
  AND NOT EXISTS (
    SELECT 1 FROM user_notifications un 
    WHERE un.notification_id = n.id AND un.user_id = u.id
  );

-- Verify it was created
SELECT 
    un.id,
    un.notification_id,
    un.user_id,
    u.username,
    u.role,
    n.type,
    n.title,
    un.read,
    un.created_at
FROM user_notifications un
JOIN notifications n ON n.id = un.notification_id
JOIN users u ON u.id = un.user_id
WHERE un.notification_id = 569;

