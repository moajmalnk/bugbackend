-- Seed data for permission system
-- Run this after running permissions_schema.sql

-- Insert system roles
INSERT INTO `roles` (`id`, `role_name`, `description`, `is_system_role`) VALUES
(1, 'Admin', 'System Administrator', 1),
(2, 'Developer', 'Software Developer', 1),
(3, 'Tester', 'Quality Assurance Tester', 1)
ON DUPLICATE KEY UPDATE `role_name`=`role_name`;

-- Insert permissions
INSERT INTO `permissions` (`id`, `permission_key`, `permission_name`, `category`, `scope`) VALUES
(1, 'BUGS_CREATE', 'Create Bugs', 'Bugs', 'global'),
(2, 'BUGS_VIEW_ALL', 'View All Bugs', 'Bugs', 'project'),
(3, 'BUGS_EDIT_ALL', 'Edit All Bugs', 'Bugs', 'project'),
(4, 'USERS_VIEW', 'View Users', 'Users', 'global'),
(5, 'USERS_MANAGE_PERMISSIONS', 'Manage User Permissions', 'Users', 'global'),
(6, 'DOCS_CREATE', 'Create Documentation', 'Docs', 'global'),
(7, 'DOCS_VIEW', 'View Documentation', 'Docs', 'project'),
(8, 'ROLES_MANAGE', 'Manage Roles', 'Settings', 'global'),
(9, 'SETTINGS_EDIT', 'Edit Settings', 'Settings', 'global'),
(10, 'SUPER_ADMIN', 'Super Administrator (Bypass All Checks)', 'System', 'global'),
(11, 'MESSAGING_VIEW', 'View Messages', 'Messaging', 'global'),
(12, 'MESSAGING_CREATE', 'Create Messages', 'Messaging', 'project'),
(13, 'MESSAGING_MANAGE', 'Manage Messages', 'Messaging', 'project'),
(14, 'PROJECTS_VIEW', 'View Projects', 'Projects', 'project'),
(15, 'PROJECTS_CREATE', 'Create Projects', 'Projects', 'global'),
(16, 'PROJECTS_EDIT', 'Edit Projects', 'Projects', 'project'),
(17, 'FIXES_VIEW', 'View Fixes', 'Fixes', 'project'),
(18, 'UPDATES_VIEW', 'View Updates', 'Updates', 'project'),
(19, 'UPDATES_CREATE', 'Create Updates', 'Updates', 'project'),
(20, 'UPDATES_EDIT', 'Edit Updates', 'Updates', 'project'),
(21, 'UPDATES_DELETE', 'Delete Updates', 'Updates', 'project'),
(22, 'ACTIVITY_VIEW', 'View Activity', 'Activity', 'global'),
(23, 'FEEDBACK_VIEW', 'View Feedback', 'Feedback', 'global'),
(24, 'FEEDBACK_MANAGE', 'Manage Feedback', 'Feedback', 'global'),
(25, 'PROJECTS_VIEW_ASSIGNED', 'View Assigned Projects', 'Projects', 'project'),
(26, 'TASKS_VIEW_ALL', 'View All Tasks', 'Tasks', 'global'),
(27, 'TASKS_VIEW_ASSIGNED', 'View Assigned Tasks', 'Tasks', 'global'),
(28, 'TASKS_CREATE', 'Create Tasks', 'Tasks', 'global'),
(29, 'DAILY_UPDATE_CREATE', 'Create Daily Updates', 'Updates', 'global'),
(30, 'DAILY_UPDATE_VIEW', 'View Daily Updates', 'Updates', 'global'),
(50, 'SETTINGS_VIEW', 'View Settings', 'Settings', 'global'),
(51, 'ANNOUNCEMENTS_MANAGE', 'Manage Announcements', 'Settings', 'global')
ON DUPLICATE KEY UPDATE `permission_key`=`permission_key`;

-- Map permissions to Admin role (all permissions including SUPER_ADMIN)
INSERT INTO `role_permissions` (`role_id`, `permission_id`) VALUES
(1, 1), (1, 2), (1, 3), (1, 4), (1, 5), (1, 6), (1, 7), (1, 8), (1, 9), (1, 10), 
(1, 11), (1, 12), (1, 13), (1, 14), (1, 15), (1, 16), (1, 17), (1, 18), (1, 19), 
(1, 20), (1, 21), (1, 22), (1, 23), (1, 24), (1, 25), (1, 26), (1, 27), (1, 28), (1, 29), (1, 30), (1, 50), (1, 51)
ON DUPLICATE KEY UPDATE `role_id`=`role_id`;

-- Map permissions to Developer role (comprehensive access)
INSERT INTO `role_permissions` (`role_id`, `permission_id`) VALUES
(2, 1),  -- BUGS_CREATE
(2, 2),  -- BUGS_VIEW_ALL
(2, 3),  -- BUGS_EDIT_ALL
(2, 4),  -- USERS_VIEW
(2, 6),  -- DOCS_CREATE
(2, 7),  -- DOCS_VIEW
(2, 9),  -- SETTINGS_EDIT
(2, 11), -- MESSAGING_VIEW
(2, 12), -- MESSAGING_CREATE
(2, 13), -- MESSAGING_MANAGE
(2, 14), -- PROJECTS_VIEW
(2, 15), -- PROJECTS_CREATE
(2, 16), -- PROJECTS_EDIT
(2, 17), -- FIXES_VIEW
(2, 18), -- UPDATES_VIEW
(2, 19), -- UPDATES_CREATE
(2, 20), -- UPDATES_EDIT
(2, 22), -- ACTIVITY_VIEW
(2, 23), -- FEEDBACK_VIEW
(2, 25), -- PROJECTS_VIEW_ASSIGNED
(2, 26), -- TASKS_VIEW_ALL
(2, 27), -- TASKS_VIEW_ASSIGNED
(2, 28), -- TASKS_CREATE
(2, 29), -- DAILY_UPDATE_CREATE
(2, 30), -- DAILY_UPDATE_VIEW
(2, 50), -- SETTINGS_VIEW
(2, 51)  -- ANNOUNCEMENTS_MANAGE
ON DUPLICATE KEY UPDATE `role_id`=`role_id`;

-- Map permissions to Tester role
INSERT INTO `role_permissions` (`role_id`, `permission_id`) VALUES
(3, 1),  -- BUGS_CREATE
(3, 4),  -- USERS_VIEW
(3, 7),  -- DOCS_VIEW
(3, 11), -- MESSAGING_VIEW
(3, 12), -- MESSAGING_CREATE
(3, 14), -- PROJECTS_VIEW
(3, 17), -- FIXES_VIEW
(3, 18), -- UPDATES_VIEW
(3, 22), -- ACTIVITY_VIEW
(3, 23), -- FEEDBACK_VIEW
(3, 25), -- PROJECTS_VIEW_ASSIGNED
(3, 27), -- TASKS_VIEW_ASSIGNED
(3, 30), -- DAILY_UPDATE_VIEW
(3, 50)  -- SETTINGS_VIEW
ON DUPLICATE KEY UPDATE `role_id`=`role_id`;

