-- Admin/developer-only planning fields for updates
ALTER TABLE `updates`
ADD COLUMN `calculated_hours` DECIMAL(10,2) NULL DEFAULT NULL COMMENT 'Estimated hours to complete (admin/developer)' AFTER `expected_time`,
ADD COLUMN `update_priority` ENUM('high','medium','low') NULL DEFAULT NULL COMMENT 'Update priority (admin/developer)' AFTER `calculated_hours`;
