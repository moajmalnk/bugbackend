-- ============================================
-- Production Permission System Setup (SAFE VERSION)
-- BugRicer - Dynamic Role-Based Access Control
-- Safe for existing databases
-- ============================================

-- ============================================
-- STEP 1: Drop Existing Permission Tables (if any)
-- ============================================

-- Disable foreign key checks temporarily for clean installation
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `user_permissions`;
DROP TABLE IF EXISTS `role_permissions`;
DROP TABLE IF EXISTS `permissions`;
DROP TABLE IF EXISTS `roles`;

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================
-- STEP 2: Drop Existing Foreign Key from users table (if exists)
-- ============================================

-- Find and drop the foreign key constraint
SET @fk_name = NULL;
SELECT CONSTRAINT_NAME INTO @fk_name FROM information_schema.KEY_COLUMN_USAGE 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'users' 
AND REFERENCED_TABLE_NAME = 'roles' 
AND CONSTRAINT_NAME != 'PRIMARY'
LIMIT 1;

SET @drop_sql = IF(@fk_name IS NOT NULL, 
    CONCAT('ALTER TABLE `users` DROP FOREIGN KEY `', @fk_name, '`'),
    'SELECT "No foreign key to drop" as message'
);
PREPARE drop_stmt FROM @drop_sql;
EXECUTE drop_stmt;
DEALLOCATE PREPARE drop_stmt;

-- ============================================
-- STEP 3: Create Permission Tables
-- ============================================

CREATE TABLE `roles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `role_name` VARCHAR(50) UNIQUE NOT NULL,
    `description` TEXT,
    `is_system_role` BOOLEAN DEFAULT FALSE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `permissions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `permission_key` VARCHAR(100) UNIQUE NOT NULL,
    `permission_name` VARCHAR(100) NOT NULL,
    `category` VARCHAR(50) NOT NULL,
    `scope` ENUM('global', 'project') DEFAULT 'global',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `role_permissions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `role_id` INT NOT NULL,
    `permission_id` INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`permission_id`) REFERENCES `permissions`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_role_permission` (`role_id`, `permission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_permissions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` VARCHAR(36) NOT NULL,
    `permission_id` INT NOT NULL,
    `project_id` VARCHAR(36) NULL,
    `granted` BOOLEAN NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`permission_id`) REFERENCES `permissions`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_user_permission_project` (`user_id`, `permission_id`, `project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- STEP 4: Insert System Roles
-- ============================================

INSERT INTO `roles` (`id`, `role_name`, `description`, `is_system_role`) VALUES
(1, 'Admin', 'System Administrator with full access', 1),
(2, 'Developer', 'Software Developer with project and bug management access', 1),
(3, 'Tester', 'Quality Assurance Tester with bug reporting and testing access', 1)
ON DUPLICATE KEY UPDATE `role_name`=`role_name`;

-- ============================================
-- STEP 5: Insert All Permissions
-- ============================================

INSERT INTO `permissions` (`id`, `permission_key`, `permission_name`, `category`, `scope`) VALUES
-- Bugs
(1, 'BUGS_CREATE', 'Create Bugs', 'Bugs', 'global'),
(2, 'BUGS_VIEW_ALL', 'View All Bugs', 'Bugs', 'project'),
(3, 'BUGS_EDIT_ALL', 'Edit All Bugs', 'Bugs', 'project'),
(4, 'BUGS_VIEW_OWN', 'View Own Bugs', 'Bugs', 'project'),
(5, 'BUGS_EDIT_OWN', 'Edit Own Bugs', 'Bugs', 'project'),
(6, 'BUGS_DELETE', 'Delete Bugs', 'Bugs', 'project'),
(7, 'BUGS_CHANGE_STATUS', 'Change Bug Status', 'Bugs', 'project'),

-- Users
(8, 'USERS_VIEW', 'View Users', 'Users', 'global'),
(9, 'USERS_CREATE', 'Create Users', 'Users', 'global'),
(10, 'USERS_EDIT', 'Edit Users', 'Users', 'global'),
(11, 'USERS_DELETE', 'Delete Users', 'Users', 'global'),
(12, 'USERS_CHANGE_PASSWORD', 'Change User Passwords', 'Users', 'global'),
(13, 'USERS_MANAGE_PERMISSIONS', 'Manage User Permissions', 'Users', 'global'),
(14, 'USERS_IMPERSONATE', 'Impersonate Users', 'Users', 'global'),

