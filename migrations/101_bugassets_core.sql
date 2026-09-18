-- =============================================================================
-- BugRicer Migration 101 — BugAssets core inventory (safe to re-run)
-- =============================================================================
-- Clients.client_code, digital/physical asset tables, ASSETS_* permissions.
-- Shared nodes (servers/hosting/vercel) have no required client_id; clients
-- attach via assets_client_nodes. Domains/mail/SSL are client-owned.
-- =============================================================================

SET @db := DATABASE();

-- ----------------------------------------------------------------
-- 1. Unique operator-facing client code (CLT-0001)
-- ----------------------------------------------------------------
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clients' AND COLUMN_NAME = 'client_code'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `clients` ADD COLUMN `client_code` VARCHAR(16) NULL DEFAULT NULL AFTER `id`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clients' AND INDEX_NAME = 'uk_clients_client_code'
);
SET @sql := IF(@idx = 0,
  'ALTER TABLE `clients` ADD UNIQUE KEY `uk_clients_client_code` (`client_code`)',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @n := 0;
UPDATE `clients`
SET `client_code` = CONCAT('CLT-', LPAD((@n := @n + 1), 4, '0'))
WHERE (`client_code` IS NULL OR `client_code` = '')
ORDER BY `created_at` ASC, `id` ASC;

SET @nulls := (
  SELECT COUNT(*) FROM `clients` WHERE `client_code` IS NULL OR `client_code` = ''
);
SET @sql := IF(@nulls = 0,
  'ALTER TABLE `clients` MODIFY COLUMN `client_code` VARCHAR(16) NOT NULL',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ----------------------------------------------------------------
-- 2. Shared infrastructure nodes
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `assets_servers` (
  `id` VARCHAR(36) NOT NULL,
  `hostname` VARCHAR(255) NOT NULL,
  `public_ipv4` VARCHAR(45) NULL DEFAULT NULL,
  `public_ipv6` VARCHAR(45) NULL DEFAULT NULL,
  `ssh_user` VARCHAR(64) NULL DEFAULT NULL,
  `ssh_port` SMALLINT UNSIGNED NOT NULL DEFAULT 22,
  `os_name` VARCHAR(80) NULL DEFAULT NULL,
  `vcpus` TINYINT UNSIGNED NULL DEFAULT NULL,
  `ram_mb` INT UNSIGNED NULL DEFAULT NULL,
  `disk_gb` INT UNSIGNED NULL DEFAULT NULL,
  `panel_url` VARCHAR(500) NULL DEFAULT NULL,
  `ownership` ENUM('shared','dedicated','internal') NOT NULL DEFAULT 'shared',
  `vendor` VARCHAR(100) NULL DEFAULT NULL,
  `vendor_account` VARCHAR(150) NULL DEFAULT NULL,
  `billing_cycle` ENUM('monthly','yearly','biennial','one_time') NOT NULL DEFAULT 'yearly',
  `currency` CHAR(3) NOT NULL DEFAULT 'INR',
  `vendor_cost` DECIMAL(12,2) NULL DEFAULT NULL,
  `client_charge` DECIMAL(12,2) NULL DEFAULT NULL,
  `margin_amount` DECIMAL(12,2) GENERATED ALWAYS AS (IFNULL(`client_charge`,0) - IFNULL(`vendor_cost`,0)) STORED,
  `invoice_status` ENUM('not_billed','invoiced','paid','waived') NOT NULL DEFAULT 'not_billed',
  `auto_renew` TINYINT(1) NOT NULL DEFAULT 1,
  `purchased_at` DATE NULL DEFAULT NULL,
  `expires_at` DATE NULL DEFAULT NULL,
  `status` ENUM('active','pending','expired','cancelled','parked') NOT NULL DEFAULT 'active',
  `notes` TEXT NULL DEFAULT NULL,
  `created_by` VARCHAR(36) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  `deleted_by` VARCHAR(36) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_servers_hostname` (`hostname`),
  UNIQUE KEY `uk_servers_ipv4` (`public_ipv4`),
  KEY `idx_servers_expires` (`expires_at`, `status`),
  KEY `idx_servers_status_created` (`status`, `created_at`),
  KEY `idx_servers_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `assets_hosting` (
  `id` VARCHAR(36) NOT NULL,
  `label` VARCHAR(255) NOT NULL,
  `panel_url` VARCHAR(500) NULL DEFAULT NULL,
  `package_name` VARCHAR(120) NULL DEFAULT NULL,
  `primary_domain` VARCHAR(255) NULL DEFAULT NULL,
  `public_ipv4` VARCHAR(45) NULL DEFAULT NULL,
  `vendor` VARCHAR(100) NULL DEFAULT NULL,
  `vendor_account` VARCHAR(150) NULL DEFAULT NULL,
  `billing_cycle` ENUM('monthly','yearly','biennial','one_time') NOT NULL DEFAULT 'yearly',
  `currency` CHAR(3) NOT NULL DEFAULT 'INR',
  `vendor_cost` DECIMAL(12,2) NULL DEFAULT NULL,
  `client_charge` DECIMAL(12,2) NULL DEFAULT NULL,
  `margin_amount` DECIMAL(12,2) GENERATED ALWAYS AS (IFNULL(`client_charge`,0) - IFNULL(`vendor_cost`,0)) STORED,
  `invoice_status` ENUM('not_billed','invoiced','paid','waived') NOT NULL DEFAULT 'not_billed',
  `auto_renew` TINYINT(1) NOT NULL DEFAULT 1,
  `purchased_at` DATE NULL DEFAULT NULL,
  `expires_at` DATE NULL DEFAULT NULL,
  `status` ENUM('active','pending','expired','cancelled','parked') NOT NULL DEFAULT 'active',
  `notes` TEXT NULL DEFAULT NULL,
  `created_by` VARCHAR(36) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  `deleted_by` VARCHAR(36) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_hosting_expires` (`expires_at`, `status`),
  KEY `idx_hosting_status_created` (`status`, `created_at`),
  KEY `idx_hosting_deleted_at` (`deleted_at`),
  KEY `idx_hosting_primary_domain` (`primary_domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `assets_vercel` (
  `id` VARCHAR(36) NOT NULL,
  `team_slug` VARCHAR(120) NULL DEFAULT NULL,
  `project_name` VARCHAR(255) NOT NULL,
  `repo_url` VARCHAR(500) NULL DEFAULT NULL,
  `production_domain` VARCHAR(255) NULL DEFAULT NULL,
  `framework` VARCHAR(80) NULL DEFAULT NULL,
  `vendor` VARCHAR(100) NULL DEFAULT 'Vercel',
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
  `status` ENUM('active','pending','expired','cancelled','parked') NOT NULL DEFAULT 'active',
  `notes` TEXT NULL DEFAULT NULL,
  `created_by` VARCHAR(36) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  `deleted_by` VARCHAR(36) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_vercel_prod` (`production_domain`),
  KEY `idx_vercel_expires` (`expires_at`, `status`),
  KEY `idx_vercel_status_created` (`status`, `created_at`),
  KEY `idx_vercel_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
-- 3. Client-owned domains, DNS, mail, SSL
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `assets_domains` (
  `id` VARCHAR(36) NOT NULL,
  `client_id` VARCHAR(36) NOT NULL,
  `project_id` VARCHAR(36) NULL DEFAULT NULL,
  `fqdn` VARCHAR(255) NOT NULL,
  `registrar` VARCHAR(80) NULL DEFAULT NULL,
  `dns_provider` VARCHAR(80) NULL DEFAULT NULL,
  `nameservers` JSON NULL DEFAULT NULL,
  `whois_privacy` TINYINT(1) NOT NULL DEFAULT 1,
  `vendor` VARCHAR(100) NULL DEFAULT NULL,
  `vendor_account` VARCHAR(150) NULL DEFAULT NULL,
  `billing_cycle` ENUM('monthly','yearly','biennial','one_time') NOT NULL DEFAULT 'yearly',
  `currency` CHAR(3) NOT NULL DEFAULT 'INR',
  `vendor_cost` DECIMAL(12,2) NULL DEFAULT NULL,
  `client_charge` DECIMAL(12,2) NULL DEFAULT NULL,
  `margin_amount` DECIMAL(12,2) GENERATED ALWAYS AS (IFNULL(`client_charge`,0) - IFNULL(`vendor_cost`,0)) STORED,
  `invoice_status` ENUM('not_billed','invoiced','paid','waived') NOT NULL DEFAULT 'not_billed',
  `auto_renew` TINYINT(1) NOT NULL DEFAULT 1,
  `purchased_at` DATE NULL DEFAULT NULL,
  `expires_at` DATE NULL DEFAULT NULL,
  `status` ENUM('active','pending','expired','cancelled','parked') NOT NULL DEFAULT 'active',
  `notes` TEXT NULL DEFAULT NULL,
  `created_by` VARCHAR(36) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  `deleted_by` VARCHAR(36) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_assets_domains_fqdn` (`fqdn`),
  KEY `idx_assets_domains_client` (`client_id`, `status`, `created_at`),
  KEY `idx_assets_domains_project` (`project_id`),
  KEY `idx_assets_domains_expires` (`expires_at`, `status`),
  KEY `idx_assets_domains_deleted_at` (`deleted_at`),
  CONSTRAINT `fk_ad_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ad_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `assets_subdomains` (
  `id` VARCHAR(36) NOT NULL,
  `domain_id` VARCHAR(36) NOT NULL,
  `host` VARCHAR(255) NOT NULL,
  `fqdn` VARCHAR(255) NOT NULL,
  `purpose` VARCHAR(255) NULL DEFAULT NULL,
  `record_type` ENUM('A','AAAA','CNAME','ALIAS','MX','TXT','NS') NOT NULL DEFAULT 'A',
  `target_kind` ENUM('server','hosting','vercel','raw') NOT NULL DEFAULT 'raw',
  `target_server_id` VARCHAR(36) NULL DEFAULT NULL,
  `target_hosting_id` VARCHAR(36) NULL DEFAULT NULL,
  `target_vercel_id` VARCHAR(36) NULL DEFAULT NULL,
  `target_value` VARCHAR(500) NULL DEFAULT NULL,
  `ttl` INT UNSIGNED NULL DEFAULT NULL,
  `status` ENUM('active','disabled') NOT NULL DEFAULT 'active',
  `created_by` VARCHAR(36) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  `deleted_by` VARCHAR(36) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_assets_sub_fqdn` (`fqdn`),
  KEY `idx_sub_domain` (`domain_id`, `created_at`),
  KEY `idx_sub_server` (`target_server_id`),
  KEY `idx_sub_hosting` (`target_hosting_id`),
  KEY `idx_sub_vercel` (`target_vercel_id`),
  KEY `idx_sub_deleted_at` (`deleted_at`),
  CONSTRAINT `fk_sub_domain` FOREIGN KEY (`domain_id`) REFERENCES `assets_domains` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sub_server` FOREIGN KEY (`target_server_id`) REFERENCES `assets_servers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sub_hosting` FOREIGN KEY (`target_hosting_id`) REFERENCES `assets_hosting` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sub_vercel` FOREIGN KEY (`target_vercel_id`) REFERENCES `assets_vercel` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `assets_emails` (
  `id` VARCHAR(36) NOT NULL,
  `domain_id` VARCHAR(36) NOT NULL,
  `address` VARCHAR(255) NOT NULL,
  `provider` ENUM('hostinger','google','zoho','microsoft','other') NOT NULL DEFAULT 'hostinger',
  `storage_quota_mb` INT UNSIGNED NULL DEFAULT NULL,
  `assigned_user_id` VARCHAR(36) NULL DEFAULT NULL,
  `assigned_contact` VARCHAR(255) NULL DEFAULT NULL,
  `vendor` VARCHAR(100) NULL DEFAULT NULL,
  `vendor_account` VARCHAR(150) NULL DEFAULT NULL,
  `billing_cycle` ENUM('monthly','yearly','biennial','one_time') NOT NULL DEFAULT 'yearly',
  `currency` CHAR(3) NOT NULL DEFAULT 'INR',
  `vendor_cost` DECIMAL(12,2) NULL DEFAULT NULL,
  `client_charge` DECIMAL(12,2) NULL DEFAULT NULL,
  `margin_amount` DECIMAL(12,2) GENERATED ALWAYS AS (IFNULL(`client_charge`,0) - IFNULL(`vendor_cost`,0)) STORED,
  `invoice_status` ENUM('not_billed','invoiced','paid','waived') NOT NULL DEFAULT 'not_billed',
  `auto_renew` TINYINT(1) NOT NULL DEFAULT 1,
  `purchased_at` DATE NULL DEFAULT NULL,
  `expires_at` DATE NULL DEFAULT NULL,
  `status` ENUM('active','suspended','deleted') NOT NULL DEFAULT 'active',
  `notes` TEXT NULL DEFAULT NULL,
  `created_by` VARCHAR(36) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  `deleted_by` VARCHAR(36) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_assets_emails_address` (`address`),
  KEY `idx_emails_domain` (`domain_id`, `created_at`),
  KEY `idx_emails_assignee` (`assigned_user_id`),
  KEY `idx_emails_expires` (`expires_at`, `status`),
  KEY `idx_emails_deleted_at` (`deleted_at`),
  CONSTRAINT `fk_em_domain` FOREIGN KEY (`domain_id`) REFERENCES `assets_domains` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_em_user` FOREIGN KEY (`assigned_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `assets_ssl_certs` (
  `id` VARCHAR(36) NOT NULL,
  `domain_id` VARCHAR(36) NOT NULL,
  `subdomain_id` VARCHAR(36) NULL DEFAULT NULL,
  `issuer` VARCHAR(80) NULL DEFAULT NULL,
  `covers` VARCHAR(255) NULL DEFAULT NULL,
  `vendor` VARCHAR(100) NULL DEFAULT NULL,
  `vendor_account` VARCHAR(150) NULL DEFAULT NULL,
  `billing_cycle` ENUM('monthly','yearly','biennial','one_time') NOT NULL DEFAULT 'yearly',
  `currency` CHAR(3) NOT NULL DEFAULT 'INR',
  `vendor_cost` DECIMAL(12,2) NULL DEFAULT NULL,
  `client_charge` DECIMAL(12,2) NULL DEFAULT NULL,
  `margin_amount` DECIMAL(12,2) GENERATED ALWAYS AS (IFNULL(`client_charge`,0) - IFNULL(`vendor_cost`,0)) STORED,
  `invoice_status` ENUM('not_billed','invoiced','paid','waived') NOT NULL DEFAULT 'not_billed',
  `auto_renew` TINYINT(1) NOT NULL DEFAULT 1,
  `purchased_at` DATE NULL DEFAULT NULL,
  `expires_at` DATE NOT NULL,
  `status` ENUM('active','expiring','expired') NOT NULL DEFAULT 'active',
  `notes` TEXT NULL DEFAULT NULL,
  `created_by` VARCHAR(36) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  `deleted_by` VARCHAR(36) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ssl_expires` (`expires_at`, `status`),
  KEY `idx_ssl_domain` (`domain_id`),
  KEY `idx_ssl_subdomain` (`subdomain_id`),
  KEY `idx_ssl_deleted_at` (`deleted_at`),
  CONSTRAINT `fk_ssl_domain` FOREIGN KEY (`domain_id`) REFERENCES `assets_domains` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ssl_sub` FOREIGN KEY (`subdomain_id`) REFERENCES `assets_subdomains` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
-- 4. Client ↔ node links (shared inventory)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `assets_client_nodes` (
  `id` VARCHAR(36) NOT NULL,
  `client_id` VARCHAR(36) NOT NULL,
  `project_id` VARCHAR(36) NULL DEFAULT NULL,
  `node_kind` ENUM('server','hosting','vercel') NOT NULL,
  `node_id` VARCHAR(36) NOT NULL,
  `role` VARCHAR(80) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_client_node` (`client_id`, `node_kind`, `node_id`),
  KEY `idx_node_lookup` (`node_kind`, `node_id`),
  KEY `idx_cn_project` (`project_id`),
  CONSTRAINT `fk_cn_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cn_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
-- 5. Physical hardware
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `assets_hardware` (
  `id` VARCHAR(36) NOT NULL,
  `asset_tag` VARCHAR(32) NOT NULL,
  `category` ENUM('laptop','test_phone','office_device','network','other') NOT NULL DEFAULT 'laptop',
  `brand` VARCHAR(80) NULL DEFAULT NULL,
  `model` VARCHAR(120) NULL DEFAULT NULL,
  `serial_no` VARCHAR(120) NULL DEFAULT NULL,
  `imei` VARCHAR(20) NULL DEFAULT NULL,
  `assigned_user_id` VARCHAR(36) NULL DEFAULT NULL,
  `client_id` VARCHAR(36) NULL DEFAULT NULL,
  `purchase_date` DATE NULL DEFAULT NULL,
  `warranty_expires_at` DATE NULL DEFAULT NULL,
  `vendor` VARCHAR(100) NULL DEFAULT NULL,
  `vendor_account` VARCHAR(150) NULL DEFAULT NULL,
  `currency` CHAR(3) NOT NULL DEFAULT 'INR',
  `vendor_cost` DECIMAL(12,2) NULL DEFAULT NULL,
  `client_charge` DECIMAL(12,2) NULL DEFAULT NULL,
  `margin_amount` DECIMAL(12,2) GENERATED ALWAYS AS (IFNULL(`client_charge`,0) - IFNULL(`vendor_cost`,0)) STORED,
  `invoice_status` ENUM('not_billed','invoiced','paid','waived') NOT NULL DEFAULT 'not_billed',
  `status` ENUM('in_stock','assigned','repair','retired','lost') NOT NULL DEFAULT 'in_stock',
  `notes` TEXT NULL DEFAULT NULL,
  `created_by` VARCHAR(36) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  `deleted_by` VARCHAR(36) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_hw_tag` (`asset_tag`),
  UNIQUE KEY `uk_hw_serial` (`serial_no`),
  KEY `idx_hw_assignee` (`assigned_user_id`, `status`),
  KEY `idx_hw_warranty` (`warranty_expires_at`),
  KEY `idx_hw_client` (`client_id`),
  KEY `idx_hw_deleted_at` (`deleted_at`),
  KEY `idx_hw_created` (`created_at`),
  CONSTRAINT `fk_hw_user` FOREIGN KEY (`assigned_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_hw_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------
-- 6. Permissions (admin role_id = 1)
-- ----------------------------------------------------------------
INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'ASSETS_VIEW', 'View BugAssets', 'Assets', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `permission_key` = 'ASSETS_VIEW');

INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'ASSETS_CREATE', 'Create BugAssets records', 'Assets', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `permission_key` = 'ASSETS_CREATE');

INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'ASSETS_EDIT', 'Edit BugAssets records', 'Assets', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `permission_key` = 'ASSETS_EDIT');

INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'ASSETS_DELETE', 'Delete BugAssets records', 'Assets', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `permission_key` = 'ASSETS_DELETE');

INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'ASSETS_VAULT_REVEAL', 'Reveal BugAssets vault secrets', 'Assets', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `permission_key` = 'ASSETS_VAULT_REVEAL');

INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'ASSETS_FINANCE_VIEW', 'View BugAssets vendor cost and margin', 'Assets', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `permission_key` = 'ASSETS_FINANCE_VIEW');

INSERT INTO `role_permissions` (`role_id`, `permission_id`, `created_at`)
SELECT 1, p.id, NOW()
FROM `permissions` p
WHERE p.permission_key IN (
  'ASSETS_VIEW', 'ASSETS_CREATE', 'ASSETS_EDIT', 'ASSETS_DELETE',
  'ASSETS_VAULT_REVEAL', 'ASSETS_FINANCE_VIEW'
)
AND NOT EXISTS (
  SELECT 1 FROM `role_permissions` rp
  WHERE rp.role_id = 1 AND rp.permission_id = p.id
);
