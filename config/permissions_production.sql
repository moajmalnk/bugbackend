-- ============================================
-- Production Permission System Setup
-- BugRicer - Dynamic Role-Based Access Control
-- ============================================

-- Disable foreign key checks temporarily for clean installation
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================
-- STEP 1: Drop Existing Tables (if any)
-- ============================================

-- Note: Foreign key constraints are automatically ignored when FOREIGN_KEY_CHECKS = 0
-- Now drop tables in correct order
DROP TABLE IF EXISTS `user_permissions`;
DROP TABLE IF EXISTS `role_permissions`;
DROP TABLE IF EXISTS `permissions`;
DROP TABLE IF EXISTS `roles`;

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
    UNIQUE KEY `unique_user_permission_project` (`user_id`, `permission_id`, `project_id`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_permission_id` (`permission_id`),
    INDEX `idx_project_id` (`project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- Now add foreign key constraints AFTER all tables are created
-- Note: These may fail if constraints already exist - that's okay
ALTER TABLE `user_permissions`
ADD CONSTRAINT `fk_up_user` 
FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE;

ALTER TABLE `user_permissions`
ADD CONSTRAINT `fk_up_permission`
FOREIGN KEY (`permission_id`) REFERENCES `permissions`(`id`) ON DELETE CASCADE;

-- ============================================
-- STEP 2: Insert System Roles
-- ============================================

INSERT INTO `roles` (`id`, `role_name`, `description`, `is_system_role`) VALUES
(1, 'Admin', 'System Administrator with full access', 1),
(2, 'Developer', 'Software Developer with project and bug management access', 1),
(3, 'Tester', 'Quality Assurance Tester with bug reporting and testing access', 1)
ON DUPLICATE KEY UPDATE `role_name`=`role_name`;

-- ============================================
-- STEP 3: Insert All Permissions
-- ============================================

INSERT INTO `permissions` (`id`, `permission_key`, `permission_name`, `category`, `scope`) VALUES
-- Bugs Permissions
(1, 'BUGS_CREATE', 'Create Bugs', 'Bugs', 'global'),
(2, 'BUGS_VIEW_ALL', 'View All Bugs', 'Bugs', 'project'),
(3, 'BUGS_EDIT_ALL', 'Edit All Bugs', 'Bugs', 'project'),
(4, 'BUGS_VIEW_OWN', 'View Own Bugs', 'Bugs', 'project'),
(5, 'BUGS_EDIT_OWN', 'Edit Own Bugs', 'Bugs', 'project'),
(6, 'BUGS_DELETE', 'Delete Bugs', 'Bugs', 'project'),
(7, 'BUGS_CHANGE_STATUS', 'Change Bug Status', 'Bugs', 'project'),

-- Users Permissions
(8, 'USERS_VIEW', 'View Users', 'Users', 'global'),
(9, 'USERS_CREATE', 'Create Users', 'Users', 'global'),
(10, 'USERS_EDIT', 'Edit Users', 'Users', 'global'),
(11, 'USERS_DELETE', 'Delete Users', 'Users', 'global'),
(12, 'USERS_CHANGE_PASSWORD', 'Change User Passwords', 'Users', 'global'),
(13, 'USERS_MANAGE_PERMISSIONS', 'Manage User Permissions', 'Users', 'global'),
(14, 'USERS_IMPERSONATE', 'Impersonate Users', 'Users', 'global'),

-- Documentation Permissions
(25, 'DOCS_VIEW', 'View Documentation', 'Documentation', 'project'),
(26, 'DOCS_CREATE', 'Create Documentation', 'Documentation', 'global'),
(42, 'DOCS_EDIT', 'Edit Documentation', 'Documentation', 'project'),

-- Settings Permissions
(38, 'ROLES_MANAGE', 'Manage Roles', 'Settings', 'global'),
(39, 'SETTINGS_EDIT', 'Edit Settings', 'Settings', 'global'),
(50, 'SETTINGS_VIEW', 'View Settings', 'Settings', 'global'),
(51, 'ANNOUNCEMENTS_MANAGE', 'Manage Announcements', 'Settings', 'global'),

-- System Permissions
(55, 'SUPER_ADMIN', 'Super Administrator (Bypass All Checks)', 'System', 'global'),

-- Messaging Permissions
(43, 'MESSAGING_VIEW', 'View Messages', 'Messaging', 'global'),
(44, 'MESSAGING_SEND', 'Send Messages', 'Messaging', 'project'),
(45, 'MESSAGING_DELETE', 'Delete Messages', 'Messaging', 'project'),
(46, 'MESSAGING_MANAGE_GROUPS', 'Manage Chat Groups', 'Messaging', 'project'),

-- Projects Permissions
(18, 'PROJECTS_VIEW_ALL', 'View All Projects', 'Projects', 'project'),
(19, 'PROJECTS_VIEW_ASSIGNED', 'View Assigned Projects', 'Projects', 'project'),
(20, 'PROJECTS_CREATE', 'Create Projects', 'Projects', 'global'),
(21, 'PROJECTS_EDIT', 'Edit Projects', 'Projects', 'project'),
(22, 'PROJECTS_DELETE', 'Delete Projects', 'Projects', 'project'),
(23, 'PROJECTS_MANAGE_MEMBERS', 'Manage Project Members', 'Projects', 'project'),
(24, 'PROJECTS_ARCHIVE', 'Archive Projects', 'Projects', 'project'),

-- Fixes Permissions
(27, 'FIXES_VIEW', 'View Fixes', 'Fixes', 'project'),

-- Updates Permissions
(33, 'UPDATES_VIEW', 'View Updates', 'Updates', 'project'),
(34, 'UPDATES_CREATE', 'Create Updates', 'Updates', 'project'),
(35, 'UPDATES_EDIT', 'Edit Updates', 'Updates', 'project'),
(36, 'UPDATES_DELETE', 'Delete Updates', 'Updates', 'project'),
(37, 'UPDATES_APPROVE', 'Approve Updates', 'Updates', 'project'),

-- Activity & Feedback Permissions
(47, 'ACTIVITY_VIEW', 'View Activity', 'Activity', 'global'),
(48, 'FEEDBACK_VIEW', 'View Feedback', 'Feedback', 'global'),
(49, 'FEEDBACK_MANAGE', 'Manage Feedback', 'Feedback', 'global'),

-- Tasks Permissions
(56, 'TASKS_VIEW_ALL', 'View All Tasks', 'Tasks', 'global'),
(57, 'TASKS_VIEW_ASSIGNED', 'View Assigned Tasks', 'Tasks', 'global'),
(58, 'TASKS_CREATE', 'Create Tasks', 'Tasks', 'global'),
(59, 'TASKS_EDIT', 'Edit Tasks', 'Tasks', 'global'),
(60, 'TASKS_DELETE', 'Delete Tasks', 'Tasks', 'global'),
(61, 'TASKS_ASSIGN', 'Assign Tasks', 'Tasks', 'global'),

-- Daily Update Permissions
(40, 'DAILY_UPDATE_CREATE', 'Create Daily Updates', 'Updates', 'global'),
(41, 'DAILY_UPDATE_VIEW', 'View Daily Updates', 'Updates', 'global'),

-- Meetings Permissions
(52, 'MEETINGS_CREATE', 'Create Meetings', 'Meetings', 'global'),
(53, 'MEETINGS_JOIN', 'Join Meetings', 'Meetings', 'global'),
(54, 'MEETINGS_MANAGE', 'Manage Meetings', 'Meetings', 'global')

ON DUPLICATE KEY UPDATE `permission_key`=`permission_key`;

-- ============================================
-- STEP 4: Map Permissions to Admin Role
-- ============================================

INSERT INTO `role_permissions` (`role_id`, `permission_id`) VALUES
-- Bugs
(1, 1), (1, 2), (1, 3), (1, 4), (1, 5), (1, 6), (1, 7),
-- Users
(1, 8), (1, 9), (1, 10), (1, 11), (1, 12), (1, 13), (1, 14),
-- Documentation
(1, 25), (1, 26), (1, 42),
-- Settings
(1, 38), (1, 39), (1, 50), (1, 51),
-- System
(1, 55),
-- Messaging
(1, 43), (1, 44), (1, 45), (1, 46),
-- Projects
(1, 18), (1, 19), (1, 20), (1, 21), (1, 22), (1, 23), (1, 24),
-- Fixes
(1, 27),
-- Updates
(1, 33), (1, 34), (1, 35), (1, 36), (1, 37),
-- Activity & Feedback
(1, 47), (1, 48), (1, 49),
-- Tasks
(1, 56), (1, 57), (1, 58), (1, 59), (1, 60), (1, 61),
-- Daily Updates
(1, 40), (1, 41),
-- Meetings
(1, 52), (1, 53), (1, 54)

ON DUPLICATE KEY UPDATE `role_id`=`role_id`;

-- ============================================
-- STEP 5: Map Permissions to Developer Role
-- ============================================

INSERT INTO `role_permissions` (`role_id`, `permission_id`) VALUES
-- Bugs
(2, 1), (2, 2), (2, 3), (2, 4), (2, 5), (2, 6), (2, 7),
-- Users
(2, 8), (2, 9), (2, 10), (2, 11), (2, 12), (2, 13), (2, 14),
-- Documentation
(2, 25), (2, 26), (2, 42),
-- Settings
(2, 38), (2, 39), (2, 50), (2, 51),
-- Messaging
(2, 43), (2, 44), (2, 45), (2, 46),
-- Projects
(2, 18), (2, 19), (2, 20), (2, 21), (2, 22), (2, 23), (2, 24),
-- Fixes
(2, 27),
-- Updates
(2, 33), (2, 34), (2, 35), (2, 36), (2, 37),
-- Activity & Feedback
(2, 47), (2, 48), (2, 49),
-- Tasks
(2, 56), (2, 57), (2, 58), (2, 59), (2, 60), (2, 61),
-- Daily Updates
(2, 40), (2, 41),
-- Meetings
(2, 52), (2, 53), (2, 54)

ON DUPLICATE KEY UPDATE `role_id`=`role_id`;

-- ============================================
-- STEP 6: Map Permissions to Tester Role
-- ============================================

INSERT INTO `role_permissions` (`role_id`, `permission_id`) VALUES
-- Bugs
(3, 1), (3, 4),
-- Users
(3, 8),
-- Documentation
(3, 25),
-- Settings
(3, 50),
-- Messaging
(3, 43), (3, 44),
-- Projects
(3, 19),
-- Fixes
(3, 27),
-- Updates
(3, 33),
-- Activity & Feedback
(3, 47), (3, 48),
-- Tasks
(3, 57),
-- Daily Updates
(3, 41),
-- Meetings
(3, 53)

ON DUPLICATE KEY UPDATE `role_id`=`role_id`;

-- ============================================
-- STEP 7: Add role_id to users table
-- ============================================

-- Add role_id column if it doesn't exist (will fail if column already exists - that's okay)
ALTER TABLE `users` 
ADD COLUMN `role_id` INT NULL AFTER `role`;

