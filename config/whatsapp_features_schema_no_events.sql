-- WhatsApp-like Features Database Schema Enhancements
-- Run this after the base messaging_schema.sql
-- This version EXCLUDES events (use if Event Scheduler is disabled)

-- Add new columns to chat_messages table for media and additional features
ALTER TABLE `chat_messages` 
  ADD COLUMN `media_type` ENUM('image', 'video', 'document', 'audio') NULL DEFAULT NULL AFTER `message_type`,
  ADD COLUMN `media_file_path` VARCHAR(500) NULL DEFAULT NULL AFTER `media_type`,
  ADD COLUMN `media_file_name` VARCHAR(255) NULL DEFAULT NULL AFTER `media_file_path`,
  ADD COLUMN `media_file_size` INT NULL DEFAULT NULL COMMENT 'File size in bytes' AFTER `media_file_name`,
  ADD COLUMN `media_thumbnail` VARCHAR(500) NULL DEFAULT NULL AFTER `media_file_size`,
  ADD COLUMN `media_duration` INT NULL DEFAULT NULL COMMENT 'For video/audio in seconds' AFTER `media_thumbnail`,
  ADD COLUMN `is_starred` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_pinned`,
  ADD COLUMN `starred_at` TIMESTAMP NULL DEFAULT NULL AFTER `is_starred`,
  ADD COLUMN `starred_by` VARCHAR(36) NULL DEFAULT NULL AFTER `starred_at`,
  ADD COLUMN `is_forwarded` TINYINT(1) NOT NULL DEFAULT 0 AFTER `starred_by`,
  ADD COLUMN `original_message_id` VARCHAR(36) NULL DEFAULT NULL AFTER `is_forwarded`,
  ADD COLUMN `is_edited` TINYINT(1) NOT NULL DEFAULT 0 AFTER `original_message_id`,
  ADD COLUMN `edited_at` TIMESTAMP NULL DEFAULT NULL AFTER `is_edited`,
  ADD COLUMN `delivery_status` ENUM('sent', 'delivered', 'read', 'failed') NOT NULL DEFAULT 'sent' AFTER `edited_at`;

-- Update message_type to include more types
ALTER TABLE `chat_messages` 
  MODIFY COLUMN `message_type` ENUM('text', 'voice', 'reply', 'image', 'video', 'document', 'audio', 'location', 'contact') NOT NULL DEFAULT 'text';

-- Add indexes for new columns
ALTER TABLE `chat_messages`
  ADD INDEX `idx_chat_messages_is_starred` (`is_starred`),
  ADD INDEX `idx_chat_messages_is_forwarded` (`is_forwarded`),
  ADD INDEX `idx_chat_messages_delivery_status` (`delivery_status`),
  ADD INDEX `idx_chat_messages_media_type` (`media_type`);

-- Add new columns to users table for online status and profile
ALTER TABLE `users` 
  ADD COLUMN `is_online` TINYINT(1) NOT NULL DEFAULT 0 AFTER `role`,
  ADD COLUMN `last_seen` TIMESTAMP NULL DEFAULT NULL AFTER `is_online`,
  ADD COLUMN `profile_picture` VARCHAR(500) NULL DEFAULT NULL AFTER `last_seen`,
  ADD COLUMN `status_message` VARCHAR(255) NULL DEFAULT NULL COMMENT 'WhatsApp-like status text' AFTER `profile_picture`,
  ADD COLUMN `show_online_status` TINYINT(1) NOT NULL DEFAULT 1 AFTER `status_message`,
  ADD COLUMN `show_last_seen` TINYINT(1) NOT NULL DEFAULT 1 AFTER `show_online_status`;

-- Add indexes for user status
ALTER TABLE `users`
  ADD INDEX `idx_users_is_online` (`is_online`),
  ADD INDEX `idx_users_last_seen` (`last_seen`);

-- Add new columns to chat_groups for group pictures and settings
ALTER TABLE `chat_groups` 
  ADD COLUMN `group_picture` VARCHAR(500) NULL DEFAULT NULL AFTER `description`,
  ADD COLUMN `is_archived` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_active`,
  ADD COLUMN `archived_at` TIMESTAMP NULL DEFAULT NULL AFTER `is_archived`;

-- Add index for archived groups
ALTER TABLE `chat_groups`
  ADD INDEX `idx_chat_groups_is_archived` (`is_archived`);

