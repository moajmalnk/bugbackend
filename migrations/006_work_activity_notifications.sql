-- =============================================================================
-- BugRicer Migration 006 — Work activity notifications + notification schema
-- =============================================================================
-- For phpMyAdmin / shared hosting (Hostinger, etc.)
--
-- ALREADY HAVE check_in_time? Use 006b_notifications_only.sql instead.
--
-- IMPORTANT:
--   • Select your app database first (e.g. u524154866_bugfixer), then run this.
--   • phpMyAdmin runs the whole file as ONE batch — it STOPS on the first error.
--   • "Duplicate column name" = that part is already done; use 006b_...sql instead.
--   • Does NOT use INFORMATION_SCHEMA (blocked on shared hosting).
-- =============================================================================

-- -----------------------------------------------------------------------------
-- A. work_submissions — check-in / planned work
-- Skip any line that says "Duplicate column name"
-- -----------------------------------------------------------------------------

ALTER TABLE work_submissions
  ADD COLUMN check_in_time TIMESTAMP NULL DEFAULT NULL AFTER start_time;

ALTER TABLE work_submissions
  ADD COLUMN planned_projects JSON NULL DEFAULT NULL AFTER check_in_time;

ALTER TABLE work_submissions
  ADD COLUMN planned_work TEXT NULL DEFAULT NULL AFTER planned_projects;

ALTER TABLE work_submissions
  ADD COLUMN planned_work_status ENUM('not_started','in_progress','completed','blocked','cancelled')
    NULL DEFAULT 'not_started' AFTER planned_work;

-- -----------------------------------------------------------------------------
-- B. notifications — entity columns (skip if duplicate column)
-- -----------------------------------------------------------------------------

ALTER TABLE notifications
  ADD COLUMN entity_type VARCHAR(50) DEFAULT NULL
    COMMENT 'bug, task, work_check_in, work_break, work_update, overtime, …'
    AFTER message;

ALTER TABLE notifications
  ADD COLUMN entity_id VARCHAR(64) DEFAULT NULL
    COMMENT 'Related entity id (e.g. userId:date for work activity)'
    AFTER entity_type;

ALTER TABLE notifications
  ADD COLUMN project_id VARCHAR(36) DEFAULT NULL
    COMMENT 'Related project id'
    AFTER entity_id;

-- Non-bug notifications (check-in, break, check-out, etc.)
ALTER TABLE notifications
  MODIFY COLUMN bug_id VARCHAR(36) NULL DEFAULT NULL,
  MODIFY COLUMN bug_title VARCHAR(255) NULL DEFAULT NULL,
  MODIFY COLUMN created_by VARCHAR(100) NOT NULL DEFAULT 'system';

-- -----------------------------------------------------------------------------
-- C. notifications.type — add work activity + other types to ENUM
-- If this fails, run the UPDATE below first, then retry ALTER.
-- -----------------------------------------------------------------------------

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
    'new_bug',
    'status_change',
    'new_update',
    'bug_created',
    'bug_fixed',
    'update_created',
    'task_created',
    'task_assigned',
    'task_completed',
    'meet_created',
    'meeting_reminder',
    'doc_created',
    'sheet_created',
    'project_created',
    'work_check_in',
    'work_break',
    'work_update',
    'feedback',
    'overtime',
    'message',
    'user_registered',
    'info'
  ) NOT NULL;

-- -----------------------------------------------------------------------------
-- D. user_notifications — per-user read state
-- -----------------------------------------------------------------------------

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

-- -----------------------------------------------------------------------------
-- E. Verify (works on shared hosting — no INFORMATION_SCHEMA)
-- -----------------------------------------------------------------------------

SHOW COLUMNS FROM notifications
  WHERE Field IN ('type', 'entity_type', 'entity_id', 'project_id', 'bug_id', 'created_by');

SHOW TABLES LIKE 'user_notifications';
