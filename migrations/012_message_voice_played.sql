-- Track when recipients play voice messages (WhatsApp-style "played" receipts)
CREATE TABLE IF NOT EXISTS `message_voice_played` (
  `message_id` varchar(36) NOT NULL,
  `user_id` varchar(36) NOT NULL,
  `played_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`message_id`, `user_id`),
  KEY `idx_message_voice_played_user` (`user_id`),
  KEY `idx_message_voice_played_at` (`played_at`),
  CONSTRAINT `message_voice_played_message_fk` FOREIGN KEY (`message_id`) REFERENCES `chat_messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `message_voice_played_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
