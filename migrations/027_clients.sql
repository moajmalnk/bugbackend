-- Normalized clients entity, attachments, and project linkage

CREATE TABLE IF NOT EXISTS `clients` (
  `id` varchar(36) NOT NULL,
  `corporate_name` varchar(255) NOT NULL,
  `website` varchar(500) DEFAULT NULL,
  `market_industry` enum('fintech','healthcare','ecommerce','education','saas','manufacturing','real_estate','other') DEFAULT NULL,
  `gst_tax_id` varchar(100) DEFAULT NULL,
  `commercial_status` enum('lead','active','inactive','ended') NOT NULL DEFAULT 'lead',
  `primary_contact_name` varchar(255) DEFAULT NULL,
  `position` varchar(255) DEFAULT NULL,
  `hq_location` varchar(255) DEFAULT NULL,
  `direct_email` varchar(255) DEFAULT NULL,
  `direct_phone` varchar(50) DEFAULT NULL,
  `birthday` date DEFAULT NULL,
  `date_of_joining` date DEFAULT NULL,
  `date_of_ending` date DEFAULT NULL,
  `referral_source` enum('direct','referral','website','social_media','event','partner','other') DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` varchar(36) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_clients_corporate_name` (`corporate_name`),
  KEY `idx_clients_commercial_status` (`commercial_status`),
  KEY `idx_clients_created_by` (`created_by`),
  CONSTRAINT `clients_ibfk_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `client_attachments` (
  `id` varchar(36) NOT NULL,
  `client_id` varchar(36) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_type` varchar(100) DEFAULT NULL,
  `uploaded_by` varchar(36) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_client_attachments_client_id` (`client_id`),
  KEY `idx_client_attachments_uploaded_by` (`uploaded_by`),
  CONSTRAINT `client_attachments_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `client_attachments_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Link projects to clients (safe if column already exists from a partial run)
SET @client_id_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'projects'
    AND COLUMN_NAME = 'client_id'
);
SET @sql := IF(
  @client_id_exists = 0,
  'ALTER TABLE `projects` ADD COLUMN `client_id` varchar(36) DEFAULT NULL AFTER `description`, ADD KEY `idx_projects_client_id` (`client_id`), ADD CONSTRAINT `projects_ibfk_client_id` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Permissions (omit permission_description — not present on all deployments)
INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'CLIENTS_VIEW', 'View Clients', 'Clients', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `permission_key` = 'CLIENTS_VIEW');

INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'CLIENTS_CREATE', 'Create Clients', 'Clients', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `permission_key` = 'CLIENTS_CREATE');

INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'CLIENTS_EDIT', 'Edit Clients', 'Clients', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `permission_key` = 'CLIENTS_EDIT');

INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'CLIENTS_DELETE', 'Delete Clients', 'Clients', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `permission_key` = 'CLIENTS_DELETE');

-- Grant to admin role (role_id = 1)
INSERT INTO `role_permissions` (`role_id`, `permission_id`, `created_at`)
SELECT 1, p.id, NOW()
FROM `permissions` p
WHERE p.permission_key IN ('CLIENTS_VIEW', 'CLIENTS_CREATE', 'CLIENTS_EDIT', 'CLIENTS_DELETE')
  AND NOT EXISTS (
    SELECT 1 FROM `role_permissions` rp
    WHERE rp.role_id = 1 AND rp.permission_id = p.id
  );