-- Create Starred Messages Table (for personal starred messages)
CREATE TABLE IF NOT EXISTS `starred_messages` (
  `id` VARCHAR(36) NOT NULL,
  `message_id` VARCHAR(36) NOT NULL,
  `user_id` VARCHAR(36) NOT NULL,
  `group_id` VARCHAR(36) NOT NULL,
  `starred_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_message_starred` (`message_id`, `user_id`),
  KEY `idx_starred_messages_user` (`user_id`),
  KEY `idx_starred_messages_message` (`message_id`),
  KEY `idx_starred_messages_group` (`group_id`),
  CONSTRAINT `starred_messages_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `chat_messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `starred_messages_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `starred_messages_ibfk_3` FOREIGN KEY (`group_id`) REFERENCES `chat_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Create Message Delivery Status Table (detailed tracking)
CREATE TABLE IF NOT EXISTS `message_delivery_status` (
  `id` VARCHAR(36) NOT NULL,
  `message_id` VARCHAR(36) NOT NULL,
  `user_id` VARCHAR(36) NOT NULL,
  `status` ENUM('delivered', 'read') NOT NULL,
  `timestamp` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_message_status` (`message_id`, `user_id`, `status`),
  KEY `idx_delivery_status_message` (`message_id`),
  KEY `idx_delivery_status_user` (`user_id`),
  KEY `idx_delivery_status_timestamp` (`timestamp`),
  CONSTRAINT `message_delivery_status_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `chat_messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `message_delivery_status_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Create User Status/Stories Table
CREATE TABLE IF NOT EXISTS `user_status` (
  `id` VARCHAR(36) NOT NULL,
  `user_id` VARCHAR(36) NOT NULL,
  `media_type` ENUM('text', 'image', 'video') NOT NULL DEFAULT 'text',
  `media_url` VARCHAR(500) NULL DEFAULT NULL,
  `text_content` TEXT NULL DEFAULT NULL,
  `background_color` VARCHAR(7) NULL DEFAULT NULL COMMENT 'Hex color for text status',
  `expires_at` TIMESTAMP NOT NULL COMMENT '24 hours from creation',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_status_user` (`user_id`),
  KEY `idx_user_status_expires` (`expires_at`),
  KEY `idx_user_status_created` (`created_at`),
  CONSTRAINT `user_status_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Create Status Views Table (who viewed the status)
CREATE TABLE IF NOT EXISTS `status_views` (
  `id` VARCHAR(36) NOT NULL,
  `status_id` VARCHAR(36) NOT NULL,
  `viewer_id` VARCHAR(36) NOT NULL,
  `viewed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_status_viewer` (`status_id`, `viewer_id`),
  KEY `idx_status_views_status` (`status_id`),
  KEY `idx_status_views_viewer` (`viewer_id`),
  CONSTRAINT `status_views_ibfk_1` FOREIGN KEY (`status_id`) REFERENCES `user_status` (`id`) ON DELETE CASCADE,
  CONSTRAINT `status_views_ibfk_2` FOREIGN KEY (`viewer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Create Broadcast Lists Table
CREATE TABLE IF NOT EXISTS `broadcast_lists` (
  `id` VARCHAR(36) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `created_by` VARCHAR(36) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_broadcast_lists_creator` (`created_by`),
  CONSTRAINT `broadcast_lists_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Create Broadcast List Recipients Table
CREATE TABLE IF NOT EXISTS `broadcast_recipients` (
  `broadcast_id` VARCHAR(36) NOT NULL,
  `user_id` VARCHAR(36) NOT NULL,
  `added_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`broadcast_id`, `user_id`),
  KEY `idx_broadcast_recipients_user` (`user_id`),
  CONSTRAINT `broadcast_recipients_ibfk_1` FOREIGN KEY (`broadcast_id`) REFERENCES `broadcast_lists` (`id`) ON DELETE CASCADE,
  CONSTRAINT `broadcast_recipients_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Create Disappearing Messages Settings Table
CREATE TABLE IF NOT EXISTS `disappearing_messages_settings` (
  `group_id` VARCHAR(36) NOT NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `duration_seconds` INT NOT NULL DEFAULT 604800 COMMENT '7 days default',
  `enabled_by` VARCHAR(36) NULL DEFAULT NULL,
  `enabled_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`group_id`),
  CONSTRAINT `disappearing_messages_settings_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `chat_groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `disappearing_messages_settings_ibfk_2` FOREIGN KEY (`enabled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Create Blocked Users Table
CREATE TABLE IF NOT EXISTS `blocked_users` (
  `id` VARCHAR(36) NOT NULL,
  `blocker_id` VARCHAR(36) NOT NULL COMMENT 'User who blocked',
  `blocked_id` VARCHAR(36) NOT NULL COMMENT 'User who was blocked',
  `blocked_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_blocker_blocked` (`blocker_id`, `blocked_id`),
  KEY `idx_blocked_users_blocker` (`blocker_id`),
  KEY `idx_blocked_users_blocked` (`blocked_id`),
  CONSTRAINT `blocked_users_ibfk_1` FOREIGN KEY (`blocker_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `blocked_users_ibfk_2` FOREIGN KEY (`blocked_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Create Group Admin Permissions Table
CREATE TABLE IF NOT EXISTS `group_admins` (
  `group_id` VARCHAR(36) NOT NULL,
  `user_id` VARCHAR(36) NOT NULL,
  `granted_by` VARCHAR(36) NOT NULL,
  `granted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`group_id`, `user_id`),
  KEY `idx_group_admins_user` (`user_id`),
  CONSTRAINT `group_admins_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `chat_groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `group_admins_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `group_admins_ibfk_3` FOREIGN KEY (`granted_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Create Voice/Video Call Logs Table
CREATE TABLE IF NOT EXISTS `call_logs` (
  `id` VARCHAR(36) NOT NULL,
  `call_type` ENUM('voice', 'video') NOT NULL,
  `caller_id` VARCHAR(36) NOT NULL,
  `group_id` VARCHAR(36) NULL DEFAULT NULL COMMENT 'For group calls',
  `duration_seconds` INT NULL DEFAULT NULL,
  `status` ENUM('missed', 'declined', 'completed', 'failed') NOT NULL DEFAULT 'completed',
  `started_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ended_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_call_logs_caller` (`caller_id`),
  KEY `idx_call_logs_group` (`group_id`),
  KEY `idx_call_logs_started` (`started_at`),
  CONSTRAINT `call_logs_ibfk_1` FOREIGN KEY (`caller_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `call_logs_ibfk_2` FOREIGN KEY (`group_id`) REFERENCES `chat_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Create Call Participants Table
CREATE TABLE IF NOT EXISTS `call_participants` (
  `call_id` VARCHAR(36) NOT NULL,
  `user_id` VARCHAR(36) NOT NULL,
  `joined_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `left_at` TIMESTAMP NULL DEFAULT NULL,
  `status` ENUM('calling', 'joined', 'declined', 'missed') NOT NULL DEFAULT 'calling',
  PRIMARY KEY (`call_id`, `user_id`),
  KEY `idx_call_participants_user` (`user_id`),
  CONSTRAINT `call_participants_ibfk_1` FOREIGN KEY (`call_id`) REFERENCES `call_logs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `call_participants_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Create Polls Table
CREATE TABLE IF NOT EXISTS `message_polls` (
  `id` VARCHAR(36) NOT NULL,
  `message_id` VARCHAR(36) NOT NULL,
  `question` TEXT NOT NULL,
  `created_by` VARCHAR(36) NOT NULL,
  `expires_at` TIMESTAMP NULL DEFAULT NULL,
  `allow_multiple_answers` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_message_polls_message` (`message_id`),
  KEY `idx_message_polls_creator` (`created_by`),
  CONSTRAINT `message_polls_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `chat_messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `message_polls_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Create Poll Options Table
CREATE TABLE IF NOT EXISTS `poll_options` (
  `id` VARCHAR(36) NOT NULL,
  `poll_id` VARCHAR(36) NOT NULL,
  `option_text` VARCHAR(255) NOT NULL,
  `option_order` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_poll_options_poll` (`poll_id`),
  CONSTRAINT `poll_options_ibfk_1` FOREIGN KEY (`poll_id`) REFERENCES `message_polls` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Create Poll Votes Table
CREATE TABLE IF NOT EXISTS `poll_votes` (
  `id` VARCHAR(36) NOT NULL,
  `poll_id` VARCHAR(36) NOT NULL,
  `option_id` VARCHAR(36) NOT NULL,
  `user_id` VARCHAR(36) NOT NULL,
  `voted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_poll_votes_poll` (`poll_id`),
  KEY `idx_poll_votes_option` (`option_id`),
  KEY `idx_poll_votes_user` (`user_id`),
  CONSTRAINT `poll_votes_ibfk_1` FOREIGN KEY (`poll_id`) REFERENCES `message_polls` (`id`) ON DELETE CASCADE,
  CONSTRAINT `poll_votes_ibfk_2` FOREIGN KEY (`option_id`) REFERENCES `poll_options` (`id`) ON DELETE CASCADE,
  CONSTRAINT `poll_votes_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Full-text search index for messages (for search functionality)
ALTER TABLE `chat_messages` 
  ADD FULLTEXT INDEX `ft_chat_messages_content` (`content`);

-- Create view for unread message counts
CREATE OR REPLACE VIEW `unread_message_counts` AS
SELECT 
  cgm.user_id,
  cgm.group_id,
  cg.name as group_name,
  COUNT(cm.id) as unread_count
FROM chat_group_members cgm
JOIN chat_groups cg ON cgm.group_id = cg.id
LEFT JOIN chat_messages cm ON cgm.group_id = cm.group_id
  AND cm.created_at > COALESCE(cgm.last_read_at, '1970-01-01')
  AND cm.sender_id != cgm.user_id
  AND cm.is_deleted = 0
WHERE cg.is_active = 1
GROUP BY cgm.user_id, cgm.group_id, cg.name;

-- Additional indexes for performance optimization
CREATE INDEX `idx_chat_messages_content_search` ON `chat_messages` (`content`(100));
CREATE INDEX `idx_users_username_search` ON `users` (`username`);
CREATE INDEX `idx_chat_groups_name_search` ON `chat_groups` (`name`);

-- NOTE: Automated cleanup events are in a separate file (whatsapp_features_schema.sql)
-- To use automated cleanup, enable Event Scheduler first using enable_event_scheduler.sql

