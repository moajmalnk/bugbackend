-- Align meeting tables with users.id VARCHAR(36)
ALTER TABLE meetings
  MODIFY created_by VARCHAR(36) NOT NULL;

ALTER TABLE meeting_participants
  MODIFY user_id VARCHAR(36) NULL;

ALTER TABLE meeting_messages
  MODIFY sender_id VARCHAR(36) NULL;

-- Add/ensure indexes still usable
ALTER TABLE meetings
  DROP INDEX idx_meetings_creator,
  ADD INDEX idx_meetings_creator (created_by);

ALTER TABLE meeting_participants
  DROP INDEX idx_participants_user,
  ADD INDEX idx_participants_user (user_id);

