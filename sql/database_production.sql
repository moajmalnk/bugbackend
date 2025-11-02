-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Nov 02, 2025 at 12:03 PM
-- Server version: 10.4.28-MariaDB
-- PHP Version: 8.2.4

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `u262074081_bugfixer_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `activities`
--

CREATE TABLE `activities` (
  `id` varchar(36) NOT NULL,
  `type` varchar(50) NOT NULL,
  `entity_id` varchar(36) NOT NULL,
  `user_id` varchar(36) NOT NULL,
  `action` varchar(255) NOT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `activity_log`
--

CREATE TABLE `activity_log` (
  `id` varchar(36) NOT NULL,
  `user_id` varchar(36) NOT NULL,
  `action_type` varchar(50) NOT NULL,
  `entity_type` varchar(50) NOT NULL,
  `entity_id` varchar(36) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_broadcast_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL,
  `user_id` varchar(36) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `details` longtext DEFAULT NULL CHECK (json_valid(`details`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `blocked_users`
--

CREATE TABLE `blocked_users` (
  `id` varchar(36) NOT NULL,
  `blocker_id` varchar(36) NOT NULL COMMENT 'User who blocked',
  `blocked_id` varchar(36) NOT NULL COMMENT 'User who was blocked',
  `blocked_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `broadcast_lists`
--