-- Add foreign key constraint (will fail if constraint already exists - that's okay)
ALTER TABLE `users` 
ADD CONSTRAINT `fk_users_role` 
FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE SET NULL;

-- Sync existing roles to role_id
UPDATE `users` SET `role_id` = 1 WHERE `role` = 'admin' AND `role_id` IS NULL;
UPDATE `users` SET `role_id` = 2 WHERE `role` = 'developer' AND `role_id` IS NULL;
UPDATE `users` SET `role_id` = 3 WHERE `role` IN ('tester', 'user') AND `role_id` IS NULL;

-- Set default role_id for any remaining users
UPDATE `users` SET `role_id` = 3 WHERE `role_id` IS NULL;

-- ============================================
-- STEP 8: Create Indexes for Performance
-- ============================================

CREATE INDEX IF NOT EXISTS `idx_users_role_id` ON `users`(`role_id`);
CREATE INDEX IF NOT EXISTS `idx_permissions_category` ON `permissions`(`category`);
CREATE INDEX IF NOT EXISTS `idx_user_permissions_user` ON `user_permissions`(`user_id`);
CREATE INDEX IF NOT EXISTS `idx_user_permissions_project` ON `user_permissions`(`project_id`);
CREATE INDEX IF NOT EXISTS `idx_role_permissions_role` ON `role_permissions`(`role_id`);
CREATE INDEX IF NOT EXISTS `idx_role_permissions_permission` ON `role_permissions`(`permission_id`);

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

