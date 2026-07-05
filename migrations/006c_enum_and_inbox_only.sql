-- =============================================================================
-- BugRicer Migration 006c — ENUM + user_notifications ONLY
-- =============================================================================
-- Use when you already have:
--   • work_submissions.check_in_time (and planned_* columns)
--   • notifications.entity_type, entity_id, project_id
--
-- phpMyAdmin stops the whole batch on first error — this file has NO ADD COLUMN.
-- Run on database u524154866_bugfixer → SQL tab → Go
-- =============================================================================

-- 1. Allow non-bug notifications (safe to re-run)
ALTER TABLE notifications
  MODIFY COLUMN bug_id VARCHAR(36) NULL DEFAULT NULL,
  MODIFY COLUMN bug_title VARCHAR(255) NULL DEFAULT NULL,
  MODIFY COLUMN created_by VARCHAR(100) NOT NULL DEFAULT 'system';

-- 2. Normalize any invalid type values before ENUM change
UPDATE notifications
SET type = 'new_update'
WHERE type IS NULL
   OR type NOT IN (
     'new_bug', 'status_change', 'new_update', 'bug_created', 'bug_fixed',
     'update_created', 'task_created', 'task_assigned', 'task_completed',
     'meet_created', 'meeting_reminder', 'doc_created', 'sheet_created',
     'project_created', 'work_check_in', 'work_break', 'work_update',
     'feedback', 'overtime', 'message', 'user_registered', 'info'
   );

-- 3. Add work activity types (check-in, break, check-out)
ALTER TABLE notifications
  MODIFY COLUMN type ENUM(
    'new_bug', 'status_change', 'new_update', 'bug_created', 'bug_fixed',
    'update_created', 'task_created', 'task_assigned', 'task_completed',
    'meet_created', 'meeting_reminder', 'doc_created', 'sheet_created',
    'project_created', 'work_check_in', 'work_break', 'work_update',
    'feedback', 'overtime', 'message', 'user_registered', 'info'
  ) NOT NULL;

-- 4. Per-user read state (skipped if table already exists)
CREATE TABLE IF NOT EXISTS user_notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  notification_id INT NOT NULL,
  user_id VARCHAR(36) NOT NULL,
  `read` TINYINT(1) NOT NULL DEFAULT 0,
  read_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_id (user_id),
  INDEX idx_notification_id (notification_id),
  INDEX idx_read (`read`),
  INDEX idx_user_read (user_id, `read`),
  INDEX idx_created_at (created_at),
  UNIQUE KEY unique_user_notification (user_id, notification_id),
  CONSTRAINT fk_user_notifications_notification
    FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_notifications_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 5. Verify
SHOW COLUMNS FROM notifications WHERE Field = 'type';
SHOW TABLES LIKE 'user_notifications';
