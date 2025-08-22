SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";
CREATE TABLE `activities` (
  `id` varchar(36) NOT NULL,
  `type` varchar(50) NOT NULL,
  `entity_id` varchar(36) NOT NULL,
  `user_id` varchar(36) NOT NULL,
  `action` varchar(255) NOT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;
-- --------------------------------------------------------
--
-- Table structure for table `activity_log`
--
CREATE TABLE `activity_log` (
  `id` varchar(36) NOT NULL,
  `user_id` varchar(36) NOT NULL,
  `action` varchar(255) NOT NULL,
  `action_type` varchar(50) NOT NULL,
  `entity_type` varchar(50) NOT NULL,
  `entity_id` varchar(36) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;
-- --------------------------------------------------------
--
-- Table structure for table `admin_audit_log`
--
CREATE TABLE `admin_audit_log` (
  `id` int(11) NOT NULL,
  `admin_id` varchar(36) NOT NULL,
  `action` varchar(100) NOT NULL,
  `target_user_id` varchar(36) DEFAULT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
-- --------------------------------------------------------
--
-- Table structure for table `announcements`
--
CREATE TABLE `announcements` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `expiry_date` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_broadcast_at` datetime DEFAULT NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;
-- --------------------------------------------------------
--
-- Table structure for table `bugs`
--
CREATE TABLE `bugs` (
  `id` varchar(36) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `fix_description` text DEFAULT NULL,
  `project_id` varchar(36) NOT NULL,
  `reported_by` varchar(36) DEFAULT NULL,
  `priority` enum('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
  `status` enum(
    'pending',
    'in_progress',
    'fixed',
    'declined',
    'rejected'
  ) DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `fixed_by` varchar(255) DEFAULT NULL,
  `updated_by` varchar(36) DEFAULT NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;
--
-- Triggers `bugs`
--
DELIMITER $$ CREATE TRIGGER `bugs_update_timestamp` BEFORE
UPDATE ON `bugs` FOR EACH ROW BEGIN -- Update timestamp and updated_by when important fields change
  IF NEW.status != OLD.status
  OR NEW.priority != OLD.priority
  OR NEW.description != OLD.description
  OR NEW.title != OLD.title THEN
SET NEW.updated_at = CURRENT_TIMESTAMP;
-- If updated_by is not being explicitly set, keep the old value
IF NEW.updated_by = OLD.updated_by THEN
SET NEW.updated_by = OLD.updated_by;
END IF;
END IF;
END $$ DELIMITER;
-- --------------------------------------------------------
--
-- Table structure for table `bug_attachments`
--
CREATE TABLE `bug_attachments` (
  `id` varchar(36) NOT NULL,
  `bug_id` varchar(36) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_type` varchar(100) DEFAULT NULL,
  `uploaded_by` varchar(36) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;
-- --------------------------------------------------------
--
-- Table structure for table `notifications`
--
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `type` enum('new_bug', 'status_change') NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `bug_id` int(11) NOT NULL,
  `bug_title` varchar(255) NOT NULL,
  `status` varchar(50) DEFAULT NULL,
  `created_by` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;
-- --------------------------------------------------------
--
-- Table structure for table `projects`
--
CREATE TABLE `projects` (
  `id` varchar(36) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active', 'completed', 'archived') NOT NULL DEFAULT 'active',
  `created_by` varchar(36) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;
-- --------------------------------------------------------
--
-- Table structure for table `project_activities`
--
CREATE TABLE `project_activities` (
  `id` int(11) NOT NULL,
  `user_id` varchar(255) NOT NULL COMMENT 'References users.id',
  `project_id` varchar(255) NOT NULL COMMENT 'References projects.id',
  `activity_type` varchar(50) NOT NULL COMMENT 'Type of activity (bug_reported, member_added, etc.)',
  `description` text NOT NULL COMMENT 'Human-readable description of the activity',
  `related_id` varchar(255) DEFAULT NULL COMMENT 'Optional reference to related entity (bug, task, etc.)',
  `metadata` text DEFAULT NULL COMMENT 'JSON metadata for additional activity context',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'When the activity occurred'
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
-- --------------------------------------------------------
--
-- Table structure for table `project_members`
--
CREATE TABLE `project_members` (
  `project_id` varchar(36) NOT NULL,
  `user_id` varchar(36) NOT NULL,
  `role` enum('manager', 'developer', 'tester') NOT NULL,
  `joined_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;
-- --------------------------------------------------------
--
-- Table structure for table `settings`
--
CREATE TABLE `settings` (
  `id` int(11) NOT NULL,
  `key_name` varchar(255) DEFAULT NULL,
  `value` varchar(255) DEFAULT NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;
--
-- Dumping data for table `settings`
--
INSERT INTO `settings` (`id`, `key_name`, `value`)
VALUES (1, 'email_notifications_enabled', '1');
-- --------------------------------------------------------
--
-- Table structure for table `updates`
--
CREATE TABLE `updates` (
  `id` varchar(36) NOT NULL,
  `project_id` varchar(36) NOT NULL,
  `title` varchar(255) NOT NULL,
  `type` enum('feature', 'updation', 'maintenance') NOT NULL,
  `description` text NOT NULL,
  `created_by` varchar(36) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `status` enum('pending', 'approved', 'declined') DEFAULT 'pending'
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;
-- --------------------------------------------------------
--
-- Table structure for table `users`
--
CREATE TABLE `users` (
  `id` varchar(36) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `password_changed_at` timestamp NULL DEFAULT NULL,
  `role` enum('admin', 'developer', 'tester', 'user') NOT NULL DEFAULT 'user',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `fcm_token` varchar(255) DEFAULT NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;
--
-- Dumping data for table `users`
--
INSERT INTO `users` (
    `id`,
    `username`,
    `email`,
    `password`,
    `password_changed_at`,
    `role`,
    `created_at`,
    `updated_at`,
    `fcm_token`
  )
VALUES (
    '608dc9d1-26e0-441d-8144-45f74c53a846',
    'admin',
    'moajmalnk@gmail.com',
    '$2y$10$AfxAdtuKx3ZrSj4/3iLxzOiF/57NZR9yuNQe4K1mynEzxef6zdpSS',
    NULL,
    'admin',
    '2025-04-10 12:22:47',
    '2025-06-23 13:47:11',
    'douTMjwTkIFkzuAyeoMiQg:APA91bExwwYFQv9yZCEuVm1CzRAqVWGQhvqgWvFJM2_QHgCiyoR1lN42kHbEx5AFq5vbrfTA-DuytgEZGPUCAgntXYln1zEy0eRGifaskpGE9iozId63aRM'
  );
--
-- Indexes for dumped tables
--
--
-- Indexes for table `activities`
--
ALTER TABLE `activities`
ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);
--
-- Indexes for table `activity_log`
--
ALTER TABLE `activity_log`
ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);
--
-- Indexes for table `admin_audit_log`
--
ALTER TABLE `admin_audit_log`
ADD PRIMARY KEY (`id`),
  ADD KEY `idx_admin_id` (`admin_id`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_created_at` (`created_at`);
--
-- Indexes for table `announcements`
--
ALTER TABLE `announcements`
ADD PRIMARY KEY (`id`);
--
-- Indexes for table `bugs`
--
ALTER TABLE `bugs`
ADD PRIMARY KEY (`id`),
  ADD KEY `idx_bugs_status` (`status`),
  ADD KEY `idx_bugs_updated_by` (`updated_by`),
  ADD KEY `idx_bugs_updated_by_status` (`updated_by`, `status`),
  ADD KEY `idx_bugs_reported_by` (`reported_by`),
  ADD KEY `idx_bugs_project_id` (`project_id`),
  ADD KEY `idx_bugs_created_at` (`created_at`),
  ADD KEY `idx_bugs_project_created` (`project_id`, `created_at`),
  ADD KEY `idx_bugs_status_updated_by` (`status`, `updated_by`),
  ADD KEY `idx_bugs_project_status_created` (`project_id`, `status`, `created_at`),
  ADD KEY `idx_bugs_reporter_created` (`reported_by`, `created_at`);
--
-- Indexes for table `bug_attachments`
--
ALTER TABLE `bug_attachments`
ADD PRIMARY KEY (`id`),
  ADD KEY `idx_bug_attachments_bug_id` (`bug_id`),
  ADD KEY `idx_bug_attachments_uploaded_by` (`uploaded_by`);
--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
ADD PRIMARY KEY (`id`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_type` (`type`),
  ADD KEY `idx_bug_id` (`bug_id`);
--
-- Indexes for table `projects`
--
ALTER TABLE `projects`
ADD PRIMARY KEY (`id`),
  ADD KEY `idx_projects_created_by` (`created_by`),
  ADD KEY `idx_projects_name` (`name`);
--
-- Indexes for table `project_activities`
--
ALTER TABLE `project_activities`
ADD PRIMARY KEY (`id`),
  ADD KEY `pa_project_id` (`project_id`),
  ADD KEY `pa_user_id` (`user_id`),
  ADD KEY `pa_activity_type` (`activity_type`),
  ADD KEY `pa_created_at` (`created_at`),
  ADD KEY `pa_related_id` (`related_id`),
  ADD KEY `pa_project_created` (`project_id`, `created_at`),
  ADD KEY `pa_user_created` (`user_id`, `created_at`);
--
-- Indexes for table `project_members`
--
ALTER TABLE `project_members`
ADD PRIMARY KEY (`project_id`, `user_id`),
  ADD KEY `idx_project_members_user_id` (`user_id`),
  ADD KEY `idx_project_members_project_id` (`project_id`),
  ADD KEY `idx_project_members_user_project` (`user_id`, `project_id`),
  ADD KEY `idx_project_members_joined_at` (`joined_at`);
--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `key_name` (`key_name`);
--
-- Indexes for table `updates`
--
ALTER TABLE `updates`
ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_type` (`type`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_project_id` (`project_id`);
--
-- Indexes for table `users`
--
ALTER TABLE `users`
ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_users_role` (`role`),
  ADD KEY `idx_users_email` (`email`),
  ADD KEY `idx_users_id_role` (`id`, `role`);
--
-- AUTO_INCREMENT for dumped tables
--
--
-- AUTO_INCREMENT for table `admin_audit_log`
--
ALTER TABLE `admin_audit_log`
MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,
  AUTO_INCREMENT = 33;
--
-- AUTO_INCREMENT for table `announcements`
--
ALTER TABLE `announcements`
MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,
  AUTO_INCREMENT = 4;
--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,
  AUTO_INCREMENT = 2;
--
-- AUTO_INCREMENT for table `project_activities`
--
ALTER TABLE `project_activities`
MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,
  AUTO_INCREMENT = 40;
--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,
  AUTO_INCREMENT = 65;
--
-- Constraints for dumped tables
--
--
-- Constraints for table `activities`
--
ALTER TABLE `activities`
ADD CONSTRAINT `activities_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
--
-- Constraints for table `activity_log`
--
ALTER TABLE `activity_log`
ADD CONSTRAINT `activity_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);
--
-- Constraints for table `bugs`
--
ALTER TABLE `bugs`
ADD CONSTRAINT `bugs_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `bugs_ibfk_2` FOREIGN KEY (`reported_by`) REFERENCES `users` (`id`);
--
-- Constraints for table `bug_attachments`
--
ALTER TABLE `bug_attachments`
ADD CONSTRAINT `bug_attachments_ibfk_1` FOREIGN KEY (`bug_id`) REFERENCES `bugs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `bug_attachments_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`);
--
-- Constraints for table `projects`
--
ALTER TABLE `projects`
ADD CONSTRAINT `projects_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);
--
-- Constraints for table `project_members`
--
ALTER TABLE `project_members`
ADD CONSTRAINT `project_members_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  ADD CONSTRAINT `project_members_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);
--
-- Constraints for table `updates`
--
ALTER TABLE `updates`
ADD CONSTRAINT `updates_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `updates_ibfk_2` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`);
COMMIT;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */
;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */
;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */
;
-- CREATE TABLE IF NOT EXISTS user_otps (
--   id INT AUTO_INCREMENT PRIMARY KEY,
--   email VARCHAR(100) NOT NULL,
--   otp VARCHAR(6) NOT NULL,
--   expires_at TIMESTAMP NOT NULL,
--   created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
-- );
-- -- Optional: Add an index for faster lookups
-- CREATE INDEX idx_user_otps_email ON user_otps(email);
CREATE TABLE IF NOT EXISTS user_otps (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(100),
  phone VARCHAR(20),
  otp VARCHAR(6) NOT NULL,
  expires_at TIMESTAMP NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_otps_email (email),
  INDEX idx_user_otps_phone (phone)
);
ALTER TABLE users
ADD COLUMN phone VARCHAR(20) NULL
AFTER email;
CREATE INDEX idx_users_phone ON users(phone);