-- Documentation
(15, 'DOCS_VIEW', 'View Documentation', 'Documentation', 'project'),
(16, 'DOCS_CREATE', 'Create Documentation', 'Documentation', 'global'),
(17, 'DOCS_EDIT', 'Edit Documentation', 'Documentation', 'project'),

-- Settings
(38, 'ROLES_MANAGE', 'Manage Roles', 'Settings', 'global'),
(39, 'SETTINGS_EDIT', 'Edit Settings', 'Settings', 'global'),
(50, 'SETTINGS_VIEW', 'View Settings', 'Settings', 'global'),
(51, 'ANNOUNCEMENTS_MANAGE', 'Manage Announcements', 'Settings', 'global'),

-- System
(55, 'SUPER_ADMIN', 'Super Administrator (Bypass All Checks)', 'System', 'global'),

-- Messaging
(43, 'MESSAGING_VIEW', 'View Messages', 'Messaging', 'global'),
(44, 'MESSAGING_SEND', 'Send Messages', 'Messaging', 'project'),
(45, 'MESSAGING_DELETE', 'Delete Messages', 'Messaging', 'project'),
(46, 'MESSAGING_MANAGE_GROUPS', 'Manage Chat Groups', 'Messaging', 'project'),

-- Projects
(18, 'PROJECTS_VIEW_ALL', 'View All Projects', 'Projects', 'project'),
(19, 'PROJECTS_VIEW_ASSIGNED', 'View Assigned Projects', 'Projects', 'project'),
(20, 'PROJECTS_CREATE', 'Create Projects', 'Projects', 'global'),
(21, 'PROJECTS_EDIT', 'Edit Projects', 'Projects', 'project'),
(22, 'PROJECTS_DELETE', 'Delete Projects', 'Projects', 'project'),
(23, 'PROJECTS_MANAGE_MEMBERS', 'Manage Project Members', 'Projects', 'project'),
(24, 'PROJECTS_ARCHIVE', 'Archive Projects', 'Projects', 'project'),

-- Fixes
(27, 'FIXES_VIEW', 'View Fixes', 'Fixes', 'project'),

-- Updates
(33, 'UPDATES_VIEW', 'View Updates', 'Updates', 'project'),
(34, 'UPDATES_CREATE', 'Create Updates', 'Updates', 'project'),
(35, 'UPDATES_EDIT', 'Edit Updates', 'Updates', 'project'),
(36, 'UPDATES_DELETE', 'Delete Updates', 'Updates', 'project'),
(37, 'UPDATES_APPROVE', 'Approve Updates', 'Updates', 'project'),

-- Activity & Feedback
(47, 'ACTIVITY_VIEW', 'View Activity', 'Activity', 'global'),
(48, 'FEEDBACK_VIEW', 'View Feedback', 'Feedback', 'global'),
(49, 'FEEDBACK_MANAGE', 'Manage Feedback', 'Feedback', 'global'),

-- Tasks
(28, 'TASKS_VIEW_ALL', 'View All Tasks', 'Tasks', 'global'),
(29, 'TASKS_VIEW_ASSIGNED', 'View Assigned Tasks', 'Tasks', 'global'),
(30, 'TASKS_CREATE', 'Create Tasks', 'Tasks', 'global'),
(31, 'TASKS_EDIT', 'Edit Tasks', 'Tasks', 'global'),
(32, 'TASKS_DELETE', 'Delete Tasks', 'Tasks', 'global'),
(33, 'TASKS_ASSIGN', 'Assign Tasks', 'Tasks', 'global'),

-- Daily Update Permissions
(40, 'DAILY_UPDATE_CREATE', 'Create Daily Updates', 'Updates', 'global'),
(41, 'DAILY_UPDATE_VIEW', 'View Daily Updates', 'Updates', 'global'),

-- Meetings
(52, 'MEETINGS_CREATE', 'Create Meetings', 'Meetings', 'global'),
(53, 'MEETINGS_JOIN', 'Join Meetings', 'Meetings', 'global'),
(54, 'MEETINGS_MANAGE', 'Manage Meetings', 'Meetings', 'global')

ON DUPLICATE KEY UPDATE `permission_key`=`permission_key`;

-- ============================================
-- STEP 6: Map Permissions to Admin Role
-- ============================================

