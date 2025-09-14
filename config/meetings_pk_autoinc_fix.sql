-- Ensure primary keys and AUTO_INCREMENT exist on meeting tables

-- meetings
ALTER TABLE meetings
  MODIFY id BIGINT(20) UNSIGNED NOT NULL;
-- drop and recreate PK to avoid #1068 multiple PK defined
ALTER TABLE meetings
  DROP PRIMARY KEY;
ALTER TABLE meetings
  MODIFY id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  ADD PRIMARY KEY (id);
-- ensure unique on meeting_code
ALTER TABLE meetings
  DROP INDEX meeting_code,
  ADD UNIQUE KEY meeting_code (meeting_code);

-- meeting_messages
ALTER TABLE meeting_messages
  MODIFY id BIGINT(20) UNSIGNED NOT NULL;
ALTER TABLE meeting_messages
  DROP PRIMARY KEY;
ALTER TABLE meeting_messages
  MODIFY id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  ADD PRIMARY KEY (id);

-- meeting_participants
ALTER TABLE meeting_participants
  MODIFY id BIGINT(20) UNSIGNED NOT NULL;
ALTER TABLE meeting_participants
  DROP PRIMARY KEY;
ALTER TABLE meeting_participants
  MODIFY id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  ADD PRIMARY KEY (id);

-- meeting_recordings
ALTER TABLE meeting_recordings
  MODIFY id BIGINT(20) UNSIGNED NOT NULL;
ALTER TABLE meeting_recordings
  DROP PRIMARY KEY;
ALTER TABLE meeting_recordings
  MODIFY id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  ADD PRIMARY KEY (id);

