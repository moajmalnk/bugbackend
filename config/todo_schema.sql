-- BugToDo minimal schema
-- user_tasks: personal tasks per user
CREATE TABLE IF NOT EXISTS user_tasks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id VARCHAR(36) NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT,
  project_id VARCHAR(36) DEFAULT NULL,
  priority ENUM('low','medium','high') DEFAULT 'medium',
  status ENUM('todo','in_progress','done','blocked') DEFAULT 'todo',
  due_date DATE DEFAULT NULL,
  period ENUM('daily','weekly','monthly','yearly','custom') DEFAULT 'daily',
  expected_hours DECIMAL(6,2) DEFAULT 0,
  spent_hours DECIMAL(10,2) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_user (user_id),
  INDEX idx_project (project_id),
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- work_submissions: daily work logs used to generate WhatsApp messages
CREATE TABLE IF NOT EXISTS work_submissions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id VARCHAR(36) NOT NULL,
  submission_date DATE NOT NULL,
  start_time TIME DEFAULT NULL,
  hours_today DECIMAL(6,2) NOT NULL DEFAULT 0,
  overtime_hours DECIMAL(6,2) DEFAULT 0,
  total_working_days INT DEFAULT NULL,
  total_hours_cumulative DECIMAL(10,2) DEFAULT NULL,
  completed_tasks MEDIUMTEXT,
  pending_tasks MEDIUMTEXT,
  notes MEDIUMTEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_user_day (user_id, submission_date),
  INDEX idx_user_date (user_id, submission_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



