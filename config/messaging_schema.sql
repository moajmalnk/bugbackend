-- Messaging System Database Schema
-- This file contains the database tables for the WhatsApp/Telegram style messaging system

-- Chat Groups Table
CREATE TABLE `chat_groups` (
  `id` varchar(36) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `project_id` varchar(36) NOT NULL,
  `created_by` varchar(36) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_chat_groups_project_id` (`project_id`),
  KEY `idx_chat_groups_created_by` (`created_by`),
  KEY `idx_chat_groups_is_active` (`is_active`),
  CONSTRAINT `chat_groups_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_groups_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Chat Messages Table
CREATE TABLE `chat_messages` (
  `id` varchar(36) NOT NULL,
  `group_id` varchar(36) NOT NULL,
  `sender_id` varchar(36) NOT NULL,
  `message_type` enum('text','voice','reply') NOT NULL DEFAULT 'text',
  `content` text DEFAULT NULL,
  `voice_file_path` varchar(500) DEFAULT NULL,
  `voice_duration` int(11) DEFAULT NULL COMMENT 'Duration in seconds',
  `reply_to_message_id` varchar(36) DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `is_pinned` tinyint(1) NOT NULL DEFAULT 0,
  `pinned_at` timestamp NULL DEFAULT NULL,
  `pinned_by` varchar(36) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_chat_messages_group_id` (`group_id`),
  KEY `idx_chat_messages_sender_id` (`sender_id`),
  KEY `idx_chat_messages_created_at` (`created_at`),
  KEY `idx_chat_messages_reply_to` (`reply_to_message_id`),
  KEY `idx_chat_messages_is_deleted` (`is_deleted`),
  KEY `idx_chat_messages_is_pinned` (`is_pinned`),
  CONSTRAINT `chat_messages_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `chat_groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_messages_ibfk_2` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`),
  CONSTRAINT `chat_messages_ibfk_3` FOREIGN KEY (`reply_to_message_id`) REFERENCES `chat_messages` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chat_messages_ibfk_4` FOREIGN KEY (`pinned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Chat Group Members Table
CREATE TABLE `chat_group_members` (
  `group_id` varchar(36) NOT NULL,
  `user_id` varchar(36) NOT NULL,
  `joined_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_read_at` timestamp NULL DEFAULT NULL,
  `is_muted` tinyint(1) NOT NULL DEFAULT 0,
  `muted_until` timestamp NULL DEFAULT NULL,
  `show_read_receipts` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`group_id`, `user_id`),
  KEY `idx_chat_group_members_user_id` (`user_id`),
  KEY `idx_chat_group_members_group_id` (`group_id`),
  CONSTRAINT `chat_group_members_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `chat_groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_group_members_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Message Read Status Table (for tracking who has read which messages)
CREATE TABLE `message_read_status` (
  `message_id` varchar(36) NOT NULL,
  `user_id` varchar(36) NOT NULL,
  `read_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`message_id`, `user_id`),
  KEY `idx_message_read_status_user_id` (`user_id`),
  KEY `idx_message_read_status_read_at` (`read_at`),
  CONSTRAINT `message_read_status_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `chat_messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `message_read_status_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Typing Indicators Table (for real-time typing status)
CREATE TABLE `typing_indicators` (
  `id` varchar(36) NOT NULL,
  `group_id` varchar(36) NOT NULL,
  `user_id` varchar(36) NOT NULL,
  `is_typing` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_typing_indicators_group_id` (`group_id`),
  KEY `idx_typing_indicators_user_id` (`user_id`),
  KEY `idx_typing_indicators_expires_at` (`expires_at`),
  CONSTRAINT `typing_indicators_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `chat_groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `typing_indicators_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Emoji Reactions Table
CREATE TABLE `message_reactions` (
  `id` varchar(36) NOT NULL,
  `message_id` varchar(36) NOT NULL,
  `user_id` varchar(36) NOT NULL,
  `emoji` varchar(10) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_message_emoji` (`message_id`, `user_id`, `emoji`),
  KEY `idx_message_reactions_message_id` (`message_id`),
  KEY `idx_message_reactions_user_id` (`user_id`),
  CONSTRAINT `message_reactions_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `chat_messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `message_reactions_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Message Mentions Table (for @mentions)
CREATE TABLE `message_mentions` (
  `id` varchar(36) NOT NULL,
  `message_id` varchar(36) NOT NULL,
  `mentioned_user_id` varchar(36) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_message_mentions_message_id` (`message_id`),
  KEY `idx_message_mentions_user_id` (`mentioned_user_id`),
  CONSTRAINT `message_mentions_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `chat_messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `message_mentions_ibfk_2` FOREIGN KEY (`mentioned_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Indexes for better performance
CREATE INDEX `idx_chat_messages_group_created` ON `chat_messages` (`group_id`, `created_at`);
CREATE INDEX `idx_chat_messages_sender_created` ON `chat_messages` (`sender_id`, `created_at`);
CREATE INDEX `idx_chat_groups_project_active` ON `chat_groups` (`project_id`, `is_active`);
CREATE INDEX `idx_chat_group_members_user_group` ON `chat_group_members` (`user_id`, `group_id`);
CREATE INDEX `idx_message_reactions_emoji` ON `message_reactions` (`emoji`);

-- Triggers for automatic cleanup
DELIMITER $$

-- Clean up expired typing indicators
CREATE EVENT IF NOT EXISTS `cleanup_typing_indicators`
ON SCHEDULE EVERY 1 MINUTE
DO
BEGIN
  DELETE FROM typing_indicators WHERE expires_at < NOW();
END$$

-- Auto-delete messages older than 1 hour for non-admin users
CREATE TRIGGER `chat_messages_auto_delete_check` 
BEFORE UPDATE ON `chat_messages`
FOR EACH ROW
BEGIN
  -- Only allow deletion if message is less than 1 hour old OR user is admin
  IF NEW.is_deleted = 1 AND OLD.is_deleted = 0 THEN
    -- This will be handled in the application logic
    -- The trigger just ensures we track the deletion time
    SET NEW.deleted_at = CURRENT_TIMESTAMP;
  END IF;
END$$

DELIMITER ; 






