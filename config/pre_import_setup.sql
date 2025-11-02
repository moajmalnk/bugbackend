-- ============================================
-- Pre-Import Setup - Run This FIRST
-- BugRicer Permission System
-- ============================================

-- This file prepares the database for the main import

-- Disable foreign key checks
SET FOREIGN_KEY_CHECKS = 0;

-- Drop the foreign key constraint if it exists
ALTER TABLE `users` DROP FOREIGN KEY `fk_users_role`;

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- Now you can import permissions_production.sql safely

