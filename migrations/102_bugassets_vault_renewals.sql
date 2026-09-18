-- =============================================================================
-- BugRicer Migration 102 — BugAssets vault + renewal ledger (safe to re-run)
-- =============================================================================

SET @db := DATABASE();

CREATE TABLE IF NOT EXISTS `assets_vault_secrets` (
  `id` VARCHAR(36) NOT NULL,
  `entity_type` ENUM('server','hosting','vercel','domain','email','hardware') NOT NULL,
  `entity_id` VARCHAR(36) NOT NULL,
  `kind` ENUM('password','ssh_private_key','api_token','recovery_code','other') NOT NULL,
  `label` VARCHAR(120) NOT NULL,
  `ciphertext` VARBINARY(8192) NOT NULL,
  `nonce` VARBINARY(12) NOT NULL,
  `dek_wrapped` VARBINARY(512) NOT NULL,
  `wrap_nonce` VARBINARY(12) NOT NULL,
  `key_version` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `fingerprint` CHAR(16) NULL DEFAULT NULL,
  `created_by` VARCHAR(36) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `rotated_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_vault_entity` (`entity_type`, `entity_id`),
  KEY `idx_vault_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `assets_vault_access_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `secret_id` VARCHAR(36) NOT NULL,
  `user_id` VARCHAR(36) NOT NULL,
  `action` ENUM('reveal','rotate','create','delete') NOT NULL,
  `ip` VARCHAR(45) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_vault_log_secret` (`secret_id`, `created_at`),
  KEY `idx_vault_log_user` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `assets_renewal_alerts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entity_type` ENUM('domain','ssl','server','hosting','vercel','hardware') NOT NULL,
  `entity_id` VARCHAR(36) NOT NULL,
  `reminder_offset` INT NOT NULL,
  `expiry_date` DATE NOT NULL,
  `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `email_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `whatsapp_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `push_ok` TINYINT(1) NOT NULL DEFAULT 0,
  `status` ENUM('sent','partial','failed') NOT NULL DEFAULT 'sent',
  `error_summary` TEXT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_assets_renewal` (`entity_type`, `entity_id`, `reminder_offset`, `expiry_date`),
  KEY `idx_assets_renewal_sent` (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Append asset_renewal to whatever notifications.type ENUM currently exists.
SET @enum := (
  SELECT COLUMN_TYPE FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'type'
);
SET @sql := IF(
  @enum IS NULL OR LOCATE('asset_renewal', @enum) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE `notifications` MODIFY COLUMN `type` ', REPLACE(@enum, ')', ',''asset_renewal'')'), ' NOT NULL')
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
