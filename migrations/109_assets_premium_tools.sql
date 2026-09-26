-- =============================================================================
-- BugRicer Migration 109 — CODO Premium Tools (SaaS inventory)
-- Safe to re-run. Adds assets_tools + assets_tool_seats; extends vault/renewals ENUMs.
--
-- Why utf8mb4_general_ci: matches assets_* (101) and users.id so FKs form correctly.
-- errno 150 on seats = collation mismatch (unicode_ci vs general_ci).
-- =============================================================================

SET @db := DATABASE();
SET NAMES utf8mb4 COLLATE utf8mb4_general_ci;
SET collation_connection = 'utf8mb4_general_ci';

CREATE TABLE IF NOT EXISTS `assets_tools` (
  `id` VARCHAR(36) NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `slug` VARCHAR(160) NOT NULL,
  `category` ENUM('ai','design','devops','productivity','marketing','communication','other')
    NOT NULL DEFAULT 'other',
  `vendor` VARCHAR(100) NULL DEFAULT NULL,
  `plan_name` VARCHAR(120) NULL DEFAULT NULL,
  `login_url` VARCHAR(500) NULL DEFAULT NULL,
  `account_email` VARCHAR(255) NULL DEFAULT NULL,
  `seats_total` INT UNSIGNED NULL DEFAULT NULL,
  `vendor_account` VARCHAR(150) NULL DEFAULT NULL,
  `billing_cycle` ENUM('monthly','yearly','biennial','one_time') NOT NULL DEFAULT 'monthly',
  `currency` CHAR(3) NOT NULL DEFAULT 'INR',
  `vendor_cost` DECIMAL(12,2) NULL DEFAULT NULL,
  `client_charge` DECIMAL(12,2) NULL DEFAULT NULL,
  `margin_amount` DECIMAL(12,2) GENERATED ALWAYS AS (IFNULL(`client_charge`,0) - IFNULL(`vendor_cost`,0)) STORED,
  `invoice_status` ENUM('not_billed','invoiced','paid','waived') NOT NULL DEFAULT 'not_billed',
  `auto_renew` TINYINT(1) NOT NULL DEFAULT 1,
  `purchased_at` DATE NULL DEFAULT NULL,
  `expires_at` DATE NULL DEFAULT NULL,
  `status` ENUM('active','trial','expired','cancelled','paused') NOT NULL DEFAULT 'active',
  `notes` TEXT NULL DEFAULT NULL,
  `created_by` VARCHAR(36) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  `deleted_by` VARCHAR(36) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_assets_tools_slug` (`slug`),
  KEY `idx_tools_status_expires` (`status`, `expires_at`),
  KEY `idx_tools_category_created` (`category`, `created_at`),
  KEY `idx_tools_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Heal partial run: tools may exist as unicode_ci from the failed attempt
SET @tools_collation := (
  SELECT TABLE_COLLATION FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'assets_tools'
);
SET @sql := IF(
  @tools_collation IS NOT NULL AND @tools_collation <> 'utf8mb4_general_ci',
  'ALTER TABLE `assets_tools` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
  'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS `assets_tool_seats` (
  `id` VARCHAR(36) NOT NULL,
  `tool_id` VARCHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `user_id` VARCHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `external_name` VARCHAR(120) NULL DEFAULT NULL,
  `external_email` VARCHAR(255) NULL DEFAULT NULL,
  `seat_role` VARCHAR(80) NULL DEFAULT NULL,
  `notes` VARCHAR(500) NULL DEFAULT NULL,
  `assigned_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tool_seat_user` (`tool_id`, `user_id`),
  KEY `idx_tool_seats_tool` (`tool_id`),
  KEY `idx_tool_seats_user` (`user_id`),
  CONSTRAINT `fk_tool_seats_tool` FOREIGN KEY (`tool_id`) REFERENCES `assets_tools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tool_seats_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Extend vault entity_type with tool
SET @vault_enum := (
  SELECT COLUMN_TYPE FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'assets_vault_secrets' AND COLUMN_NAME = 'entity_type'
);
SET @sql := IF(
  @vault_enum IS NOT NULL AND LOCATE('''tool''', @vault_enum) = 0,
  'ALTER TABLE `assets_vault_secrets` MODIFY `entity_type` ENUM(''server'',''hosting'',''vercel'',''domain'',''email'',''hardware'',''tool'') NOT NULL',
  'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Extend renewal alerts entity_type with tool
SET @ren_enum := (
  SELECT COLUMN_TYPE FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'assets_renewal_alerts' AND COLUMN_NAME = 'entity_type'
);
SET @sql := IF(
  @ren_enum IS NOT NULL AND LOCATE('''tool''', @ren_enum) = 0,
  'ALTER TABLE `assets_renewal_alerts` MODIFY `entity_type` ENUM(''domain'',''ssl'',''server'',''hosting'',''vercel'',''hardware'',''tool'') NOT NULL',
  'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
