-- =============================================================================
-- BugRicer Migration 043 — Deadline reminder channel telemetry + notification type
-- =============================================================================
-- Adds delivery counters/status on project_deadline_reminders and a dedicated
-- notifications.type value so deadline alerts no longer reuse meeting_reminder.
-- Safe to re-run.
-- =============================================================================

SET @db := DATABASE();

-- Ensure reminder table exists (idempotent; matches runtime ensure)
CREATE TABLE IF NOT EXISTS `project_deadline_reminders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` VARCHAR(36) NOT NULL,
  `milestone_key` VARCHAR(64) NOT NULL,
  `reminder_offset` INT NOT NULL COMMENT 'Days relative to milestone: 7/3/1 before, 0 due today, -1 overdue',
  `milestone_date` DATE NOT NULL,
  `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_project_deadline_reminder` (`project_id`, `milestone_key`, `reminder_offset`, `milestone_date`),
  KEY `idx_deadline_reminders_project` (`project_id`),
  KEY `idx_deadline_reminders_sent` (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- email_count
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'project_deadline_reminders' AND COLUMN_NAME = 'email_count'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `project_deadline_reminders` ADD COLUMN `email_count` INT UNSIGNED NOT NULL DEFAULT 0',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- whatsapp_count
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'project_deadline_reminders' AND COLUMN_NAME = 'whatsapp_count'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `project_deadline_reminders` ADD COLUMN `whatsapp_count` INT UNSIGNED NOT NULL DEFAULT 0',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- push_ok
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'project_deadline_reminders' AND COLUMN_NAME = 'push_ok'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `project_deadline_reminders` ADD COLUMN `push_ok` TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- status
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'project_deadline_reminders' AND COLUMN_NAME = 'status'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `project_deadline_reminders` ADD COLUMN `status` ENUM(''sent'', ''partial'', ''failed'') NOT NULL DEFAULT ''sent''',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- error_summary
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'project_deadline_reminders' AND COLUMN_NAME = 'error_summary'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `project_deadline_reminders` ADD COLUMN `error_summary` TEXT NULL',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Normalize invalid notification types before ENUM expand
UPDATE notifications
SET type = 'info'
WHERE type IS NULL
   OR type NOT IN (
     'new_bug', 'status_change', 'new_update', 'bug_created', 'bug_fixed',
     'update_created', 'task_created', 'task_assigned', 'task_completed',
     'meet_created', 'meeting_reminder', 'doc_created', 'sheet_created',
     'project_created', 'work_check_in', 'work_break', 'work_update',
     'feedback', 'overtime', 'message', 'user_registered', 'info', 'leave',
     'project_deadline_reminder'
   );

ALTER TABLE notifications
  MODIFY COLUMN type ENUM(
    'new_bug', 'status_change', 'new_update', 'bug_created', 'bug_fixed',
    'update_created', 'task_created', 'task_assigned', 'task_completed',
    'meet_created', 'meeting_reminder', 'doc_created', 'sheet_created',
    'project_created', 'work_check_in', 'work_break', 'work_update',
    'feedback', 'overtime', 'message', 'user_registered', 'info', 'leave',
    'project_deadline_reminder'
  ) NOT NULL;
