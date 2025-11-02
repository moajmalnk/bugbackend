-- Time Tracking System Schema
-- This schema supports check-in/check-out with pause/resume functionality

-- work_sessions: Track check-in/check-out sessions
CREATE TABLE IF NOT EXISTS work_sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id VARCHAR(36) NOT NULL,
  submission_date DATE NOT NULL,
  check_in_time TIMESTAMP NOT NULL,
  check_out_time TIMESTAMP NULL,
  total_duration_seconds INT DEFAULT 0,
  net_duration_seconds INT DEFAULT 0, -- Total minus pause time
  is_active BOOLEAN DEFAULT TRUE,
  session_notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_user_date (user_id, submission_date),
  INDEX idx_active (user_id, is_active),
  INDEX idx_check_in (check_in_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- session_pauses: Track pause/hold periods during work sessions
CREATE TABLE IF NOT EXISTS session_pauses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  session_id INT NOT NULL,
  pause_start TIMESTAMP NOT NULL,
  pause_end TIMESTAMP NULL,
  pause_reason VARCHAR(255) DEFAULT 'break',
  duration_seconds INT DEFAULT 0,
  is_active BOOLEAN DEFAULT TRUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (session_id) REFERENCES work_sessions(id) ON DELETE CASCADE,
  INDEX idx_session (session_id),
  INDEX idx_active_pause (session_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- session_activities: Track what user is doing during session
CREATE TABLE IF NOT EXISTS session_activities (
  id INT AUTO_INCREMENT PRIMARY KEY,
  session_id INT NOT NULL,
  activity_type ENUM('work', 'break', 'meeting', 'training', 'other') DEFAULT 'work',
  start_time TIMESTAMP NOT NULL,
  end_time TIMESTAMP NULL,
  activity_notes TEXT,
  project_id VARCHAR(36) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (session_id) REFERENCES work_sessions(id) ON DELETE CASCADE,
  INDEX idx_session (session_id),
  INDEX idx_activity_type (activity_type),
  INDEX idx_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- time_tracking_settings: User preferences for time tracking
CREATE TABLE IF NOT EXISTS time_tracking_settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id VARCHAR(36) NOT NULL UNIQUE,
  expected_daily_hours DECIMAL(4,2) DEFAULT 8.00,
  auto_checkout_time TIME DEFAULT '18:00:00',
  break_duration_limit_minutes INT DEFAULT 60,
  overtime_threshold_hours DECIMAL(4,2) DEFAULT 8.00,
  reminder_enabled BOOLEAN DEFAULT TRUE,
  reminder_time TIME DEFAULT '17:30:00',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Add foreign key constraint to work_submissions for linking sessions
-- This will be added after ensuring work_submissions table exists
-- ALTER TABLE work_submissions ADD COLUMN session_id INT NULL;
-- ALTER TABLE work_submissions ADD FOREIGN KEY (session_id) REFERENCES work_sessions(id) ON DELETE SET NULL;
