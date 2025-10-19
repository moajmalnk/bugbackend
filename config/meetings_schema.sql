-- BugMeet SQL schema
-- Tables: meetings, meeting_participants, meeting_messages, meeting_recordings (placeholder)

CREATE TABLE IF NOT EXISTS meetings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  meeting_code VARCHAR(16) NOT NULL UNIQUE,
  title VARCHAR(255) NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_meetings_code (meeting_code),
  INDEX idx_meetings_creator (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meeting_participants (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  meeting_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  display_name VARCHAR(255) NULL,
  role ENUM('host','cohost','participant') NOT NULL DEFAULT 'participant',
  joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  left_at TIMESTAMP NULL DEFAULT NULL,
  is_connected TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_participant_meeting FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE,
  INDEX idx_participants_meeting (meeting_id),
  INDEX idx_participants_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meeting_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  meeting_id BIGINT UNSIGNED NOT NULL,
  sender_id BIGINT UNSIGNED NULL,
  sender_name VARCHAR(255) NULL,
  message TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_message_meeting FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE,
  INDEX idx_messages_meeting (meeting_id),
  FULLTEXT INDEX idx_messages_text (message)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional placeholder for recordings metadata (if added later)
CREATE TABLE IF NOT EXISTS meeting_recordings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  meeting_id BIGINT UNSIGNED NOT NULL,
  storage_path VARCHAR(512) NOT NULL,
  duration_seconds INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_recording_meeting FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE,
  INDEX idx_recordings_meeting (meeting_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

