-- =============================================================================
-- BugRicer Migration 006b — Notifications only (skip work_submissions columns)
-- =============================================================================
-- Use this if Section A failed with "Duplicate column name" for check_in_time,
-- planned_projects, etc. Those columns already exist — you only need this file.
--
-- In phpMyAdmin: select u524154866_bugfixer → SQL → paste → Go
-- Skip any statement that says "Duplicate column name" and run the rest.
-- =============================================================================

-- B. notifications — entity columns (skip if duplicate column)
ALTER TABLE notifications
  ADD COLUMN entity_type VARCHAR(50) DEFAULT NULL
    COMMENT 'bug, task, work_check_in, work_break, work_update, overtime'
    AFTER message;

ALTER TABLE notifications
  ADD COLUMN entity_id VARCHAR(64) DEFAULT NULL
    COMMENT 'Related entity id (e.g. userId:date)'
    AFTER entity_type;

ALTER TABLE notifications
  ADD COLUMN project_id VARCHAR(36) DEFAULT NULL
    COMMENT 'Related project id'
    AFTER entity_id;

ALTER TABLE notifications
  MODIFY COLUMN bug_id VARCHAR(36) NULL DEFAULT NULL,
  MODIFY COLUMN bug_title VARCHAR(255) NULL DEFAULT NULL,
  MODIFY COLUMN created_by VARCHAR(100) NOT NULL DEFAULT 'system';

-- C. Extend notifications.type ENUM (work check-in / break / check-out)
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

ALTER TABLE notifications
  MODIFY COLUMN type ENUM(
    'new_bug', 'status_change', 'new_update', 'bug_created', 'bug_fixed',
    'update_created', 'task_created', 'task_assigned', 'task_completed',
    'meet_created', 'meeting_reminder', 'doc_created', 'sheet_created',
    'project_created', 'work_check_in', 'work_break', 'work_update',
    'feedback', 'overtime', 'message', 'user_registered', 'info'
  ) NOT NULL;

-- D. user_notifications table
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

-- Verify
SHOW COLUMNS FROM notifications
  WHERE Field IN ('type', 'entity_type', 'entity_id', 'project_id', 'bug_id');

SHOW TABLES LIKE 'user_notifications';