CREATE TABLE `broadcast_lists` (
  `id` varchar(36) NOT NULL,
  `name` varchar(255) NOT NULL,
  `created_by` varchar(36) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `broadcast_recipients`
--

CREATE TABLE `broadcast_recipients` (
  `broadcast_id` varchar(36) NOT NULL,
  `user_id` varchar(36) NOT NULL,
  `added_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bugs`
--

CREATE TABLE `bugs` (
  `id` varchar(36) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `expected_result` text DEFAULT NULL,
  `actual_result` text DEFAULT NULL,
  `fix_description` text DEFAULT NULL,
  `project_id` varchar(36) NOT NULL,
  `reported_by` varchar(36) DEFAULT NULL,
  `priority` enum('high','medium','low') DEFAULT 'medium',
  `status` enum('pending','in_progress','fixed','declined','rejected') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` varchar(36) DEFAULT NULL,
  `fixed_by` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Triggers `bugs`
--
DELIMITER $$
CREATE TRIGGER `bugs_update_timestamp` BEFORE UPDATE ON `bugs` FOR EACH ROW BEGIN
    -- Update timestamp and updated_by when important fields change
    IF NEW.status != OLD.status OR 
       NEW.priority != OLD.priority OR 
       NEW.description != OLD.description OR
       NEW.title != OLD.title THEN
        SET NEW.updated_at = CURRENT_TIMESTAMP;
        
        -- If updated_by is not being explicitly set, keep the old value
        IF NEW.updated_by = OLD.updated_by THEN
            SET NEW.updated_by = OLD.updated_by;
        END IF;
    END IF;
END
$$
DELIMITER ;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bug_documents`
--

CREATE TABLE `bug_documents` (
  `id` int(11) NOT NULL,
  `bug_id` varchar(255) NOT NULL COMMENT 'Reference to bugs table (UUID)',
  `google_doc_id` varchar(255) NOT NULL COMMENT 'Google Document ID',
  `google_doc_url` text NOT NULL COMMENT 'Full URL to the Google Document',
  `document_name` varchar(500) NOT NULL COMMENT 'Name of the document',
  `created_by` varchar(255) NOT NULL COMMENT 'User who created the document (UUID)',
  `template_id` int(11) DEFAULT NULL COMMENT 'Reference to doc_templates if created from template',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_accessed_at` timestamp NULL DEFAULT NULL COMMENT 'Last time document was opened'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `call_logs`
--

CREATE TABLE `call_logs` (
  `id` varchar(36) NOT NULL,
  `call_type` enum('voice','video') NOT NULL,
  `caller_id` varchar(36) NOT NULL,
  `group_id` varchar(36) DEFAULT NULL COMMENT 'For group calls',
  `duration_seconds` int(11) DEFAULT NULL,
  `status` enum('missed','declined','completed','failed') NOT NULL DEFAULT 'completed',
  `started_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `ended_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `call_participants`
--

CREATE TABLE `call_participants` (
  `call_id` varchar(36) NOT NULL,
  `user_id` varchar(36) NOT NULL,
  `joined_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `left_at` timestamp NULL DEFAULT NULL,
  `status` enum('calling','joined','declined','missed') NOT NULL DEFAULT 'calling'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_groups`
--

CREATE TABLE `chat_groups` (
  `id` varchar(36) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `group_picture` varchar(500) DEFAULT NULL,
  `project_id` varchar(36) NOT NULL,
  `created_by` varchar(36) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_archived` tinyint(1) NOT NULL DEFAULT 0,
  `archived_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_group_members`
--

CREATE TABLE `chat_group_members` (
  `group_id` varchar(36) NOT NULL,
  `user_id` varchar(36) NOT NULL,
  `joined_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_read_at` timestamp NULL DEFAULT NULL,
  `is_muted` tinyint(1) NOT NULL DEFAULT 0,
  `muted_until` timestamp NULL DEFAULT NULL,
  `show_read_receipts` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_messages`
--

CREATE TABLE `chat_messages` (
  `id` varchar(36) NOT NULL,
  `group_id` varchar(36) NOT NULL,
  `sender_id` varchar(36) NOT NULL,
  `message_type` enum('text','voice','reply','image','video','document','audio','location','contact') NOT NULL DEFAULT 'text',
  `media_type` enum('image','video','document','audio') DEFAULT NULL,
  `media_file_path` varchar(500) DEFAULT NULL,
  `media_file_name` varchar(255) DEFAULT NULL,
  `media_file_size` int(11) DEFAULT NULL COMMENT 'File size in bytes',
  `media_thumbnail` varchar(500) DEFAULT NULL,
  `media_duration` int(11) DEFAULT NULL COMMENT 'For video/audio in seconds',
  `content` text DEFAULT NULL,
  `voice_file_path` varchar(500) DEFAULT NULL,
  `voice_duration` int(11) DEFAULT NULL COMMENT 'Duration in seconds',
  `reply_to_message_id` varchar(36) DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `is_pinned` tinyint(1) NOT NULL DEFAULT 0,
  `is_starred` tinyint(1) NOT NULL DEFAULT 0,
  `starred_at` timestamp NULL DEFAULT NULL,
  `starred_by` varchar(36) DEFAULT NULL,
  `is_forwarded` tinyint(1) NOT NULL DEFAULT 0,
  `original_message_id` varchar(36) DEFAULT NULL,
  `is_edited` tinyint(1) NOT NULL DEFAULT 0,
  `edited_at` timestamp NULL DEFAULT NULL,
  `delivery_status` enum('sent','delivered','read','failed') NOT NULL DEFAULT 'sent',
  `pinned_at` timestamp NULL DEFAULT NULL,
  `pinned_by` varchar(36) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Triggers `chat_messages`
--
DELIMITER $$
CREATE TRIGGER `chat_messages_auto_delete_check` BEFORE UPDATE ON `chat_messages` FOR EACH ROW BEGIN
  -- Only allow deletion if message is less than 1 hour old OR user is admin
  IF NEW.is_deleted = 1 AND OLD.is_deleted = 0 THEN
    -- This will be handled in the application logic
    -- The trigger just ensures we track the deletion time
    SET NEW.deleted_at = CURRENT_TIMESTAMP;
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `disappearing_messages_settings`
--

CREATE TABLE `disappearing_messages_settings` (
  `group_id` varchar(36) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `duration_seconds` int(11) NOT NULL DEFAULT 604800 COMMENT '7 days default',
  `enabled_by` varchar(36) DEFAULT NULL,
  `enabled_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `doc_templates`
--

CREATE TABLE `doc_templates` (
  `id` int(11) NOT NULL,
  `template_name` varchar(255) NOT NULL COMMENT 'Human-readable template name (e.g., Bug Report, Meeting Notes)',
  `google_doc_id` varchar(255) NOT NULL COMMENT 'Google Document ID of the template file',
  `description` text DEFAULT NULL COMMENT 'Template description',
  `category` varchar(100) DEFAULT 'general' COMMENT 'Template category: bug, general, meeting, etc.',
  `is_active` tinyint(1) DEFAULT 1 COMMENT 'Whether template is active and available for use',
  `created_by` varchar(255) DEFAULT NULL COMMENT 'User who created the template',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Document templates for BugDocs';

--
-- Dumping data for table `doc_templates`
--

INSERT INTO `doc_templates` (`id`, `template_name`, `google_doc_id`, `description`, `category`, `is_active`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'Bug Report Template', 'TEMPLATE_BUG_REPORT_ID', 'Professional bug investigation and reporting template with placeholders for bug details', 'bug', 1, NULL, '2025-10-20 17:27:23', '2025-10-20 17:27:23'),
(2, 'Meeting Notes Template', 'TEMPLATE_MEETING_NOTES_ID', 'Structured meeting notes with agenda, attendees, and action items', 'meeting', 1, NULL, '2025-10-20 17:27:23', '2025-10-20 17:27:23'),
(3, 'General Document', 'TEMPLATE_GENERAL_DOC_ID', 'Blank document for general purposes', 'general', 1, NULL, '2025-10-20 17:27:23', '2025-10-20 17:27:23'),
(4, 'Technical Specification', 'TEMPLATE_TECH_SPEC_ID', 'Technical specification document template', 'technical', 1, NULL, '2025-10-20 17:27:23', '2025-10-20 17:27:23');

-- --------------------------------------------------------

--
-- Table structure for table `google_tokens`
--

CREATE TABLE `google_tokens` (
  `google_user_id` varchar(255) NOT NULL COMMENT 'Google User ID (from OAuth)',
  `bugricer_user_id` varchar(255) NOT NULL COMMENT 'BugRicer user ID reference (UUID)',
  `refresh_token` text NOT NULL COMMENT 'Google OAuth Refresh Token (long-lived)',
  `access_token_expiry` timestamp NULL DEFAULT NULL COMMENT 'When the current access token expires',
  `email` varchar(255) DEFAULT NULL COMMENT 'Google account email',
  `scope` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `group_admins`
--

CREATE TABLE `group_admins` (
  `group_id` varchar(36) NOT NULL,
  `user_id` varchar(36) NOT NULL,
  `granted_by` varchar(36) NOT NULL,
  `granted_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `magic_links`
--

CREATE TABLE `magic_links` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `email` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Stores magic link tokens for passwordless email authentication';

-- --------------------------------------------------------

--
-- Table structure for table `meetings`
--

CREATE TABLE `meetings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `meeting_code` varchar(16) NOT NULL,
  `title` varchar(255) NOT NULL,
  `created_by` varchar(36) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `meeting_messages`
--

CREATE TABLE `meeting_messages` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `meeting_id` bigint(20) UNSIGNED NOT NULL,
  `sender_id` varchar(36) DEFAULT NULL,
  `sender_name` varchar(255) DEFAULT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `meeting_participants`
--

CREATE TABLE `meeting_participants` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `meeting_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` varchar(36) DEFAULT NULL,
  `display_name` varchar(255) DEFAULT NULL,
  `role` enum('host','cohost','participant') NOT NULL DEFAULT 'participant',
  `joined_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `left_at` timestamp NULL DEFAULT NULL,
  `is_connected` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `meeting_recordings`
--

CREATE TABLE `meeting_recordings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `meeting_id` bigint(20) UNSIGNED NOT NULL,
  `storage_path` varchar(512) NOT NULL,
  `duration_seconds` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `message_delivery_status`
--

CREATE TABLE `message_delivery_status` (
  `id` varchar(36) NOT NULL,
  `message_id` varchar(36) NOT NULL,
  `user_id` varchar(36) NOT NULL,
  `status` enum('delivered','read') NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `message_mentions`
--

CREATE TABLE `message_mentions` (
  `id` varchar(36) NOT NULL,
  `message_id` varchar(36) NOT NULL,
  `mentioned_user_id` varchar(36) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `message_polls`
--

CREATE TABLE `message_polls` (
  `id` varchar(36) NOT NULL,
  `message_id` varchar(36) NOT NULL,
  `question` text NOT NULL,
  `created_by` varchar(36) NOT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `allow_multiple_answers` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `message_reactions`
--

CREATE TABLE `message_reactions` (
  `id` varchar(36) NOT NULL,
  `message_id` varchar(36) NOT NULL,
  `user_id` varchar(36) NOT NULL,
  `emoji` varchar(10) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `message_read_status`
--

CREATE TABLE `message_read_status` (
  `message_id` varchar(36) NOT NULL,
  `user_id` varchar(36) NOT NULL,
  `read_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `type` enum('new_bug','status_change') NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `bug_id` int(11) NOT NULL,
  `bug_title` varchar(255) NOT NULL,
  `status` varchar(50) DEFAULT NULL,
  `created_by` varchar(100) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL,
  `user_id` varchar(36) NOT NULL COMMENT 'References users.id (UUID)',
  `email` varchar(255) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `id` int(11) NOT NULL,
  `permission_key` varchar(100) NOT NULL,
  `permission_name` varchar(100) NOT NULL,
  `permission_description` text DEFAULT NULL,
  `category` varchar(50) NOT NULL,
  `scope` enum('global','project') NOT NULL DEFAULT 'global',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `permissions`
--

INSERT INTO `permissions` (`id`, `permission_key`, `permission_name`, `permission_description`, `category`, `scope`, `created_at`) VALUES
(1, 'BUGS_VIEW_ALL', 'View All Bugs', 'View all bugs across all projects', 'Bugs', 'global', '2025-10-25 11:51:00'),
(2, 'BUGS_VIEW_OWN', 'View Own Bugs', 'View only bugs reported by the user', 'Bugs', 'global', '2025-10-25 11:51:00'),
(3, 'BUGS_CREATE', 'Create Bugs', 'Create new bug reports', 'Bugs', 'global', '2025-10-25 11:51:00'),
(4, 'BUGS_EDIT_ALL', 'Edit All Bugs', 'Edit any bug report', 'Bugs', 'global', '2025-10-25 11:51:00'),
(5, 'BUGS_EDIT_OWN', 'Edit Own Bugs', 'Edit only bugs reported by the user', 'Bugs', 'global', '2025-10-25 11:51:00'),
(6, 'BUGS_DELETE', 'Delete Bugs', 'Delete bug reports', 'Bugs', 'global', '2025-10-25 11:51:00'),
(7, 'BUGS_CHANGE_STATUS', 'Change Bug Status', 'Update bug status (pending, in_progress, fixed, etc.)', 'Bugs', 'global', '2025-10-25 11:51:00'),
(8, 'BUGS_ASSIGN', 'Assign Bugs', 'Assign bugs to other users', 'Bugs', 'global', '2025-10-25 11:51:00'),
(9, 'USERS_VIEW', 'View Users', 'View user list and details', 'Users', 'global', '2025-10-25 11:51:00'),
(10, 'USERS_CREATE', 'Create Users', 'Create new user accounts', 'Users', 'global', '2025-10-25 11:51:00'),
(11, 'USERS_EDIT', 'Edit Users', 'Edit user information', 'Users', 'global', '2025-10-25 11:51:00'),
(12, 'USERS_DELETE', 'Delete Users', 'Delete user accounts', 'Users', 'global', '2025-10-25 11:51:00'),
(13, 'USERS_CHANGE_PASSWORD', 'Change User Passwords', 'Reset or change user passwords', 'Users', 'global', '2025-10-25 11:51:00'),
(14, 'USERS_MANAGE_PERMISSIONS', 'Manage User Permissions', 'Assign and modify user permissions', 'Users', 'global', '2025-10-25 11:51:00'),
(15, 'USERS_IMPERSONATE', 'Impersonate Users', 'Login as another user for support', 'Users', 'global', '2025-10-25 11:51:00'),
(16, 'PROJECTS_VIEW_ALL', 'View All Projects', 'View all projects in the system', 'Projects', 'global', '2025-10-25 11:51:00'),
(17, 'PROJECTS_VIEW_ASSIGNED', 'View Assigned Projects', 'View only projects where user is a member', 'Projects', 'global', '2025-10-25 11:51:00'),
(18, 'PROJECTS_CREATE', 'Create Projects', 'Create new projects', 'Projects', 'global', '2025-10-25 11:51:00'),
(19, 'PROJECTS_EDIT', 'Edit Projects', 'Edit project information', 'Projects', 'global', '2025-10-25 11:51:00'),
(20, 'PROJECTS_DELETE', 'Delete Projects', 'Delete projects', 'Projects', 'global', '2025-10-25 11:51:00'),
(21, 'PROJECTS_MANAGE_MEMBERS', 'Manage Project Members', 'Add/remove members from projects', 'Projects', 'global', '2025-10-25 11:51:00'),
(22, 'PROJECTS_ARCHIVE', 'Archive Projects', 'Archive or unarchive projects', 'Projects', 'global', '2025-10-25 11:51:00'),
(23, 'DOCS_VIEW', 'View Documentation', 'View project documentation', 'Documentation', 'global', '2025-10-25 11:51:00'),
(24, 'DOCS_CREATE', 'Create Documentation', 'Create new documentation', 'Documentation', 'global', '2025-10-25 11:51:00'),
(25, 'DOCS_EDIT', 'Edit Documentation', 'Edit existing documentation', 'Documentation', 'global', '2025-10-25 11:51:00'),
(26, 'DOCS_DELETE', 'Delete Documentation', 'Delete documentation', 'Documentation', 'global', '2025-10-25 11:51:00'),
(27, 'TASKS_VIEW_ALL', 'View All Tasks', 'View all tasks across all projects', 'Tasks', 'global', '2025-10-25 11:51:00'),
(28, 'TASKS_VIEW_ASSIGNED', 'View Assigned Tasks', 'View only tasks assigned to the user', 'Tasks', 'global', '2025-10-25 11:51:00'),
(29, 'TASKS_CREATE', 'Create Tasks', 'Create new tasks', 'Tasks', 'global', '2025-10-25 11:51:00'),
(30, 'TASKS_EDIT', 'Edit Tasks', 'Edit task information', 'Tasks', 'global', '2025-10-25 11:51:00'),
(31, 'TASKS_DELETE', 'Delete Tasks', 'Delete tasks', 'Tasks', 'global', '2025-10-25 11:51:00'),
(32, 'TASKS_ASSIGN', 'Assign Tasks', 'Assign tasks to users', 'Tasks', 'global', '2025-10-25 11:51:00'),
(33, 'UPDATES_VIEW', 'View Updates', 'View project updates and changelogs', 'Updates', 'global', '2025-10-25 11:51:00'),
(34, 'UPDATES_CREATE', 'Create Updates', 'Create new project updates', 'Updates', 'global', '2025-10-25 11:51:00'),
(35, 'UPDATES_EDIT', 'Edit Updates', 'Edit existing updates', 'Updates', 'global', '2025-10-25 11:51:00'),
(36, 'UPDATES_DELETE', 'Delete Updates', 'Delete updates', 'Updates', 'global', '2025-10-25 11:51:00'),
(37, 'UPDATES_APPROVE', 'Approve Updates', 'Approve or reject update requests', 'Updates', 'global', '2025-10-25 11:51:00'),
(38, 'SETTINGS_VIEW', 'View Settings', 'View application settings', 'Settings', 'global', '2025-10-25 11:51:00'),
(39, 'SETTINGS_EDIT', 'Edit Settings', 'Modify application settings', 'Settings', 'global', '2025-10-25 11:51:00'),
(40, 'ROLES_MANAGE', 'Manage Roles', 'Create, edit, and delete custom roles', 'Settings', 'global', '2025-10-25 11:51:00'),
(41, 'ANNOUNCEMENTS_MANAGE', 'Manage Announcements', 'Create and manage system announcements', 'Settings', 'global', '2025-10-25 11:51:00'),
(42, 'MESSAGING_VIEW', 'View Messages', 'View chat messages and conversations', 'Messaging', 'global', '2025-10-25 11:51:00'),
(43, 'MESSAGING_SEND', 'Send Messages', 'Send messages in chat groups', 'Messaging', 'global', '2025-10-25 11:51:00'),
(44, 'MESSAGING_DELETE', 'Delete Messages', 'Delete chat messages', 'Messaging', 'global', '2025-10-25 11:51:00'),
(45, 'MESSAGING_MANAGE_GROUPS', 'Manage Chat Groups', 'Create and manage chat groups', 'Messaging', 'global', '2025-10-25 11:51:00'),
(46, 'MEETINGS_CREATE', 'Create Meetings', 'Create new meetings', 'Meetings', 'global', '2025-10-25 11:51:00'),
(47, 'MEETINGS_JOIN', 'Join Meetings', 'Join existing meetings', 'Meetings', 'global', '2025-10-25 11:51:00'),
(48, 'MEETINGS_MANAGE', 'Manage Meetings', 'Manage meeting settings and participants', 'Meetings', 'global', '2025-10-25 11:51:00'),
(49, 'SUPER_ADMIN', 'Super Administrator', 'Bypass all permission checks - full system access', 'System', 'global', '2025-10-25 11:51:00');

-- --------------------------------------------------------

--
-- Table structure for table `poll_options`
--

CREATE TABLE `poll_options` (
  `id` varchar(36) NOT NULL,
  `poll_id` varchar(36) NOT NULL,
  `option_text` varchar(255) NOT NULL,
  `option_order` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `poll_votes`
--

CREATE TABLE `poll_votes` (
  `id` varchar(36) NOT NULL,
  `poll_id` varchar(36) NOT NULL,
  `option_id` varchar(36) NOT NULL,
  `user_id` varchar(36) NOT NULL,
  `voted_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `projects`
--

CREATE TABLE `projects` (
  `id` varchar(36) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active','completed','archived') NOT NULL DEFAULT 'active',
  `created_by` varchar(36) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `project_activities`
--

CREATE TABLE `project_activities` (
  `id` int(11) NOT NULL,
  `user_id` varchar(255) NOT NULL COMMENT 'References users.id',
  `project_id` varchar(36) DEFAULT NULL,
  `activity_type` varchar(50) NOT NULL COMMENT 'Type of activity (bug_reported, member_added, etc.)',
  `description` text NOT NULL COMMENT 'Human-readable description of the activity',
  `related_id` varchar(255) DEFAULT NULL COMMENT 'Optional reference to related entity (bug, task, etc.)',
  `metadata` text DEFAULT NULL COMMENT 'JSON metadata for additional activity context',
  `created_at` timestamp NULL DEFAULT current_timestamp() COMMENT 'When the activity occurred'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `project_members`
--

CREATE TABLE `project_members` (
  `project_id` varchar(36) NOT NULL,
  `user_id` varchar(36) NOT NULL,
  `role` enum('manager','developer','tester') NOT NULL,
  `joined_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` int(11) NOT NULL,
  `role_name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `is_system_role` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `role_name`, `description`, `is_system_role`, `created_at`, `updated_at`) VALUES
(1, 'Admin', 'System administrator with full access', 1, '2025-10-25 11:51:00', '2025-10-25 11:51:00'),
(2, 'Developer', 'Software developer with project and bug management access', 1, '2025-10-25 11:51:00', '2025-10-25 11:51:00'),
(3, 'Tester', 'Quality assurance tester with bug reporting and testing access', 1, '2025-10-25 11:51:00', '2025-10-25 11:51:00');

-- --------------------------------------------------------

--
-- Table structure for table `role_permissions`
--

CREATE TABLE `role_permissions` (
  `id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `role_permissions`
--

INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`) VALUES
(1298, 1, 8, '2025-10-27 20:13:49'),
(1299, 1, 7, '2025-10-27 20:13:49'),
(1300, 1, 3, '2025-10-27 20:13:49'),
(1301, 1, 6, '2025-10-27 20:13:49'),
(1302, 1, 4, '2025-10-27 20:13:49'),
(1303, 1, 5, '2025-10-27 20:13:49'),
(1304, 1, 1, '2025-10-27 20:13:49'),
(1305, 1, 2, '2025-10-27 20:13:49'),
(1306, 1, 24, '2025-10-27 20:13:49'),
(1307, 1, 26, '2025-10-27 20:13:49'),
(1308, 1, 25, '2025-10-27 20:13:49'),
(1309, 1, 23, '2025-10-27 20:13:49'),
(1310, 1, 46, '2025-10-27 20:13:49'),
(1311, 1, 47, '2025-10-27 20:13:49'),
(1312, 1, 48, '2025-10-27 20:13:49'),
(1313, 1, 44, '2025-10-27 20:13:49'),
(1314, 1, 45, '2025-10-27 20:13:49'),
(1315, 1, 43, '2025-10-27 20:13:49'),
(1316, 1, 42, '2025-10-27 20:13:49'),
(1317, 1, 22, '2025-10-27 20:13:49'),
(1318, 1, 18, '2025-10-27 20:13:49'),
(1319, 1, 20, '2025-10-27 20:13:49'),
(1320, 1, 19, '2025-10-27 20:13:49'),
(1321, 1, 21, '2025-10-27 20:13:49'),
(1322, 1, 16, '2025-10-27 20:13:49'),
(1323, 1, 17, '2025-10-27 20:13:49'),
(1324, 1, 39, '2025-10-27 20:13:49'),
(1325, 1, 41, '2025-10-27 20:13:49'),
(1326, 1, 40, '2025-10-27 20:13:49'),
(1327, 1, 38, '2025-10-27 20:13:49'),
(1328, 1, 49, '2025-10-27 20:13:49'),
(1329, 1, 32, '2025-10-27 20:13:49'),
(1330, 1, 29, '2025-10-27 20:13:49'),
(1331, 1, 31, '2025-10-27 20:13:49'),
(1332, 1, 30, '2025-10-27 20:13:49'),
(1333, 1, 27, '2025-10-27 20:13:49'),
(1334, 1, 28, '2025-10-27 20:13:49'),
(1335, 1, 37, '2025-10-27 20:13:49'),
(1336, 1, 34, '2025-10-27 20:13:49'),
(1337, 1, 36, '2025-10-27 20:13:49'),
(1338, 1, 35, '2025-10-27 20:13:49'),
(1339, 1, 33, '2025-10-27 20:13:49'),
(1340, 1, 13, '2025-10-27 20:13:49'),
(1341, 1, 10, '2025-10-27 20:13:49'),
(1342, 1, 12, '2025-10-27 20:13:49'),
(1343, 1, 11, '2025-10-27 20:13:49'),
(1344, 1, 15, '2025-10-27 20:13:49'),
(1345, 1, 14, '2025-10-27 20:13:49'),
(1346, 1, 9, '2025-10-27 20:13:49'),
(1347, 2, 7, '2025-10-27 20:14:14'),
(1348, 2, 3, '2025-10-27 20:14:14'),
(1349, 2, 6, '2025-10-27 20:14:14'),
(1350, 2, 4, '2025-10-27 20:14:14'),
(1351, 2, 1, '2025-10-27 20:14:14'),
(1352, 2, 2, '2025-10-27 20:14:14'),
(1353, 2, 26, '2025-10-27 20:14:14'),
(1354, 2, 25, '2025-10-27 20:14:14'),
(1355, 2, 23, '2025-10-27 20:14:14'),
(1356, 2, 22, '2025-10-27 20:14:14'),
(1357, 2, 18, '2025-10-27 20:14:14'),
(1358, 2, 20, '2025-10-27 20:14:14'),
(1359, 2, 19, '2025-10-27 20:14:14'),
(1360, 2, 16, '2025-10-27 20:14:14'),
(1361, 2, 17, '2025-10-27 20:14:14'),
(1362, 2, 32, '2025-10-27 20:14:14'),
(1363, 2, 29, '2025-10-27 20:14:14'),
(1364, 2, 31, '2025-10-27 20:14:14'),
(1365, 2, 30, '2025-10-27 20:14:14'),
(1366, 2, 27, '2025-10-27 20:14:14'),
(1367, 2, 28, '2025-10-27 20:14:14'),
(1368, 2, 37, '2025-10-27 20:14:14'),
(1369, 2, 34, '2025-10-27 20:14:14'),
(1370, 2, 36, '2025-10-27 20:14:14'),
(1371, 2, 35, '2025-10-27 20:14:14'),
(1372, 2, 33, '2025-10-27 20:14:14'),
(1373, 3, 7, '2025-10-27 20:15:07'),
(1374, 3, 3, '2025-10-27 20:15:07'),
(1375, 3, 4, '2025-10-27 20:15:07'),
(1376, 3, 5, '2025-10-27 20:15:07'),
(1377, 3, 1, '2025-10-27 20:15:07'),
(1378, 3, 24, '2025-10-27 20:15:07'),
(1379, 3, 26, '2025-10-27 20:15:07'),
(1380, 3, 25, '2025-10-27 20:15:07'),
(1381, 3, 23, '2025-10-27 20:15:07'),
(1382, 3, 46, '2025-10-27 20:15:07'),
(1383, 3, 47, '2025-10-27 20:15:07'),
(1384, 3, 48, '2025-10-27 20:15:07'),
(1385, 3, 22, '2025-10-27 20:15:07'),
(1386, 3, 18, '2025-10-27 20:15:07'),
(1387, 3, 17, '2025-10-27 20:15:07'),
(1388, 3, 32, '2025-10-27 20:15:07'),
(1389, 3, 29, '2025-10-27 20:15:07'),
(1390, 3, 31, '2025-10-27 20:15:07'),
(1391, 3, 30, '2025-10-27 20:15:07'),
(1392, 3, 27, '2025-10-27 20:15:07'),
(1393, 3, 28, '2025-10-27 20:15:07'),
(1394, 3, 37, '2025-10-27 20:15:07'),
(1395, 3, 34, '2025-10-27 20:15:07'),
(1396, 3, 36, '2025-10-27 20:15:07'),
(1397, 3, 35, '2025-10-27 20:15:07'),
(1398, 3, 33, '2025-10-27 20:15:07');

-- --------------------------------------------------------

--
-- Table structure for table `session_activities`
--

CREATE TABLE `session_activities` (
  `id` int(11) NOT NULL,
  `session_id` int(11) NOT NULL,
  `activity_type` enum('work','break','meeting','training','other') DEFAULT 'work',
  `start_time` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `end_time` timestamp NULL DEFAULT NULL,
  `activity_notes` text DEFAULT NULL,
  `project_id` varchar(36) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `session_pauses`
--

CREATE TABLE `session_pauses` (
  `id` int(11) NOT NULL,
  `session_id` int(11) NOT NULL,
  `pause_start` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `pause_end` timestamp NULL DEFAULT NULL,
  `pause_reason` varchar(255) DEFAULT 'break',
  `duration_seconds` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` int(11) NOT NULL,
  `key_name` varchar(255) DEFAULT NULL,
  `value` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`id`, `key_name`, `value`) VALUES
(1, 'email_notifications_enabled', '0');

-- --------------------------------------------------------

--
-- Table structure for table `shared_tasks`
--

CREATE TABLE `shared_tasks` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `created_by` varchar(36) NOT NULL,
  `assigned_to` varchar(36) NOT NULL,
  `approved_by` varchar(36) DEFAULT NULL,
  `completed_by` varchar(36) DEFAULT NULL,
  `project_id` varchar(36) DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `status` enum('pending','in_progress','completed','approved') DEFAULT 'pending',
  `priority` enum('low','medium','high') DEFAULT 'medium',
  `completed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `shared_task_assignees`
--

CREATE TABLE `shared_task_assignees` (
  `id` int(11) NOT NULL,
  `shared_task_id` int(11) NOT NULL,
  `assigned_to` varchar(36) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `shared_task_projects`
--

CREATE TABLE `shared_task_projects` (
  `id` int(11) NOT NULL,
  `shared_task_id` int(11) NOT NULL,
  `project_id` varchar(36) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `starred_messages`
--

CREATE TABLE `starred_messages` (
  `id` varchar(36) NOT NULL,
  `message_id` varchar(36) NOT NULL,
  `user_id` varchar(36) NOT NULL,
  `group_id` varchar(36) NOT NULL,
  `starred_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `status_views`
--

CREATE TABLE `status_views` (
  `id` varchar(36) NOT NULL,
  `status_id` varchar(36) NOT NULL,
  `viewer_id` varchar(36) NOT NULL,
  `viewed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `typing_indicators`
--

CREATE TABLE `typing_indicators` (
  `id` varchar(36) NOT NULL,
  `group_id` varchar(36) NOT NULL,
  `user_id` varchar(36) NOT NULL,
  `is_typing` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `updates`
--

CREATE TABLE `updates` (
  `id` varchar(36) NOT NULL,
  `project_id` varchar(36) NOT NULL,
  `title` varchar(255) NOT NULL,
  `type` enum('feature','updation','maintenance') NOT NULL,
  `description` text NOT NULL,
  `created_by` varchar(36) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `status` enum('pending','approved','declined') DEFAULT 'pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` varchar(36) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `google_sub` varchar(255) DEFAULT NULL COMMENT 'Google User ID (sub claim) from OAuth token',
  `phone` varchar(20) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `password_changed_at` timestamp NULL DEFAULT NULL,
  `role` enum('admin','developer','tester','user') NOT NULL DEFAULT 'user',
  `role_id` int(11) DEFAULT NULL,
  `is_online` tinyint(1) NOT NULL DEFAULT 0,
  `last_seen` timestamp NULL DEFAULT NULL,
  `profile_picture` varchar(500) DEFAULT NULL,
  `profile_picture_url` varchar(255) DEFAULT NULL COMMENT 'URL of user profile picture from Google',
  `status_message` varchar(255) DEFAULT NULL COMMENT 'WhatsApp-like status text',
  `show_online_status` tinyint(1) NOT NULL DEFAULT 1,
  `show_last_seen` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_login_at` datetime DEFAULT NULL COMMENT 'Timestamp of last successful login',
  `fcm_token` varchar(255) DEFAULT NULL,
  `last_active_at` datetime DEFAULT NULL COMMENT 'Timestamp of last heartbeat for presence tracking'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `google_sub`, `phone`, `password`, `password_changed_at`, `role`, `role_id`, `is_online`, `last_seen`, `profile_picture`, `profile_picture_url`, `status_message`, `show_online_status`, `show_last_seen`, `created_at`, `updated_at`, `last_login_at`, `fcm_token`, `last_active_at`) VALUES
('608dc9d1-26e0-441d-8144-45f74c53a846', 'moajmalnk', 'moajmalnk@gmail.com', NULL, '+919526271123', '$2y$10$2oXXl0bW2MeouQp6FxKmn.G40QjwpFgdllMvxe2Ho0dv4K15I34Ne', NULL, 'admin', 1, 0, NULL, NULL, NULL, NULL, 1, 1, '2025-04-10 12:22:47', '2025-11-02 11:02:58', '2025-10-24 11:51:32', 'ematIBhwKIzG-LIs87i8oT:APA91bFcsxXQMVrzbcbwE34nP_wl-8l8KoXu7ZATn7X5GJAI2ZPD-gfrk0esR4X9o8QKMh8vYKrSnYmurKnBL3jOwRdLUy9xu-DDePCq1MDWfQj3V-Sh8J8', '2025-11-02 11:02:58'),
('c18ce191-e34b-4ca7-b69b-6a78488d3de5', 'tester', 'tester@gmail.com', NULL, '+918848344627', '$2y$10$hE45Bi6zB5YT6tA6hvhqsuv80ms082Akpef1fcm7vpCO3Wsl6YRNm', NULL, 'tester', 3, 0, NULL, NULL, NULL, NULL, 1, 1, '2025-08-22 17:57:27', '2025-11-02 11:02:58', '2025-09-10 18:42:52', 'ematIBhwKIzG-LIs87i8oT:APA91bFcsxXQMVrzbcbwE34nP_wl-8l8KoXu7ZATn7X5GJAI2ZPD-gfrk0esR4X9o8QKMh8vYKrSnYmurKnBL3jOwRdLUy9xu-DDePCq1MDWfQj3V-Sh8J8', '2025-11-02 11:02:58'),
('d84019a3-575f-403c-aa12-02482422bcfa', 'developer', 'developer@gmail.com', NULL, '+919526271192', '$2y$10$2eZYNi3hk3Am2waJAcfQuuD2m4WBRV74oJtB30oVXvMXag.93unkq', NULL, 'developer', 2, 0, NULL, NULL, NULL, NULL, 1, 1, '2025-04-18 17:34:28', '2025-11-02 11:02:58', '2025-10-08 01:52:51', 'ematIBhwKIzG-LIs87i8oT:APA91bFcsxXQMVrzbcbwE34nP_wl-8l8KoXu7ZATn7X5GJAI2ZPD-gfrk0esR4X9o8QKMh8vYKrSnYmurKnBL3jOwRdLUy9xu-DDePCq1MDWfQj3V-Sh8J8', '2025-11-02 11:02:58');

--
-- Triggers `users`
--
DELIMITER $$
CREATE TRIGGER `tr_create_feedback_tracking_after_user_insert` AFTER INSERT ON `users` FOR EACH ROW BEGIN
    INSERT INTO user_feedback_tracking (user_id, has_submitted_feedback, first_submission_at)
    VALUES (NEW.id, FALSE, NULL);
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `user_activity_sessions`
--

CREATE TABLE `user_activity_sessions` (
  `id` varchar(36) NOT NULL,
  `user_id` varchar(36) NOT NULL,
  `session_start` datetime NOT NULL,
  `session_end` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `session_duration_minutes` int(11) DEFAULT NULL,
  `activity_type` enum('work','break','meeting','other') DEFAULT 'work',
  `project_id` varchar(36) DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tracks user activity sessions for calculating active hours and presence';

-- --------------------------------------------------------

--
-- Table structure for table `user_documents`
--

CREATE TABLE `user_documents` (
  `id` int(11) NOT NULL,
  `doc_title` varchar(500) NOT NULL COMMENT 'Document title',
  `google_doc_id` varchar(255) NOT NULL COMMENT 'Google Document ID',
  `google_doc_url` text NOT NULL COMMENT 'Full Google Docs edit URL',
  `creator_user_id` varchar(255) NOT NULL COMMENT 'BugRicer user ID (UUID)',
  `template_id` int(11) DEFAULT NULL COMMENT 'Reference to doc_templates if created from template',
  `doc_type` varchar(50) DEFAULT 'general' COMMENT 'Document type: general, meeting, notes, etc.',
  `is_archived` tinyint(1) DEFAULT 0 COMMENT 'Whether document is archived',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_accessed_at` timestamp NULL DEFAULT NULL COMMENT 'Last time document was opened'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='User-created general documents';

-- --------------------------------------------------------

--
-- Table structure for table `user_feedback`
--

CREATE TABLE `user_feedback` (
  `id` varchar(36) NOT NULL,
  `user_id` varchar(36) NOT NULL,
  `rating` tinyint(1) NOT NULL COMMENT 'Rating from 1-5 (1=angry, 2=sad, 3=neutral, 4=happy, 5=star-struck)',
  `feedback_text` text DEFAULT NULL COMMENT 'Optional text feedback',
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_feedback_tracking`
--

CREATE TABLE `user_feedback_tracking` (
  `user_id` varchar(36) NOT NULL,
  `has_submitted_feedback` tinyint(1) NOT NULL DEFAULT 0,
  `first_submission_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_otps`
--

CREATE TABLE `user_otps` (
  `id` int(11) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `otp` varchar(6) NOT NULL,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_permissions`
--

CREATE TABLE `user_permissions` (
  `id` int(11) NOT NULL,
  `user_id` varchar(36) NOT NULL,
  `permission_id` int(11) NOT NULL,
  `project_id` varchar(36) DEFAULT NULL,
  `granted` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_status`
--

CREATE TABLE `user_status` (
  `id` varchar(36) NOT NULL,
  `user_id` varchar(36) NOT NULL,
  `media_type` enum('text','image','video') NOT NULL DEFAULT 'text',
  `media_url` varchar(500) DEFAULT NULL,
  `text_content` text DEFAULT NULL,
  `background_color` varchar(7) DEFAULT NULL COMMENT 'Hex color for text status',
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT '24 hours from creation',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_tasks`
--

CREATE TABLE `user_tasks` (
  `id` int(11) NOT NULL,
  `user_id` varchar(36) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `project_id` varchar(36) DEFAULT NULL,
  `priority` enum('low','medium','high') DEFAULT 'medium',
  `status` enum('todo','in_progress','done','blocked') DEFAULT 'todo',
  `due_date` date DEFAULT NULL,
  `period` enum('daily','weekly','monthly','yearly','custom') DEFAULT 'daily',
  `expected_hours` decimal(6,2) DEFAULT 0.00,
  `spent_hours` decimal(10,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `voice_notes`
--

CREATE TABLE `voice_notes` (
  `id` int(11) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `duration` int(11) NOT NULL DEFAULT 0,
  `sent_by` varchar(36) NOT NULL,
  `status` enum('sent','delivered','read','failed') DEFAULT 'sent',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `voice_note_history`
--

CREATE TABLE `voice_note_history` (
  `id` int(11) NOT NULL,
  `voice_note_id` int(11) NOT NULL,
  `status` enum('sent','delivered','read','failed') NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `work_sessions`
--

CREATE TABLE `work_sessions` (
  `id` int(11) NOT NULL,
  `user_id` varchar(36) NOT NULL,
  `submission_date` date NOT NULL,
  `check_in_time` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `check_out_time` timestamp NULL DEFAULT NULL,
  `total_duration_seconds` int(11) DEFAULT 0,
  `net_duration_seconds` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `session_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `work_submissions`
--

CREATE TABLE `work_submissions` (
  `id` int(11) NOT NULL,
  `user_id` varchar(36) NOT NULL,
  `submission_date` date NOT NULL,
  `start_time` time DEFAULT NULL,
  `hours_today` decimal(6,2) NOT NULL DEFAULT 0.00,
  `total_working_days` int(11) DEFAULT NULL,
  `total_hours_cumulative` decimal(10,2) DEFAULT NULL,
  `completed_tasks` mediumtext DEFAULT NULL,
  `pending_tasks` mediumtext DEFAULT NULL,
  `ongoing_tasks` mediumtext DEFAULT NULL,
  `notes` mediumtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activities`
--
ALTER TABLE `activities`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_activities_user_type` (`user_id`,`type`),
  ADD KEY `idx_activities_created_at` (`created_at`),
  ADD KEY `idx_activities_user_dashboard` (`user_id`,`type`,`created_at`),
  ADD KEY `idx_activities_type_entity_id` (`type`,`entity_id`);

--
-- Indexes for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_activity_log_user_action` (`user_id`,`action_type`),
  ADD KEY `idx_activity_log_entity` (`entity_type`,`entity_id`),
  ADD KEY `idx_activity_log_created_at` (`created_at`);

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
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_announcements_active_expiry` (`is_active`,`expiry_date`),
  ADD KEY `idx_announcements_created_at` (`created_at`);
ALTER TABLE `announcements` ADD FULLTEXT KEY `ft_announcements_search` (`title`,`content`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_ip_address` (`ip_address`);

--
-- Indexes for table `blocked_users`
--
ALTER TABLE `blocked_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_blocker_blocked` (`blocker_id`,`blocked_id`),
  ADD KEY `idx_blocked_users_blocker` (`blocker_id`),
  ADD KEY `idx_blocked_users_blocked` (`blocked_id`);

--
-- Indexes for table `broadcast_lists`
--
ALTER TABLE `broadcast_lists`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_broadcast_lists_creator` (`created_by`);

--
-- Indexes for table `broadcast_recipients`
--
ALTER TABLE `broadcast_recipients`
  ADD PRIMARY KEY (`broadcast_id`,`user_id`),
  ADD KEY `idx_broadcast_recipients_user` (`user_id`);

--
-- Indexes for table `bugs`
--
ALTER TABLE `bugs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_id` (`project_id`),
  ADD KEY `reported_by` (`reported_by`),
  ADD KEY `idx_bugs_updated_by` (`updated_by`),
  ADD KEY `idx_bugs_status` (`status`),
  ADD KEY `idx_bugs_updated_by_status` (`updated_by`,`status`),
  ADD KEY `idx_bugs_reported_by` (`reported_by`),
  ADD KEY `idx_bugs_project_id` (`project_id`),
  ADD KEY `idx_bugs_created_at` (`created_at`),
  ADD KEY `idx_bugs_project_created` (`project_id`,`created_at`),
  ADD KEY `idx_bugs_status_updated_by` (`status`,`updated_by`),
  ADD KEY `idx_bugs_project_status_created` (`project_id`,`status`,`created_at`),
  ADD KEY `idx_bugs_reporter_created` (`reported_by`,`created_at`),
  ADD KEY `idx_bugs_project_status_priority` (`project_id`,`status`,`priority`),
  ADD KEY `idx_bugs_reported_by_status` (`reported_by`,`status`),
  ADD KEY `idx_bugs_created_at_status` (`created_at`,`status`),
  ADD KEY `idx_bugs_updated_at` (`updated_at`),
  ADD KEY `idx_bugs_priority_status` (`priority`,`status`),
  ADD KEY `idx_bugs_project_created_status` (`project_id`,`created_at`,`status`),
  ADD KEY `idx_bugs_dashboard` (`project_id`,`status`,`priority`,`created_at`),
  ADD KEY `idx_bugs_covering_list` (`project_id`,`status`,`created_at`,`id`,`title`,`priority`,`reported_by`,`updated_by`),
  ADD KEY `idx_bugs_status_id_project_priority_created` (`status`,`id`,`project_id`,`priority`,`created_at`),
  ADD KEY `idx_bugs_expected_result` (`expected_result`(100)),
  ADD KEY `idx_bugs_actual_result` (`actual_result`(100));
ALTER TABLE `bugs` ADD FULLTEXT KEY `ft_bugs_search` (`title`,`description`);

--
-- Indexes for table `bug_attachments`
--
ALTER TABLE `bug_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `bug_id` (`bug_id`),
  ADD KEY `uploaded_by` (`uploaded_by`),
  ADD KEY `idx_bug_attachments_bug_id` (`bug_id`),
  ADD KEY `idx_bug_attachments_uploaded_by` (`uploaded_by`),
  ADD KEY `idx_bug_attachments_bug_uploaded` (`bug_id`,`uploaded_by`),
  ADD KEY `idx_bug_attachments_created_at` (`created_at`);

--
-- Indexes for table `bug_documents`
--
ALTER TABLE `bug_documents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_bug_doc` (`bug_id`,`google_doc_id`),
  ADD KEY `idx_bug_id` (`bug_id`),
  ADD KEY `idx_created_by` (`created_by`),
  ADD KEY `idx_template` (`template_id`);

--
-- Indexes for table `call_logs`
--
ALTER TABLE `call_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_call_logs_caller` (`caller_id`),
  ADD KEY `idx_call_logs_group` (`group_id`),
  ADD KEY `idx_call_logs_started` (`started_at`);

--
-- Indexes for table `call_participants`
--
ALTER TABLE `call_participants`
  ADD PRIMARY KEY (`call_id`,`user_id`),
  ADD KEY `idx_call_participants_user` (`user_id`);

--
-- Indexes for table `chat_groups`
--
ALTER TABLE `chat_groups`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_chat_groups_project_id` (`project_id`),
  ADD KEY `idx_chat_groups_created_by` (`created_by`),
  ADD KEY `idx_chat_groups_is_active` (`is_active`),
  ADD KEY `idx_chat_groups_project_active` (`project_id`,`is_active`),
  ADD KEY `idx_chat_groups_is_archived` (`is_archived`);

--
-- Indexes for table `chat_group_members`
--
ALTER TABLE `chat_group_members`
  ADD PRIMARY KEY (`group_id`,`user_id`),
  ADD KEY `idx_chat_group_members_user_id` (`user_id`),
  ADD KEY `idx_chat_group_members_group_id` (`group_id`),
  ADD KEY `idx_chat_group_members_user_group` (`user_id`,`group_id`);

--
-- Indexes for table `chat_messages`
--
ALTER TABLE `chat_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_chat_messages_group_id` (`group_id`),
  ADD KEY `idx_chat_messages_sender_id` (`sender_id`),
  ADD KEY `idx_chat_messages_created_at` (`created_at`),
  ADD KEY `idx_chat_messages_reply_to` (`reply_to_message_id`),
  ADD KEY `idx_chat_messages_is_deleted` (`is_deleted`),
  ADD KEY `idx_chat_messages_is_pinned` (`is_pinned`),
  ADD KEY `chat_messages_ibfk_4` (`pinned_by`),
  ADD KEY `idx_chat_messages_group_created` (`group_id`,`created_at`),
  ADD KEY `idx_chat_messages_sender_created` (`sender_id`,`created_at`),
  ADD KEY `idx_chat_messages_is_starred` (`is_starred`),
  ADD KEY `idx_chat_messages_is_forwarded` (`is_forwarded`),
  ADD KEY `idx_chat_messages_delivery_status` (`delivery_status`),
  ADD KEY `idx_chat_messages_media_type` (`media_type`);

--
-- Indexes for table `disappearing_messages_settings`
--
ALTER TABLE `disappearing_messages_settings`
  ADD PRIMARY KEY (`group_id`),
  ADD KEY `disappearing_messages_settings_ibfk_2` (`enabled_by`);

--
-- Indexes for table `doc_templates`
--
ALTER TABLE `doc_templates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_category` (`category`),
  ADD KEY `idx_active` (`is_active`);

--
-- Indexes for table `google_tokens`
--
ALTER TABLE `google_tokens`
  ADD PRIMARY KEY (`google_user_id`),
  ADD KEY `idx_bugricer_user` (`bugricer_user_id`);

--
-- Indexes for table `group_admins`
--
ALTER TABLE `group_admins`
  ADD PRIMARY KEY (`group_id`,`user_id`),
  ADD KEY `idx_group_admins_user` (`user_id`),
  ADD KEY `group_admins_ibfk_3` (`granted_by`);

--
-- Indexes for table `magic_links`
--
ALTER TABLE `magic_links`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD KEY `idx_token` (`token`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_expires_at` (`expires_at`),
  ADD KEY `idx_cleanup` (`expires_at`,`used_at`);

--
-- Indexes for table `meetings`
--
ALTER TABLE `meetings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `meeting_code` (`meeting_code`),
  ADD KEY `idx_meetings_code` (`meeting_code`),
  ADD KEY `idx_meetings_creator` (`created_by`);

--
-- Indexes for table `meeting_messages`
--
ALTER TABLE `meeting_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_messages_meeting` (`meeting_id`);
ALTER TABLE `meeting_messages` ADD FULLTEXT KEY `idx_messages_text` (`message`);

--
-- Indexes for table `meeting_participants`
--
ALTER TABLE `meeting_participants`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_participants_meeting` (`meeting_id`),
  ADD KEY `idx_participants_user` (`user_id`);

--
-- Indexes for table `meeting_recordings`
--
ALTER TABLE `meeting_recordings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_recordings_meeting` (`meeting_id`);

--
-- Indexes for table `message_delivery_status`
--
ALTER TABLE `message_delivery_status`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_message_status` (`message_id`,`user_id`,`status`),
  ADD KEY `idx_delivery_status_message` (`message_id`),
  ADD KEY `idx_delivery_status_user` (`user_id`),
  ADD KEY `idx_delivery_status_timestamp` (`timestamp`);

--
-- Indexes for table `message_mentions`
--
ALTER TABLE `message_mentions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_message_mentions_message_id` (`message_id`),
  ADD KEY `idx_message_mentions_user_id` (`mentioned_user_id`);

--
-- Indexes for table `message_polls`
--
ALTER TABLE `message_polls`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_message_polls_message` (`message_id`),
  ADD KEY `idx_message_polls_creator` (`created_by`);

--
-- Indexes for table `message_reactions`
--
ALTER TABLE `message_reactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_message_emoji` (`message_id`,`user_id`,`emoji`),
  ADD KEY `idx_message_reactions_message_id` (`message_id`),
  ADD KEY `idx_message_reactions_user_id` (`user_id`),
  ADD KEY `idx_message_reactions_emoji` (`emoji`);

--
-- Indexes for table `message_read_status`
--
ALTER TABLE `message_read_status`
  ADD PRIMARY KEY (`message_id`,`user_id`),
  ADD KEY `idx_message_read_status_user_id` (`user_id`),
  ADD KEY `idx_message_read_status_read_at` (`read_at`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_type` (`type`),
  ADD KEY `idx_bug_id` (`bug_id`),
  ADD KEY `idx_notifications_type_created` (`type`,`created_at`),
  ADD KEY `idx_notifications_bug_status` (`bug_id`,`status`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD KEY `idx_token` (`token`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_expires_at` (`expires_at`),
  ADD KEY `idx_used_at` (`used_at`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `permission_key` (`permission_key`),
  ADD KEY `idx_permissions_category` (`category`),
  ADD KEY `idx_permissions_scope` (`scope`),
  ADD KEY `idx_permissions_key` (`permission_key`);

--
-- Indexes for table `poll_options`
--
ALTER TABLE `poll_options`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_poll_options_poll` (`poll_id`);

--
-- Indexes for table `poll_votes`
--
ALTER TABLE `poll_votes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_poll_votes_poll` (`poll_id`),
  ADD KEY `idx_poll_votes_option` (`option_id`),
  ADD KEY `idx_poll_votes_user` (`user_id`);

--
-- Indexes for table `projects`
--
ALTER TABLE `projects`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_projects_created_by` (`created_by`),
  ADD KEY `idx_projects_name` (`name`),
  ADD KEY `idx_projects_status_created` (`status`,`created_at`),
  ADD KEY `idx_projects_created_by_status` (`created_by`,`status`),
  ADD KEY `idx_projects_name_status` (`name`,`status`),
  ADD KEY `idx_projects_status_id_name_created_by` (`status`,`id`,`name`,`created_by`);
ALTER TABLE `projects` ADD FULLTEXT KEY `ft_projects_search` (`name`,`description`);

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
  ADD KEY `pa_project_created` (`project_id`,`created_at`),
  ADD KEY `pa_user_created` (`user_id`,`created_at`),
  ADD KEY `idx_project_activities_project_user` (`project_id`,`user_id`),
  ADD KEY `idx_project_activities_type_created` (`activity_type`,`created_at`),
  ADD KEY `idx_project_activities_related` (`related_id`),
  ADD KEY `idx_project_activities_project_id` (`project_id`),
  ADD KEY `idx_project_activities_type` (`activity_type`),
  ADD KEY `idx_project_activities_created_at` (`created_at`),
  ADD KEY `idx_project_activities_user_id` (`user_id`);

--
-- Indexes for table `project_members`
--
ALTER TABLE `project_members`
  ADD PRIMARY KEY (`project_id`,`user_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_project_members_user_id` (`user_id`),
  ADD KEY `idx_project_members_project_id` (`project_id`),
  ADD KEY `idx_project_members_user_project` (`user_id`,`project_id`),
  ADD KEY `idx_project_members_joined_at` (`joined_at`),
  ADD KEY `idx_project_members_user_role` (`user_id`,`role`),
  ADD KEY `idx_project_members_project_role` (`project_id`,`role`),
  ADD KEY `idx_project_members_dashboard` (`project_id`,`role`,`joined_at`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `role_name` (`role_name`),
  ADD KEY `idx_roles_system` (`is_system_role`),
  ADD KEY `idx_roles_name` (`role_name`);

--
-- Indexes for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_role_permission` (`role_id`,`permission_id`),
  ADD KEY `idx_role_permissions_role` (`role_id`),
  ADD KEY `idx_role_permissions_permission` (`permission_id`);

--
-- Indexes for table `session_activities`
--
ALTER TABLE `session_activities`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_session` (`session_id`),
  ADD KEY `idx_activity_type` (`activity_type`),
  ADD KEY `idx_project` (`project_id`);

--
-- Indexes for table `session_pauses`
--
ALTER TABLE `session_pauses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_session` (`session_id`),
  ADD KEY `idx_active_pause` (`session_id`,`is_active`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `key_name` (`key_name`);

--
-- Indexes for table `shared_tasks`
--
ALTER TABLE `shared_tasks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `approved_by` (`approved_by`),
  ADD KEY `completed_by` (`completed_by`),
  ADD KEY `idx_assigned_to` (`assigned_to`),
  ADD KEY `idx_created_by` (`created_by`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_project_id` (`project_id`);

--
-- Indexes for table `shared_task_assignees`
--
ALTER TABLE `shared_task_assignees`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_task_assignee` (`shared_task_id`,`assigned_to`),
  ADD KEY `idx_shared_task_id` (`shared_task_id`),
  ADD KEY `idx_assigned_to` (`assigned_to`),
  ADD KEY `idx_completed_at` (`completed_at`);

--
-- Indexes for table `shared_task_projects`
--
ALTER TABLE `shared_task_projects`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_task_project` (`shared_task_id`,`project_id`),
  ADD KEY `idx_shared_task_id` (`shared_task_id`),
  ADD KEY `idx_project_id` (`project_id`);

--
-- Indexes for table `starred_messages`
--
ALTER TABLE `starred_messages`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_message_starred` (`message_id`,`user_id`),
  ADD KEY `idx_starred_messages_user` (`user_id`),
  ADD KEY `idx_starred_messages_message` (`message_id`),
  ADD KEY `idx_starred_messages_group` (`group_id`);

--
-- Indexes for table `status_views`
--
ALTER TABLE `status_views`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_status_viewer` (`status_id`,`viewer_id`),
  ADD KEY `idx_status_views_status` (`status_id`),
  ADD KEY `idx_status_views_viewer` (`viewer_id`);

--
-- Indexes for table `typing_indicators`
--
ALTER TABLE `typing_indicators`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_typing_indicators_group_id` (`group_id`),
  ADD KEY `idx_typing_indicators_user_id` (`user_id`),
  ADD KEY `idx_typing_indicators_expires_at` (`expires_at`);

--
-- Indexes for table `updates`
--
ALTER TABLE `updates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_type` (`type`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_project_id` (`project_id`),
  ADD KEY `idx_updates_project_status` (`project_id`,`status`),
  ADD KEY `idx_updates_created_by_status` (`created_by`,`status`),
  ADD KEY `idx_updates_created_at_status` (`created_at`,`status`),
  ADD KEY `idx_updates_type_status` (`type`,`status`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `id` (`id`),
  ADD UNIQUE KEY `id_2` (`id`),
  ADD UNIQUE KEY `idx_users_phone_unique` (`phone`),
  ADD UNIQUE KEY `idx_users_google_sub` (`google_sub`),
  ADD KEY `idx_users_role` (`role`),
  ADD KEY `idx_users_email` (`email`),
  ADD KEY `idx_users_id_role` (`id`,`role`),
  ADD KEY `idx_users_role_email` (`role`,`email`),
  ADD KEY `idx_users_created_at` (`created_at`),
  ADD KEY `idx_users_covering_profile` (`id`,`username`,`email`,`role`,`created_at`,`updated_at`),
  ADD KEY `idx_users_fcm_token` (`fcm_token`),
  ADD KEY `idx_users_phone` (`phone`),
  ADD KEY `idx_users_is_online` (`is_online`),
  ADD KEY `idx_users_last_seen` (`last_seen`),
  ADD KEY `idx_users_last_login_at` (`last_login_at`),
  ADD KEY `idx_users_last_active_at` (`last_active_at`),
  ADD KEY `idx_users_role_id` (`role_id`);

--
-- Indexes for table `user_activity_sessions`
--
ALTER TABLE `user_activity_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_activity_user_id` (`user_id`),
  ADD KEY `idx_user_activity_session_start` (`session_start`),
  ADD KEY `idx_user_activity_session_end` (`session_end`),
  ADD KEY `idx_user_activity_user_date` (`user_id`,`session_start`);

--
-- Indexes for table `user_documents`
--
ALTER TABLE `user_documents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `google_doc_id` (`google_doc_id`),
  ADD KEY `idx_creator` (`creator_user_id`),
  ADD KEY `idx_template` (`template_id`),
  ADD KEY `idx_type` (`doc_type`),
  ADD KEY `idx_archived` (`is_archived`);

--
-- Indexes for table `user_feedback`
--
ALTER TABLE `user_feedback`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_feedback_user_id` (`user_id`),
  ADD KEY `idx_user_feedback_submitted_at` (`submitted_at`),
  ADD KEY `idx_user_feedback_rating` (`rating`);

--
-- Indexes for table `user_feedback_tracking`
--
ALTER TABLE `user_feedback_tracking`
  ADD PRIMARY KEY (`user_id`),
  ADD KEY `idx_user_feedback_tracking_submitted` (`has_submitted_feedback`);

--
-- Indexes for table `user_otps`
--
ALTER TABLE `user_otps`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_otps_email` (`email`),
  ADD KEY `idx_user_otps_phone` (`phone`);

--
-- Indexes for table `user_permissions`
--
ALTER TABLE `user_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_permission_project` (`user_id`,`permission_id`,`project_id`),
  ADD KEY `idx_user_permissions_user` (`user_id`),
  ADD KEY `idx_user_permissions_permission` (`permission_id`),
  ADD KEY `idx_user_permissions_project` (`project_id`),
  ADD KEY `idx_user_permissions_granted` (`granted`);

--
-- Indexes for table `user_status`
--
ALTER TABLE `user_status`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_status_user` (`user_id`),
  ADD KEY `idx_user_status_expires` (`expires_at`),
  ADD KEY `idx_user_status_created` (`created_at`);

--
-- Indexes for table `user_tasks`
--
ALTER TABLE `user_tasks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_project` (`project_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `voice_notes`
--
ALTER TABLE `voice_notes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_phone` (`phone`),
  ADD KEY `idx_sent_by` (`sent_by`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `voice_note_history`
--
ALTER TABLE `voice_note_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_voice_note_id` (`voice_note_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_timestamp` (`timestamp`);

--
-- Indexes for table `work_sessions`
--
ALTER TABLE `work_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_date` (`user_id`,`submission_date`),
  ADD KEY `idx_active` (`user_id`,`is_active`),
  ADD KEY `idx_check_in` (`check_in_time`);

--
-- Indexes for table `work_submissions`
--
ALTER TABLE `work_submissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_user_day` (`user_id`,`submission_date`),
  ADD KEY `idx_user_date` (`user_id`,`submission_date`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin_audit_log`
--
ALTER TABLE `admin_audit_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=149;

--
-- AUTO_INCREMENT for table `announcements`
--
ALTER TABLE `announcements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `bug_documents`
--
ALTER TABLE `bug_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `doc_templates`
--
ALTER TABLE `doc_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `magic_links`
--
ALTER TABLE `magic_links`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `meetings`
--
ALTER TABLE `meetings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=52;

--
-- AUTO_INCREMENT for table `meeting_messages`
--
ALTER TABLE `meeting_messages`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `meeting_participants`
--
ALTER TABLE `meeting_participants`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3606;

--
-- AUTO_INCREMENT for table `meeting_recordings`
--
ALTER TABLE `meeting_recordings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=112;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=156;

--
-- AUTO_INCREMENT for table `project_activities`
--
ALTER TABLE `project_activities`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=753;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `role_permissions`
--
ALTER TABLE `role_permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1399;

--
-- AUTO_INCREMENT for table `session_activities`
--
ALTER TABLE `session_activities`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `session_pauses`
--
ALTER TABLE `session_pauses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `shared_tasks`
--
ALTER TABLE `shared_tasks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `shared_task_assignees`
--
ALTER TABLE `shared_task_assignees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=90;

--
-- AUTO_INCREMENT for table `shared_task_projects`
--
ALTER TABLE `shared_task_projects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;

--
-- AUTO_INCREMENT for table `user_documents`
--
ALTER TABLE `user_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `user_otps`
--
ALTER TABLE `user_otps`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=103;

--
-- AUTO_INCREMENT for table `user_permissions`
--
ALTER TABLE `user_permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=277;

--
-- AUTO_INCREMENT for table `user_tasks`
--
ALTER TABLE `user_tasks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `voice_notes`
--
ALTER TABLE `voice_notes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `voice_note_history`
--
ALTER TABLE `voice_note_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `work_sessions`
--
ALTER TABLE `work_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `work_submissions`
--
ALTER TABLE `work_submissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=54;

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
-- Constraints for table `blocked_users`
--
ALTER TABLE `blocked_users`
  ADD CONSTRAINT `blocked_users_ibfk_1` FOREIGN KEY (`blocker_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `blocked_users_ibfk_2` FOREIGN KEY (`blocked_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `broadcast_lists`
--
ALTER TABLE `broadcast_lists`
  ADD CONSTRAINT `broadcast_lists_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `broadcast_recipients`
--
ALTER TABLE `broadcast_recipients`
  ADD CONSTRAINT `broadcast_recipients_ibfk_1` FOREIGN KEY (`broadcast_id`) REFERENCES `broadcast_lists` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `broadcast_recipients_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

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
-- Constraints for table `bug_documents`
--
ALTER TABLE `bug_documents`
  ADD CONSTRAINT `fk_bug_documents_template` FOREIGN KEY (`template_id`) REFERENCES `doc_templates` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `call_logs`
--
ALTER TABLE `call_logs`
  ADD CONSTRAINT `call_logs_ibfk_1` FOREIGN KEY (`caller_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `call_logs_ibfk_2` FOREIGN KEY (`group_id`) REFERENCES `chat_groups` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `call_participants`
--
ALTER TABLE `call_participants`
  ADD CONSTRAINT `call_participants_ibfk_1` FOREIGN KEY (`call_id`) REFERENCES `call_logs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `call_participants_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `chat_groups`
--
ALTER TABLE `chat_groups`
  ADD CONSTRAINT `chat_groups_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `chat_groups_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `chat_group_members`
--
ALTER TABLE `chat_group_members`
  ADD CONSTRAINT `chat_group_members_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `chat_groups` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `chat_group_members_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `chat_messages`
--
ALTER TABLE `chat_messages`
  ADD CONSTRAINT `chat_messages_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `chat_groups` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `chat_messages_ibfk_2` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `chat_messages_ibfk_3` FOREIGN KEY (`reply_to_message_id`) REFERENCES `chat_messages` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `chat_messages_ibfk_4` FOREIGN KEY (`pinned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `disappearing_messages_settings`
--
ALTER TABLE `disappearing_messages_settings`
  ADD CONSTRAINT `disappearing_messages_settings_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `chat_groups` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `disappearing_messages_settings_ibfk_2` FOREIGN KEY (`enabled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `group_admins`
--
ALTER TABLE `group_admins`
  ADD CONSTRAINT `group_admins_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `chat_groups` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `group_admins_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `group_admins_ibfk_3` FOREIGN KEY (`granted_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `meeting_messages`
--
ALTER TABLE `meeting_messages`
  ADD CONSTRAINT `fk_message_meeting` FOREIGN KEY (`meeting_id`) REFERENCES `meetings` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `meeting_participants`
--
ALTER TABLE `meeting_participants`
  ADD CONSTRAINT `fk_participant_meeting` FOREIGN KEY (`meeting_id`) REFERENCES `meetings` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `meeting_recordings`
--
ALTER TABLE `meeting_recordings`
  ADD CONSTRAINT `fk_recording_meeting` FOREIGN KEY (`meeting_id`) REFERENCES `meetings` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `message_delivery_status`
--
ALTER TABLE `message_delivery_status`
  ADD CONSTRAINT `message_delivery_status_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `chat_messages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `message_delivery_status_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `message_mentions`
--
ALTER TABLE `message_mentions`
  ADD CONSTRAINT `message_mentions_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `chat_messages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `message_mentions_ibfk_2` FOREIGN KEY (`mentioned_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `message_polls`
--
ALTER TABLE `message_polls`
  ADD CONSTRAINT `message_polls_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `chat_messages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `message_polls_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `message_reactions`
--
ALTER TABLE `message_reactions`
  ADD CONSTRAINT `message_reactions_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `chat_messages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `message_reactions_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `message_read_status`
--
ALTER TABLE `message_read_status`
  ADD CONSTRAINT `message_read_status_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `chat_messages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `message_read_status_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `poll_options`
--
ALTER TABLE `poll_options`
  ADD CONSTRAINT `poll_options_ibfk_1` FOREIGN KEY (`poll_id`) REFERENCES `message_polls` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `poll_votes`
--
ALTER TABLE `poll_votes`
  ADD CONSTRAINT `poll_votes_ibfk_1` FOREIGN KEY (`poll_id`) REFERENCES `message_polls` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `poll_votes_ibfk_2` FOREIGN KEY (`option_id`) REFERENCES `poll_options` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `poll_votes_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

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
-- Constraints for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD CONSTRAINT `fk_role_permissions_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_role_permissions_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `session_activities`
--
ALTER TABLE `session_activities`
  ADD CONSTRAINT `session_activities_ibfk_1` FOREIGN KEY (`session_id`) REFERENCES `work_sessions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `session_pauses`
--
ALTER TABLE `session_pauses`
  ADD CONSTRAINT `session_pauses_ibfk_1` FOREIGN KEY (`session_id`) REFERENCES `work_sessions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `shared_tasks`
--
ALTER TABLE `shared_tasks`
  ADD CONSTRAINT `shared_tasks_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `shared_tasks_ibfk_2` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `shared_tasks_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `shared_tasks_ibfk_4` FOREIGN KEY (`completed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `shared_task_assignees`
--
ALTER TABLE `shared_task_assignees`
  ADD CONSTRAINT `fk_sta_task` FOREIGN KEY (`shared_task_id`) REFERENCES `shared_tasks` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `shared_task_projects`
--
ALTER TABLE `shared_task_projects`
  ADD CONSTRAINT `shared_task_projects_ibfk_1` FOREIGN KEY (`shared_task_id`) REFERENCES `shared_tasks` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `starred_messages`
--
ALTER TABLE `starred_messages`
  ADD CONSTRAINT `starred_messages_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `chat_messages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `starred_messages_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `starred_messages_ibfk_3` FOREIGN KEY (`group_id`) REFERENCES `chat_groups` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `status_views`
--
ALTER TABLE `status_views`
  ADD CONSTRAINT `status_views_ibfk_1` FOREIGN KEY (`status_id`) REFERENCES `user_status` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `status_views_ibfk_2` FOREIGN KEY (`viewer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `typing_indicators`
--
ALTER TABLE `typing_indicators`
  ADD CONSTRAINT `typing_indicators_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `chat_groups` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `typing_indicators_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `updates`
--
ALTER TABLE `updates`
  ADD CONSTRAINT `updates_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `updates_ibfk_2` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`);

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `user_documents`
--
ALTER TABLE `user_documents`
  ADD CONSTRAINT `user_documents_ibfk_1` FOREIGN KEY (`template_id`) REFERENCES `doc_templates` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `user_status`
--
ALTER TABLE `user_status`
  ADD CONSTRAINT `user_status_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `voice_notes`
--
ALTER TABLE `voice_notes`
  ADD CONSTRAINT `voice_notes_ibfk_1` FOREIGN KEY (`sent_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `voice_note_history`
--
ALTER TABLE `voice_note_history`
  ADD CONSTRAINT `voice_note_history_ibfk_1` FOREIGN KEY (`voice_note_id`) REFERENCES `voice_notes` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
