-- Bug doubt-clearing threads (text + voice) with replies.
-- Next after 076_project_timeline_history.sql

CREATE TABLE IF NOT EXISTS `bug_doubts` (
  `id` varchar(36) NOT NULL,
  `bug_id` varchar(36) NOT NULL,
  `asked_by` varchar(36) NOT NULL,
  `body` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bug_doubts_bug_created` (`bug_id`, `created_at`),
  KEY `idx_bug_doubts_asked_by` (`asked_by`),
  CONSTRAINT `bug_doubts_bug`
    FOREIGN KEY (`bug_id`) REFERENCES `bugs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bug_doubts_asked_by`
    FOREIGN KEY (`asked_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `bug_doubt_replies` (
  `id` varchar(36) NOT NULL,
  `doubt_id` varchar(36) NOT NULL,
  `user_id` varchar(36) NOT NULL,
  `body` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bug_doubt_replies_doubt_created` (`doubt_id`, `created_at`),
  KEY `idx_bug_doubt_replies_user` (`user_id`),
  CONSTRAINT `bug_doubt_replies_doubt`
    FOREIGN KEY (`doubt_id`) REFERENCES `bug_doubts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bug_doubt_replies_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `bug_doubt_attachments` (
  `id` varchar(36) NOT NULL,
  `doubt_id` varchar(36) NOT NULL,
  `reply_id` varchar(36) DEFAULT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_type` varchar(100) DEFAULT NULL,
  `duration` int(11) DEFAULT NULL,
  `uploaded_by` varchar(36) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bug_doubt_att_doubt` (`doubt_id`),
  KEY `idx_bug_doubt_att_reply` (`reply_id`),
  CONSTRAINT `bug_doubt_att_doubt`
    FOREIGN KEY (`doubt_id`) REFERENCES `bug_doubts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bug_doubt_att_reply`
    FOREIGN KEY (`reply_id`) REFERENCES `bug_doubt_replies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE notifications
  MODIFY COLUMN type ENUM(
    'new_bug', 'status_change', 'new_update', 'bug_created', 'bug_fixed',
    'update_created', 'task_created', 'task_assigned', 'task_completed',
    'meet_created', 'meeting_reminder', 'doc_created', 'sheet_created',
    'project_created', 'work_check_in', 'work_break', 'work_update',
    'feedback', 'overtime', 'message', 'user_registered', 'info', 'leave',
    'project_deadline_reminder', 'bug_doubt', 'bug_doubt_reply'
  ) NOT NULL;
