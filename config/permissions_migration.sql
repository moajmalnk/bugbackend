-- Migration script to add role_id column to users table and sync existing data
-- Run this after running permissions_schema.sql and permissions_seed.sql

-- Add role_id column to users table
ALTER TABLE `users` ADD COLUMN `role_id` INT NULL AFTER `role`;

-- Add foreign key constraint
ALTER TABLE `users` ADD CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE SET NULL;

-- Migrate existing role data to role_id
-- Admin role
UPDATE `users` SET `role_id` = 1 WHERE `role` = 'admin';

-- Developer role
UPDATE `users` SET `role_id` = 2 WHERE `role` = 'developer';

-- Tester role
UPDATE `users` SET `role_id` = 3 WHERE `role` = 'tester';

-- Add index for better query performance
CREATE INDEX `idx_users_role_id` ON `users`(`role_id`);

