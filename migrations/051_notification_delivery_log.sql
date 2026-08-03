-- Delivery log for email + WhatsApp sends (admin coverage). Safe to re-run.

CREATE TABLE IF NOT EXISTS `notification_delivery_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `channel` ENUM('email','whatsapp') NOT NULL,
  `status` ENUM('sent','failed') NOT NULL,
  `user_id` VARCHAR(64) NULL DEFAULT NULL,
  `recipient` VARCHAR(255) NOT NULL,
  `subject` VARCHAR(255) NULL DEFAULT NULL,
  `error_message` VARCHAR(500) NULL DEFAULT NULL,
  `meta` TEXT NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ndl_channel_status_created` (`channel`, `status`, `created_at`),
  KEY `idx_ndl_user_created` (`user_id`, `created_at`),
  KEY `idx_ndl_recipient` (`recipient`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