INSERT INTO `role_permissions` (`role_id`, `permission_id`) VALUES
(1, 1), (1, 2), (1, 3), (1, 4), (1, 5), (1, 6), (1, 7),
(1, 8), (1, 9), (1, 10), (1, 11), (1, 12), (1, 13), (1, 14),
(1, 15), (1, 16), (1, 17),
(1, 38), (1, 39), (1, 50), (1, 51),
(1, 55),
(1, 43), (1, 44), (1, 45), (1, 46),
(1, 18), (1, 19), (1, 20), (1, 21), (1, 22), (1, 23), (1, 24),
(1, 27),
(1, 33), (1, 34), (1, 35), (1, 36), (1, 37),
(1, 47), (1, 48), (1, 49),
(1, 28), (1, 29), (1, 30), (1, 31), (1, 32), (1, 33),
(1, 40), (1, 41),
(1, 52), (1, 53), (1, 54)

ON DUPLICATE KEY UPDATE `role_id`=`role_id`;

-- ============================================
-- STEP 7: Map Permissions to Developer Role
-- ============================================

INSERT INTO `role_permissions` (`role_id`, `permission_id`) VALUES
(2, 1), (2, 2), (2, 3), (2, 4), (2, 5), (2, 6), (2, 7),
(2, 8), (2, 9), (2, 10), (2, 11), (2, 12), (2, 13), (2, 14),
(2, 15), (2, 16), (2, 17),
(2, 38), (2, 39), (2, 50), (2, 51),
(2, 43), (2, 44), (2, 45), (2, 46),
(2, 18), (2, 19), (2, 20), (2, 21), (2, 22), (2, 23), (2, 24),
(2, 27),
(2, 33), (2, 34), (2, 35), (2, 36), (2, 37),
(2, 47), (2, 48), (2, 49),
(2, 28), (2, 29), (2, 30), (2, 31), (2, 32), (2, 33),
(2, 40), (2, 41),
(2, 52), (2, 53), (2, 54)

ON DUPLICATE KEY UPDATE `role_id`=`role_id`;

-- ============================================
-- STEP 8: Map Permissions to Tester Role
-- ============================================

INSERT INTO `role_permissions` (`role_id`, `permission_id`) VALUES
(3, 1), (3, 4), (3, 8), (3, 15), (3, 50), (3, 43), (3, 44), 
(3, 19), (3, 27), (3, 33), (3, 47), (3, 48), (3, 29), (3, 41), (3, 53)

ON DUPLICATE KEY UPDATE `role_id`=`role_id`;

-- ============================================
-- STEP 9: Add role_id column to users table
-- ============================================

-- Add role_id column if it doesn't exist
ALTER TABLE `users` 
ADD COLUMN `role_id` INT NULL AFTER `role`;

-- Add foreign key constraint
ALTER TABLE `users` 
ADD CONSTRAINT `fk_users_role` 
FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE SET NULL;

-- ============================================
-- STEP 10: Migrate existing users to role_id
-- ============================================

UPDATE `users` SET `role_id` = 1 WHERE `role` = 'admin' AND `role_id` IS NULL;
UPDATE `users` SET `role_id` = 2 WHERE `role` = 'developer' AND `role_id` IS NULL;
UPDATE `users` SET `role_id` = 3 WHERE `role` IN ('tester', 'user') AND `role_id` IS NULL;

-- Set default role_id for any remaining users
UPDATE `users` SET `role_id` = 3 WHERE `role_id` IS NULL;

-- ============================================
-- STEP 11: Create Indexes for Performance
-- ============================================

CREATE INDEX `idx_users_role_id` ON `users`(`role_id`);
CREATE INDEX `idx_permissions_category` ON `permissions`(`category`);
CREATE INDEX `idx_user_permissions_user` ON `user_permissions`(`user_id`);
CREATE INDEX `idx_user_permissions_project` ON `user_permissions`(`project_id`);
CREATE INDEX `idx_role_permissions_role` ON `role_permissions`(`role_id`);
CREATE INDEX `idx_role_permissions_permission` ON `role_permissions`(`permission_id`);

-- ============================================
-- Installation Complete!
-- ============================================

SELECT 
    'Permission System Installation Complete!' as status,
    COUNT(DISTINCT r.id) as total_roles,
    COUNT(DISTINCT p.id) as total_permissions,
    COUNT(DISTINCT rp.id) as total_assignments
FROM roles r
CROSS JOIN permissions p
LEFT JOIN role_permissions rp ON r.id = rp.role_id AND p.id = rp.permission_id
LIMIT 1;

