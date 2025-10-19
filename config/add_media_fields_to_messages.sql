-- Migration: Add WhatsApp Media Fields to chat_messages table
-- Date: 2025-10-20
-- Description: Adds support for image, video, document, and other media types in messages

USE `u262074081_bugfixer_db`;

-- 1. Update message_type enum to include media types
ALTER TABLE `chat_messages` 
MODIFY COLUMN `message_type` ENUM('text', 'voice', 'reply', 'image', 'video', 'document', 'audio', 'location', 'contact') 
NOT NULL DEFAULT 'text';

-- 2. Add media-related columns
ALTER TABLE `chat_messages`
ADD COLUMN `media_type` ENUM('image', 'video', 'document', 'audio') DEFAULT NULL AFTER `reply_to_message_id`,
ADD COLUMN `media_file_path` VARCHAR(500) DEFAULT NULL AFTER `media_type`,
ADD COLUMN `media_file_name` VARCHAR(255) DEFAULT NULL AFTER `media_file_path`,
ADD COLUMN `media_file_size` BIGINT DEFAULT NULL AFTER `media_file_name`,
ADD COLUMN `media_thumbnail` VARCHAR(500) DEFAULT NULL AFTER `media_file_size`,
ADD COLUMN `media_duration` INT DEFAULT NULL COMMENT 'Duration in seconds for video/audio' AFTER `media_thumbnail`;

-- 3. Add WhatsApp-specific message features
ALTER TABLE `chat_messages`
ADD COLUMN `is_starred` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_pinned`,
ADD COLUMN `starred_at` TIMESTAMP NULL DEFAULT NULL AFTER `is_starred`,
ADD COLUMN `starred_by` VARCHAR(36) DEFAULT NULL AFTER `starred_at`,
ADD COLUMN `is_forwarded` TINYINT(1) NOT NULL DEFAULT 0 AFTER `starred_by`,
ADD COLUMN `original_message_id` VARCHAR(36) DEFAULT NULL AFTER `is_forwarded`,
ADD COLUMN `is_edited` TINYINT(1) NOT NULL DEFAULT 0 AFTER `original_message_id`,
ADD COLUMN `edited_at` TIMESTAMP NULL DEFAULT NULL AFTER `is_edited`,
ADD COLUMN `delivery_status` ENUM('sent', 'delivered', 'read', 'failed') DEFAULT 'sent' AFTER `edited_at`;

-- 4. Add indexes for better performance
CREATE INDEX `idx_chat_messages_is_starred` ON `chat_messages`(`is_starred`);
CREATE INDEX `idx_chat_messages_media_type` ON `chat_messages`(`media_type`);
CREATE INDEX `idx_chat_messages_delivery_status` ON `chat_messages`(`delivery_status`);
CREATE INDEX `idx_chat_messages_is_edited` ON `chat_messages`(`is_edited`);

-- 5. Add foreign key for starred_by
ALTER TABLE `chat_messages`
ADD CONSTRAINT `chat_messages_ibfk_starred_by` 
FOREIGN KEY (`starred_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;

-- 6. Update pinned_by foreign key name if it doesn't exist
-- (Skip if already exists - this is just for consistency)

SELECT 'Migration completed: Media fields added to chat_messages table' AS status;

