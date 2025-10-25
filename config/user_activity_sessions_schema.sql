-- User Activity Sessions Schema
-- This table tracks user working sessions for calculating active hours

CREATE TABLE IF NOT EXISTS `user_activity_sessions` (
  `id` varchar(36) NOT NULL PRIMARY KEY,
  `user_id` varchar(36) NOT NULL,
  `session_start` timestamp NOT NULL DEFAULT current_timestamp(),
  `session_end` timestamp NULL DEFAULT NULL,
  `session_duration_minutes` int(11) DEFAULT NULL,
  `activity_type` enum('work','break','meeting','other') DEFAULT 'work',
  `project_id` varchar(36) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_session_start` (`session_start`),
  INDEX `idx_session_end` (`session_end`),
  INDEX `idx_user_session` (`user_id`, `session_start`),
  INDEX `idx_activity_type` (`activity_type`),
  INDEX `idx_project_id` (`project_id`),
  
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Add some sample data for testing (Lubaba's working hours)
INSERT INTO `user_activity_sessions` (`id`, `user_id`, `session_start`, `session_end`, `session_duration_minutes`, `activity_type`, `project_id`, `notes`) VALUES
-- Lubaba's sessions for today (6-7 hours as mentioned)
('session-001', '93f675c2-765f-448c-bdce-d654aebd61f7', '2024-01-15 09:00:00', '2024-01-15 12:00:00', 180, 'work', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'Morning development work'),
('session-002', '93f675c2-765f-448c-bdce-d654aebd61f7', '2024-01-15 13:00:00', '2024-01-15 16:30:00', 210, 'work', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'Afternoon bug fixes'),
('session-003', '93f675c2-765f-448c-bdce-d654aebd61f7', '2024-01-15 17:00:00', '2024-01-15 18:00:00', 60, 'work', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'Evening code review'),

-- Lubaba's sessions for yesterday
('session-004', '93f675c2-765f-448c-bdce-d654aebd61f7', '2024-01-14 08:30:00', '2024-01-14 11:30:00', 180, 'work', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'Morning development'),
('session-005', '93f675c2-765f-448c-bdce-d654aebd61f7', '2024-01-14 12:30:00', '2024-01-14 15:30:00', 180, 'work', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'Afternoon testing'),
('session-006', '93f675c2-765f-448c-bdce-d654aebd61f7', '2024-01-14 16:00:00', '2024-01-14 17:30:00', 90, 'work', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'Evening documentation'),

-- Lubaba's sessions for the week
('session-007', '93f675c2-765f-448c-bdce-d654aebd61f7', '2024-01-13 09:00:00', '2024-01-13 12:00:00', 180, 'work', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'Saturday morning work'),
('session-008', '93f675c2-765f-448c-bdce-d654aebd61f7', '2024-01-12 10:00:00', '2024-01-12 13:00:00', 180, 'work', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'Friday development'),
('session-009', '93f675c2-765f-448c-bdce-d654aebd61f7', '2024-01-12 14:00:00', '2024-01-12 16:00:00', 120, 'work', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'Friday afternoon'),

-- Current active session (no end time)
('session-010', '93f675c2-765f-448c-bdce-d654aebd61f7', NOW(), NULL, NULL, 'work', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'Currently working on bug fixes');
