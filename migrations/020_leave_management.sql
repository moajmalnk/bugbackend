-- Leave management: types, requests, and notification type for leave events.

CREATE TABLE IF NOT EXISTS leave_types (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(32) NOT NULL,
  name VARCHAR(64) NOT NULL,
  monthly_quota DECIMAL(6,2) NOT NULL DEFAULT 1.00,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_leave_types_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO leave_types (code, name, monthly_quota, is_active)
VALUES
  ('paid', 'Paid Leave', 1.00, 1),
  ('sick', 'Sick Leave', 1.00, 1),
  ('personal', 'Personal Leave', 1.00, 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  monthly_quota = VALUES(monthly_quota),
  is_active = VALUES(is_active);

CREATE TABLE IF NOT EXISTS leave_requests (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id VARCHAR(36) NOT NULL,
  leave_type_id INT UNSIGNED NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  days_count DECIMAL(6,2) NOT NULL DEFAULT 1.00,
  reason TEXT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'pending',
  reviewed_by VARCHAR(36) NULL,
  reviewed_at DATETIME NULL,
  admin_note TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_leave_requests_user (user_id),
  KEY idx_leave_requests_status (status),
  KEY idx_leave_requests_dates (start_date, end_date),
  KEY idx_leave_requests_type (leave_type_id),
  CONSTRAINT fk_leave_requests_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_leave_requests_type
    FOREIGN KEY (leave_type_id) REFERENCES leave_types(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Allow leave notification type (safe to re-run after normalizing invalid values)
UPDATE notifications
SET type = 'status_change'
WHERE type IS NULL
   OR type NOT IN (
     'new_bug', 'status_change', 'new_update', 'bug_created', 'bug_fixed',
     'update_created', 'task_created', 'task_assigned', 'task_completed',
     'meet_created', 'meeting_reminder', 'doc_created', 'sheet_created',
     'project_created', 'work_check_in', 'work_break', 'work_update',
     'feedback', 'overtime', 'message', 'user_registered', 'info', 'leave'
   );

ALTER TABLE notifications
  MODIFY COLUMN type ENUM(
    'new_bug', 'status_change', 'new_update', 'bug_created', 'bug_fixed',
    'update_created', 'task_created', 'task_assigned', 'task_completed',
    'meet_created', 'meeting_reminder', 'doc_created', 'sheet_created',
    'project_created', 'work_check_in', 'work_break', 'work_update',
    'feedback', 'overtime', 'message', 'user_registered', 'info', 'leave'
  ) NOT NULL;
