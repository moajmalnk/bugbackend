-- BugMeet Production Setup SQL
-- Run this once on your production database

-- 1. Create meetings table with proper structure
CREATE TABLE IF NOT EXISTS meetings (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  meeting_code VARCHAR(16) NOT NULL,
  title VARCHAR(255) NOT NULL,
  created_by VARCHAR(36) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY meeting_code (meeting_code),
  INDEX idx_meetings_creator (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Create meeting_participants table
CREATE TABLE IF NOT EXISTS meeting_participants (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  meeting_id BIGINT(20) UNSIGNED NOT NULL,
  user_id VARCHAR(36) NULL,
  display_name VARCHAR(255) NULL,
  role ENUM('host','cohost','participant') NOT NULL DEFAULT 'participant',
  joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  left_at TIMESTAMP NULL DEFAULT NULL,
  is_connected TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  INDEX idx_participants_meeting (meeting_id),
  INDEX idx_participants_user (user_id),
  CONSTRAINT fk_participant_meeting FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Create meeting_messages table
CREATE TABLE IF NOT EXISTS meeting_messages (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  meeting_id BIGINT(20) UNSIGNED NOT NULL,
  sender_id VARCHAR(36) NULL,
  sender_name VARCHAR(255) NULL,
  message TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_messages_meeting (meeting_id),
  FULLTEXT INDEX idx_messages_text (message),
  CONSTRAINT fk_message_meeting FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Create meeting_recordings table (optional for future use)
CREATE TABLE IF NOT EXISTS meeting_recordings (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  meeting_id BIGINT(20) UNSIGNED NOT NULL,
  storage_path VARCHAR(512) NOT NULL,
  duration_seconds INT(10) UNSIGNED DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_recordings_meeting (meeting_id),
  CONSTRAINT fk_recording_meeting FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. If tables already exist, update them to match production structure
-- Update meetings table if it exists (only modify columns, don't add existing keys)
ALTER TABLE meetings
  MODIFY COLUMN id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  MODIFY COLUMN created_by VARCHAR(36) NOT NULL;

-- Update meeting_participants table if it exists
ALTER TABLE meeting_participants
  MODIFY COLUMN id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  MODIFY COLUMN user_id VARCHAR(36) NULL;

-- Update meeting_messages table if it exists
ALTER TABLE meeting_messages
  MODIFY COLUMN id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  MODIFY COLUMN sender_id VARCHAR(36) NULL;

-- Update meeting_recordings table if it exists
ALTER TABLE meeting_recordings
  MODIFY COLUMN id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT;

-- 6. Verify the setup
SELECT 'BugMeet tables created/updated successfully' as status;
