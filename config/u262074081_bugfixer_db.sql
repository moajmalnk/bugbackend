-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Sep 11, 2025 at 02:34 PM
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

--
-- Dumping data for table `admin_audit_log`
--

INSERT INTO `admin_audit_log` (`id`, `admin_id`, `action`, `target_user_id`, `details`, `created_at`) VALUES
(14, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', '85faf78f-d83b-4ba0-a907-0fb39d718d1b', '{\"target_username\":\"faisaltk\",\"target_role\":\"tester\",\"expires_at\":\"2025-06-22 22:31:09\"}', '2025-06-22 22:26:09'),
(15, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', '85faf78f-d83b-4ba0-a907-0fb39d718d1b', '{\"target_username\":\"faisaltk\",\"target_role\":\"tester\",\"expires_at\":\"2025-06-22 22:31:19\"}', '2025-06-22 22:26:19'),
(16, '6717efed-5466-4204-93a4-3287978f9fff', 'generate_dashboard_link', '97202702-bef5-445c-b073-c70108fc6008', '{\"target_username\":\"titty\",\"target_role\":\"developer\",\"expires_at\":\"2025-06-22 22:32:14\"}', '2025-06-22 22:27:14'),
(17, '6717efed-5466-4204-93a4-3287978f9fff', 'generate_dashboard_link', '85faf78f-d83b-4ba0-a907-0fb39d718d1b', '{\"target_username\":\"faisaltk\",\"target_role\":\"tester\",\"expires_at\":\"2025-06-22 22:32:28\"}', '2025-06-22 22:27:28'),
(18, '6717efed-5466-4204-93a4-3287978f9fff', 'generate_dashboard_link', '608dc9d1-26e0-441d-8144-45f74c53a846', '{\"target_username\":\"moajmalnk\",\"target_role\":\"admin\",\"expires_at\":\"2025-06-22 22:34:04\"}', '2025-06-22 22:29:04'),
(19, '6717efed-5466-4204-93a4-3287978f9fff', 'generate_dashboard_link', '97202702-bef5-445c-b073-c70108fc6008', '{\"target_username\":\"titty\",\"target_role\":\"developer\",\"expires_at\":\"2025-06-23 05:27:54\"}', '2025-06-23 05:22:54'),
(20, '6717efed-5466-4204-93a4-3287978f9fff', 'generate_dashboard_link', '93f675c2-765f-448c-bdce-d654aebd61f7', '{\"target_username\":\"lubaba\",\"target_role\":\"developer\",\"expires_at\":\"2025-06-23 05:28:13\"}', '2025-06-23 05:23:13'),
(21, '6717efed-5466-4204-93a4-3287978f9fff', 'generate_dashboard_link', '85faf78f-d83b-4ba0-a907-0fb39d718d1b', '{\"target_username\":\"faisaltk\",\"target_role\":\"tester\",\"expires_at\":\"2025-06-23 05:28:31\"}', '2025-06-23 05:23:31'),
(22, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', '6717efed-5466-4204-93a4-3287978f9fff', '{\"target_username\":\"moajmalp\",\"target_role\":\"admin\",\"expires_at\":\"2025-06-23 09:33:41\"}', '2025-06-23 09:28:41'),
(23, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', '2c119797-d897-4c54-9cda-993c0580e23a', '{\"target_username\":\"Raoof\",\"target_role\":\"developer\",\"expires_at\":\"2025-06-23 09:33:47\"}', '2025-06-23 09:28:47'),
(24, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', 'aa726532-9648-4f8b-9e1b-c8052ebc47cf', '{\"target_username\":\"nabeel\",\"target_role\":\"developer\",\"expires_at\":\"2025-06-23 10:19:02\"}', '2025-06-23 10:14:02'),
(25, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', 'aa726532-9648-4f8b-9e1b-c8052ebc47cf', '{\"target_username\":\"nabeel\",\"target_role\":\"developer\",\"expires_at\":\"2025-06-23 10:26:39\"}', '2025-06-23 10:21:39'),
(26, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', '6717efed-5466-4204-93a4-3287978f9fff', '{\"target_username\":\"moajmalp\",\"target_role\":\"admin\",\"expires_at\":\"2025-06-23 10:28:04\"}', '2025-06-23 10:23:04'),
(27, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', 'aa726532-9648-4f8b-9e1b-c8052ebc47cf', '{\"target_username\":\"nabeel\",\"target_role\":\"developer\",\"expires_at\":\"2025-06-23 10:29:21\"}', '2025-06-23 10:24:21'),
(28, '6717efed-5466-4204-93a4-3287978f9fff', 'generate_dashboard_link', '608dc9d1-26e0-441d-8144-45f74c53a846', '{\"target_username\":\"moajmalnk\",\"target_role\":\"admin\",\"expires_at\":\"2025-06-23 10:59:18\"}', '2025-06-23 10:54:18'),
(29, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', 'aa726532-9648-4f8b-9e1b-c8052ebc47cf', '{\"target_username\":\"nabeel\",\"target_role\":\"developer\",\"expires_at\":\"2025-06-23 19:00:05\"}', '2025-06-23 18:55:05'),
(30, '6717efed-5466-4204-93a4-3287978f9fff', 'generate_dashboard_link', 'aa726532-9648-4f8b-9e1b-c8052ebc47cf', '{\"target_username\":\"nabeel\",\"target_role\":\"developer\",\"expires_at\":\"2025-06-23 19:02:24\"}', '2025-06-23 18:57:24'),
(31, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', 'ce9701d2-c0b0-4826-a0d4-7e452211f40e', '{\"target_username\":\"adnaj\",\"target_role\":\"developer\",\"expires_at\":\"2025-06-25 12:32:10\"}', '2025-06-25 12:25:10'),
(32, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', '2c119797-d897-4c54-9cda-993c0580e23a', '{\"target_username\":\"Raoof\",\"target_role\":\"developer\",\"expires_at\":\"2025-06-26 08:08:55\"}', '2025-06-26 08:01:55'),
(33, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', 'ce9701d2-c0b0-4826-a0d4-7e452211f40e', '{\"target_username\":\"adnaj\",\"target_role\":\"developer\",\"expires_at\":\"2025-06-26 12:57:11\"}', '2025-06-26 12:50:11'),
(34, '6717efed-5466-4204-93a4-3287978f9fff', 'generate_dashboard_link', '608dc9d1-26e0-441d-8144-45f74c53a846', '{\"target_username\":\"moajmalnk\",\"target_role\":\"admin\",\"expires_at\":\"2025-07-03 12:50:53\"}', '2025-06-26 12:50:53'),
(35, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', 'd8edceb5-ca8a-446d-ad21-54d280064e69', '{\"target_username\":\"aaaaaaaa\",\"target_role\":\"tester\",\"expires_at\":\"2025-06-27 08:43:31\"}', '2025-06-27 06:36:31'),
(36, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', '799b8406-7a20-4b8c-b7a9-c467f0c6268e', '{\"target_username\":\"ajmalnk\",\"target_role\":\"admin\",\"expires_at\":\"2025-07-04 08:37:26\"}', '2025-06-27 06:37:26'),
(37, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', 'd8edceb5-ca8a-446d-ad21-54d280064e69', '{\"target_username\":\"aaaaaaaa\",\"target_role\":\"tester\",\"expires_at\":\"2025-06-27 08:45:32\"}', '2025-06-27 06:38:32'),
(38, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', 'd8edceb5-ca8a-446d-ad21-54d280064e69', '{\"target_username\":\"aaaaaaaa\",\"target_role\":\"tester\",\"expires_at\":\"2025-06-27 08:47:16\"}', '2025-06-27 06:40:16'),
(39, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', 'd8edceb5-ca8a-446d-ad21-54d280064e69', '{\"target_username\":\"aaaaaaaa\",\"target_role\":\"developer\",\"expires_at\":\"2025-06-27 09:02:44\"}', '2025-06-27 06:55:44'),
(40, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', 'd8edceb5-ca8a-446d-ad21-54d280064e69', '{\"target_username\":\"aaaaaaaa\",\"target_role\":\"developer\",\"expires_at\":\"2025-06-27 09:23:32\"}', '2025-06-27 07:16:32'),
(41, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', 'd8edceb5-ca8a-446d-ad21-54d280064e69', '{\"target_username\":\"aaaaaaaa\",\"target_role\":\"tester\",\"expires_at\":\"2025-06-27 09:26:05\"}', '2025-06-27 07:19:05'),
(42, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', 'd8edceb5-ca8a-446d-ad21-54d280064e69', '{\"target_username\":\"aaaaaaaa\",\"target_role\":\"tester\",\"expires_at\":\"2025-06-27 13:31:09\"}', '2025-06-27 11:24:09'),
(43, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', '799b8406-7a20-4b8c-b7a9-c467f0c6268e', '{\"target_username\":\"ajmalnk\",\"target_role\":\"admin\",\"expires_at\":\"2025-07-05 12:07:26\"}', '2025-06-28 12:07:26'),
(44, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', 'd8edceb5-ca8a-446d-ad21-54d280064e69', '{\"target_username\":\"aaaaaaaa\",\"target_role\":\"tester\",\"expires_at\":\"2025-06-28 12:19:42\"}', '2025-06-28 12:12:42'),
(45, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', 'd8edceb5-ca8a-446d-ad21-54d280064e69', '{\"target_username\":\"aaaaaaaa\",\"target_role\":\"tester\",\"expires_at\":\"2025-06-29 12:43:12\"}', '2025-06-29 12:36:12'),
(46, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', 'd8edceb5-ca8a-446d-ad21-54d280064e69', '{\"target_username\":\"aaaaaaaa\",\"target_role\":\"tester\",\"expires_at\":\"2025-06-29 12:50:54\"}', '2025-06-29 12:43:54'),
(47, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', 'd8edceb5-ca8a-446d-ad21-54d280064e69', '{\"target_username\":\"aaaaaaaa\",\"target_role\":\"tester\",\"expires_at\":\"2025-06-29 13:23:22\"}', '2025-06-29 13:16:22'),
(48, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', 'd84019a3-575f-403c-aa12-02482422bcfa', '{\"target_username\":\"admins\",\"target_role\":\"developer\",\"expires_at\":\"2025-06-29 13:27:44\"}', '2025-06-29 13:20:44'),
(49, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', 'd8edceb5-ca8a-446d-ad21-54d280064e69', '{\"target_username\":\"aaaaaaaa\",\"target_role\":\"tester\",\"expires_at\":\"2025-06-29 13:43:53\"}', '2025-06-29 13:36:53'),
(50, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', 'd84019a3-575f-403c-aa12-02482422bcfa', '{\"target_username\":\"admins\",\"target_role\":\"developer\",\"expires_at\":\"2025-06-29 13:43:59\"}', '2025-06-29 13:36:59'),
(51, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', 'd8edceb5-ca8a-446d-ad21-54d280064e69', '{\"target_username\":\"aaaaaaaa\",\"target_role\":\"tester\",\"expires_at\":\"2025-06-29 14:00:18\"}', '2025-06-29 13:53:18'),
(52, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', 'd84019a3-575f-403c-aa12-02482422bcfa', '{\"target_username\":\"admins\",\"target_role\":\"developer\",\"expires_at\":\"2025-06-29 14:00:44\"}', '2025-06-29 13:53:44'),
(53, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', '799b8406-7a20-4b8c-b7a9-c467f0c6268e', '{\"target_username\":\"ajmalnk\",\"target_role\":\"admin\",\"expires_at\":\"2025-07-06 15:45:00\"}', '2025-06-29 15:45:00'),
(54, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', 'b6b1ab7a-f3c0-4b33-b953-ef4428a2c318', '{\"target_username\":\"ssssssss\",\"target_role\":\"tester\",\"expires_at\":\"2025-07-31 12:26:42\"}', '2025-07-24 12:26:42'),
(55, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', '799b8406-7a20-4b8c-b7a9-c467f0c6268e', '{\"target_username\":\"ajmalnk\",\"target_role\":\"admin\",\"expires_at\":\"2025-08-04 13:38:52\"}', '2025-07-28 13:38:52'),
(56, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', 'd84019a3-575f-403c-aa12-02482422bcfa', '{\"target_username\":\"admins\",\"target_role\":\"developer\",\"expires_at\":\"2025-08-04 13:39:05\"}', '2025-07-28 13:39:05'),
(57, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', '244ab83a-5049-4e61-8fb9-67e67281a3c2', '{\"target_username\":\"essdd\",\"target_role\":\"tester\",\"expires_at\":\"2025-09-03 14:20:12\"}', '2025-08-27 14:20:12'),
(58, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', 'd8edceb5-ca8a-446d-ad21-54d280064e69', '{\"target_username\":\"aaaaaaaa\",\"target_role\":\"tester\",\"expires_at\":\"2025-09-03 14:20:28\"}', '2025-08-27 14:20:28'),
(59, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', 'b6b1ab7a-f3c0-4b33-b953-ef4428a2c318', '{\"target_username\":\"ssssssss\",\"target_role\":\"tester\",\"expires_at\":\"2025-09-03 14:28:51\"}', '2025-08-27 14:28:51'),
(60, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', '5b5a0a88-480b-4605-8b75-e6757dbdcd3a', '{\"target_username\":\"admin1\",\"target_role\":\"admin\",\"expires_at\":\"2025-09-03 14:35:18\"}', '2025-08-27 14:35:18'),
(61, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', '608dc9d1-26e0-441d-8144-45f74c53a846', '{\"target_username\":\"moajmalnk\",\"target_role\":\"admin\",\"expires_at\":\"2025-09-03 14:36:02\"}', '2025-08-27 14:36:02'),
(62, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', '5b5a0a88-480b-4605-8b75-e6757dbdcd3a', '{\"target_username\":\"admin1\",\"target_role\":\"admin\",\"expires_at\":\"2025-09-03 14:36:36\"}', '2025-08-27 14:36:36'),
(63, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', 'b6b1ab7a-f3c0-4b33-b953-ef4428a2c318', '{\"target_username\":\"ssssssss\",\"target_role\":\"tester\",\"expires_at\":\"2025-09-03 14:37:42\"}', '2025-08-27 14:37:42'),
(64, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', 'b6b1ab7a-f3c0-4b33-b953-ef4428a2c318', '{\"target_username\":\"ssssssss\",\"target_role\":\"tester\",\"expires_at\":\"2025-09-03 16:40:14\"}', '2025-08-27 16:40:14'),
(65, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', 'b6b1ab7a-f3c0-4b33-b953-ef4428a2c318', '{\"target_username\":\"ssssssss\",\"target_role\":\"tester\",\"expires_at\":\"2025-09-03 17:54:55\"}', '2025-08-27 17:54:55'),
(66, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', 'b6b1ab7a-f3c0-4b33-b953-ef4428a2c318', '{\"target_username\":\"ssssssss\",\"target_role\":\"tester\",\"expires_at\":\"2025-09-03 19:35:34\"}', '2025-08-27 19:35:34'),
(67, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', 'd84019a3-575f-403c-aa12-02482422bcfa', '{\"target_username\":\"admins\",\"target_role\":\"developer\",\"expires_at\":\"2025-09-03 19:36:51\"}', '2025-08-27 19:36:51'),
(68, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', '5b5a0a88-480b-4605-8b75-e6757dbdcd3a', '{\"target_username\":\"admin1\",\"target_role\":\"admin\",\"expires_at\":\"2025-09-03 19:37:06\"}', '2025-08-27 19:37:06'),
(69, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', 'b6b1ab7a-f3c0-4b33-b953-ef4428a2c318', '{\"target_username\":\"ssssssss\",\"target_role\":\"tester\",\"expires_at\":\"2025-09-16 08:10:09\"}', '2025-09-09 08:10:09'),
(70, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', '244ab83a-5049-4e61-8fb9-67e67281a3c2', '{\"target_username\":\"essdd\",\"target_role\":\"tester\",\"expires_at\":\"2025-09-16 15:34:03\"}', '2025-09-09 15:34:03'),
(71, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', 'b6b1ab7a-f3c0-4b33-b953-ef4428a2c318', '{\"target_username\":\"ssssssss\",\"target_role\":\"tester\",\"expires_at\":\"2025-09-16 15:34:53\"}', '2025-09-09 15:34:53'),
(72, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', '799b8406-7a20-4b8c-b7a9-c467f0c6268e', '{\"target_username\":\"ajmalnk\",\"target_role\":\"admin\",\"expires_at\":\"2025-09-17 05:26:42\"}', '2025-09-10 05:26:42'),
(73, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', '5b5a0a88-480b-4605-8b75-e6757dbdcd3a', '{\"target_username\":\"admin1\",\"target_role\":\"admin\",\"expires_at\":\"2025-09-17 05:45:03\"}', '2025-09-10 05:45:03'),
(74, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', 'b6b1ab7a-f3c0-4b33-b953-ef4428a2c318', '{\"target_username\":\"ssssssss\",\"target_role\":\"tester\",\"expires_at\":\"2025-09-17 05:45:17\"}', '2025-09-10 05:45:17'),
(75, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', '799b8406-7a20-4b8c-b7a9-c467f0c6268e', '{\"target_username\":\"ajmalnk\",\"target_role\":\"admin\",\"expires_at\":\"2025-09-17 06:59:18\"}', '2025-09-10 06:59:18'),
(76, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', '5b5a0a88-480b-4605-8b75-e6757dbdcd3a', '{\"target_username\":\"admin1\",\"target_role\":\"admin\",\"expires_at\":\"2025-09-17 06:59:29\"}', '2025-09-10 06:59:29'),
(77, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', '244ab83a-5049-4e61-8fb9-67e67281a3c2', '{\"target_username\":\"essdd\",\"target_role\":\"developer\",\"expires_at\":\"2025-09-17 06:59:48\"}', '2025-09-10 06:59:48'),
(78, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', 'c18ce191-e34b-4ca7-b69b-6a78488d3de5', '{\"target_username\":\"sssss\",\"target_role\":\"tester\",\"expires_at\":\"2025-09-17 07:00:21\"}', '2025-09-10 07:00:21'),
(79, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', '244ab83a-5049-4e61-8fb9-67e67281a3c2', '{\"target_username\":\"essdd\",\"target_role\":\"developer\",\"expires_at\":\"2025-09-17 13:00:47\"}', '2025-09-10 13:00:47'),
(80, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', 'c18ce191-e34b-4ca7-b69b-6a78488d3de5', '{\"target_username\":\"sssss\",\"target_role\":\"tester\",\"expires_at\":\"2025-09-17 13:01:17\"}', '2025-09-10 13:01:17'),
(81, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', '244ab83a-5049-4e61-8fb9-67e67281a3c2', '{\"target_username\":\"developer\",\"target_role\":\"developer\",\"expires_at\":\"2025-09-17 14:07:41\"}', '2025-09-10 14:07:41'),
(82, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', 'c18ce191-e34b-4ca7-b69b-6a78488d3de5', '{\"target_username\":\"tester\",\"target_role\":\"tester\",\"expires_at\":\"2025-09-17 14:10:06\"}', '2025-09-10 14:10:06'),
(83, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', 'c18ce191-e34b-4ca7-b69b-6a78488d3de5', '{\"target_username\":\"tester\",\"target_role\":\"tester\",\"expires_at\":\"2025-09-17 14:13:40\"}', '2025-09-10 14:13:40'),
(84, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', '244ab83a-5049-4e61-8fb9-67e67281a3c2', '{\"target_username\":\"developer\",\"target_role\":\"developer\",\"expires_at\":\"2025-09-17 14:14:34\"}', '2025-09-10 14:14:34'),
(85, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', 'd84019a3-575f-403c-aa12-02482422bcfa', '{\"target_username\":\"developer\",\"target_role\":\"developer\",\"expires_at\":\"2025-09-17 14:41:53\"}', '2025-09-10 14:41:53'),
(86, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', 'd84019a3-575f-403c-aa12-02482422bcfa', '{\"target_username\":\"developer\",\"target_role\":\"developer\",\"expires_at\":\"2025-09-17 14:47:46\"}', '2025-09-10 14:47:46'),
(87, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', '799b8406-7a20-4b8c-b7a9-c467f0c6268e', '{\"target_username\":\"ajmalnk\",\"target_role\":\"admin\",\"expires_at\":\"2025-09-17 14:49:47\"}', '2025-09-10 14:49:47'),
(88, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', 'd84019a3-575f-403c-aa12-02482422bcfa', '{\"target_username\":\"developer\",\"target_role\":\"developer\",\"expires_at\":\"2025-09-17 15:09:34\"}', '2025-09-10 15:09:34'),
(89, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', 'c18ce191-e34b-4ca7-b69b-6a78488d3de5', '{\"target_username\":\"tester\",\"target_role\":\"tester\",\"expires_at\":\"2025-09-17 15:09:41\"}', '2025-09-10 15:09:41'),
(90, '608dc9d1-26e0-441d-8144-45f74c53a846', 'generate_dashboard_link', '799b8406-7a20-4b8c-b7a9-c467f0c6268e', '{\"target_username\":\"ajmalnk\",\"target_role\":\"admin\",\"expires_at\":\"2025-09-17 15:10:27\"}', '2025-09-10 15:10:27');

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

--
-- Dumping data for table `announcements`
--

INSERT INTO `announcements` (`id`, `title`, `content`, `is_active`, `expiry_date`, `created_at`, `updated_at`, `last_broadcast_at`) VALUES
(1, 'A Fresh Look for BugRacer: New Announcement Feature', 'We\'ve introduced a powerful new announcement system to keep you updated on all the latest changes in BugRacer. Here’s a summary of what’s new:\nCentered Announcement Popup: Important updates are now displayed in a beautiful, centered modal that grabs your attention without being intrusive. It’s designed to match the clean, modern aesthetic of BugRacer, complete with our logo.\nAdmin Announcement Management: We\'ve added a new \"Announcements\" tab in the Settings page, exclusively for administrators. This new interface allows admins to easily create, edit, and delete announcements.\n\"Notify\" on Demand: For critical updates, admins can now use the \"Notify\" button to broadcast an announcement to all users, ensuring everyone sees important messages, even if they\'ve dismissed the popup before.', 1, '2025-09-30 00:00:00', '2025-06-23 13:21:39', '2025-09-10 05:56:26', '2025-09-10 05:56:26');

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL,
  `user_id` varchar(36) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`id`, `user_id`, `action`, `details`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, '608dc9d1-26e0-441d-8144-45f74c53a846', 'password_reset_requested', '{\"email\":\"moajmalnk@gmail.com\",\"ip\":\"::1\"}', '::1', 'curl/8.7.1', '2025-09-10 16:31:05'),
(2, '608dc9d1-26e0-441d-8144-45f74c53a846', 'password_reset_requested', '{\"email\":\"moajmalnk@gmail.com\",\"ip\":\"::1\"}', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36', '2025-09-10 16:31:20'),
(3, '608dc9d1-26e0-441d-8144-45f74c53a846', 'password_reset_requested', '{\"email\":\"moajmalnk@gmail.com\",\"ip\":\"::1\"}', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36', '2025-09-10 16:32:31'),
(4, '608dc9d1-26e0-441d-8144-45f74c53a846', 'password_reset_requested', '{\"email\":\"moajmalnk@gmail.com\",\"ip\":\"::1\"}', '::1', 'curl/8.7.1', '2025-09-10 16:34:23'),
(5, '608dc9d1-26e0-441d-8144-45f74c53a846', 'password_reset_requested', '{\"email\":\"moajmalnk@gmail.com\",\"ip\":\"::1\"}', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36', '2025-09-10 16:41:03'),
(6, '608dc9d1-26e0-441d-8144-45f74c53a846', 'password_reset_requested', '{\"email\":\"moajmalnk@gmail.com\",\"ip\":\"::1\"}', '::1', 'curl/8.7.1', '2025-09-10 16:43:01'),
(7, '608dc9d1-26e0-441d-8144-45f74c53a846', 'password_reset_requested', '{\"email\":\"moajmalnk@gmail.com\",\"ip\":\"::1\"}', '::1', 'curl/8.7.1', '2025-09-10 16:46:36'),
(8, '608dc9d1-26e0-441d-8144-45f74c53a846', 'password_reset_requested', '{\"email\":\"moajmalnk@gmail.com\",\"ip\":\"::1\"}', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36', '2025-09-10 16:47:10'),
(9, '608dc9d1-26e0-441d-8144-45f74c53a846', 'password_reset_completed', '{\"email\":\"moajmalnk@gmail.com\",\"ip\":\"::1\"}', '::1', 'curl/8.7.1', '2025-09-10 16:58:25'),
(10, '608dc9d1-26e0-441d-8144-45f74c53a846', 'password_reset_completed', '{\"email\":\"moajmalnk@gmail.com\",\"ip\":\"::1\"}', '::1', 'unknown', '2025-09-10 16:58:32'),
(11, '608dc9d1-26e0-441d-8144-45f74c53a846', 'password_reset_completed', '{\"email\":\"moajmalnk@gmail.com\",\"ip\":\"::1\"}', '::1', 'unknown', '2025-09-10 17:03:01'),
(12, '608dc9d1-26e0-441d-8144-45f74c53a846', 'password_reset_requested', '{\"email\":\"moajmalnk@gmail.com\",\"ip\":\"::1\"}', '::1', 'curl/8.7.1', '2025-09-10 17:13:33'),
(13, '608dc9d1-26e0-441d-8144-45f74c53a846', 'password_reset_requested', '{\"email\":\"moajmalnk@gmail.com\",\"ip\":\"::1\"}', '::1', 'curl/8.7.1', '2025-09-10 17:13:47'),
(14, '608dc9d1-26e0-441d-8144-45f74c53a846', 'password_reset_requested', '{\"email\":\"moajmalnk@gmail.com\",\"ip\":\"::1\"}', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36', '2025-09-11 02:24:33'),
(15, '608dc9d1-26e0-441d-8144-45f74c53a846', 'password_reset_requested', '{\"email\":\"moajmalnk@gmail.com\",\"ip\":\"::1\"}', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36', '2025-09-11 12:13:00');

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
-- Dumping data for table `bugs`
--

INSERT INTO `bugs` (`id`, `title`, `description`, `expected_result`, `actual_result`, `fix_description`, `project_id`, `reported_by`, `priority`, `status`, `created_at`, `updated_at`, `updated_by`, `fixed_by`) VALUES
('04496880-9f61-49c9-b8a3-573b8f532384', 'error', 'In the admin and assistant admin dashboard, when clicking on the \"pending\" session tab, it shows an error ', NULL, NULL, NULL, '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'high', 'pending', '2025-06-26 05:51:24', '2025-06-26 15:09:31', '6717', NULL),
('0718fa2a-0f69-4df7-836f-869e61172351', 'Development', 'sssssssssss', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 'medium', 'fixed', '2025-06-26 16:03:39', '2025-09-10 03:13:09', '799b8406-7a20-4b8c-b7a9-c467f0c6268e', '799b8406-7a20-4b8c-b7a9-c467f0c6268e'),
('0b2950c3-c49c-41f8-8bfb-ee1f68b58cf3', 'ssss', 'sssssssssssssssssss', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 'medium', 'fixed', '2025-06-26 15:17:34', '2025-09-10 03:13:09', '608dc9d1-26e0-441d-8144-45f74c53a846', '608dc9d1-26e0-441d-8144-45f74c53a846'),
('0b6eaa55-e466-4a2c-8a77-ddd7bd055e4d', 'Development', 'aaaaaaaaaaaaaaaaaaaa', NULL, NULL, NULL, '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 'medium', 'pending', '2025-07-28 15:50:50', '2025-07-28 15:50:50', NULL, NULL),
('0c3397b8-a651-4970-a26b-a4854f075e84', 'Add Class Taken Amount', 'in Admin Dashboard', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 'medium', 'fixed', '2025-06-04 07:12:08', '2025-09-10 03:13:09', '608dc9d1-26e0-441d-8144-45f74c53a846', '608dc9d1-26e0-441d-8144-45f74c53a846'),
('0f535fc3-0b88-4481-9e07-2e30806ddc9c', 'asad', 'asdasdaqw', NULL, NULL, NULL, '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'medium', 'pending', '2025-06-26 13:04:16', '2025-06-26 15:09:31', NULL, NULL),
('0fac4519-4f8a-4b85-9ce9-b54c7cd9725b', 'Mobile App Design', 'xsssss', '', '', NULL, '560c6c1f-5185-4ef4-9e8f-ef1315f38930', '608dc9d1-26e0-441d-8144-45f74c53a846', 'medium', 'fixed', '2025-09-10 14:36:25', '2025-09-10 14:42:17', 'd84019a3-575f-403c-aa12-02482422bcfa', 'd84019a3-575f-403c-aa12-02482422bcfa'),
('10437484-ef12-45e0-a6b2-2cc524dba0f7', 'advisor is not displaying', 'In the sales head dashboard, when add student I choose a advisor but it is not displaying in student view   ', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'high', 'fixed', '2025-06-25 05:45:45', '2025-09-10 03:13:09', '2', NULL),
('106ccd2e-095c-40c4-8254-10067ff2e337', 'Student Dashboard home', 'class details varanam \r\nlike hour,session okke\r\n\r\nStudent Dashboard ', NULL, NULL, NULL, '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'high', 'pending', '2025-06-20 07:24:12', '2025-06-26 15:09:31', NULL, NULL),
('14b9d149-9678-4dc5-91a2-5d8afc09c414', 'dd', 'ddd', 'ddd', 'ddd', 'Fixed, Can U check Now', '560c6c1f-5185-4ef4-9e8f-ef1315f38930', '608dc9d1-26e0-441d-8144-45f74c53a846', 'medium', 'fixed', '2025-09-10 14:51:25', '2025-09-10 14:53:49', '608dc9d1-26e0-441d-8144-45f74c53a846', '799b8406-7a20-4b8c-b7a9-c467f0c6268e'),
('15cc4d95-f4bd-4b8c-9aca-0b5af910097a', 'Mobile App Design', 'ddd', 'ddd', 'ddd', 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 'medium', 'fixed', '2025-09-10 14:04:53', '2025-09-10 14:08:00', '608dc9d1-26e0-441d-8144-45f74c53a846', '244ab83a-5049-4e61-8fb9-67e67281a3c2'),
('173e3fa1-9b43-44a8-bd58-0ed85ef815ea', 'sdfasd', 'dfsdfsd', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'medium', 'fixed', '2025-06-26 13:03:15', '2025-09-10 03:13:09', '608dc9d1-26e0-441d-8144-45f74c53a846', '608dc9d1-26e0-441d-8144-45f74c53a846'),
('1916c0f0-f039-411b-8903-a4c6df5a848c', 'meet', 'I create a meeting, not a session, from assi for the mentor, teacher, and student. But there (In mentor, teacher, student) is no option to show that ', NULL, NULL, NULL, '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'high', 'pending', '2025-06-26 06:34:30', '2025-06-26 15:09:31', NULL, NULL),
('1a91c86c-2e89-42f0-96ec-be01c8b08ffb', 'Mobile App Design', '            }\r\n\r\n            $this->conn->beginTransaction();\r\n\r\n            $bugId = $this->generateUUID();\r\n            \r\n            // Insert bug\r\n            $stmt = $this->conn->prepare(\"\r\n                INSERT INTO bugs (\r\n                    id, title, description, expected_result, actual_result, project_id, reported_by,\r\n                    priority, status\r\n                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)\r\n            \");\r\n\r\n            $result = $stmt->execute([', '            }\r\n\r\n            $this->conn->beginTransaction();\r\n\r\n            $bugId = $this->generateUUID();\r\n            \r\n            // Insert bug\r\n            $stmt = $this->conn->prepare(\"\r\n                INSERT INTO bugs (\r\n                    id, title, description, expected_result, actual_result, project_id, reported_by,\r\n                    priority, status\r\n                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)\r\n            \");\r\n\r\n            $result = $stmt->execute([', '            }\r\n\r\n            $this->conn->beginTransaction();\r\n\r\n            $bugId = $this->generateUUID();\r\n            \r\n            // Insert bug\r\n            $stmt = $this->conn->prepare(\"\r\n                INSERT INTO bugs (\r\n                    id, title, description, expected_result, actual_result, project_id, reported_by,\r\n                    priority, status\r\n                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)\r\n            \");\r\n\r\n            $result = $stmt->execute([', 'ndjdkd\r\n', '560c6c1f-5185-4ef4-9e8f-ef1315f38930', '608dc9d1-26e0-441d-8144-45f74c53a846', 'medium', 'fixed', '2025-09-10 01:52:21', '2025-09-10 03:10:11', '608dc9d1-26e0-441d-8144-45f74c53a846', '608dc9d1-26e0-441d-8144-45f74c53a846'),
('1c02352a-870d-41a2-a424-2f787740a600', 'Editing not updating', 'When editing students\' package Details, \"Sessions, Hours\" are not being changed', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'high', 'fixed', '2025-06-03 04:44:43', '2025-09-10 03:13:09', '608dc9d1-26e0-441d-8144-45f74c53a846', '608dc9d1-26e0-441d-8144-45f74c53a846'),
('205301a7-367f-4d68-9c24-ba87458cdba2', 'Development', 'szzzzzzzzzzzzz', NULL, NULL, NULL, '560c6c1f-5185-4ef4-9e8f-ef1315f38930', '608dc9d1-26e0-441d-8144-45f74c53a846', 'medium', 'pending', '2025-07-26 21:14:10', '2025-07-26 21:14:10', NULL, NULL),
('215dd067-2023-45ef-a5a1-d436cf85984a', 'Development', 'sssssssssssss', NULL, NULL, NULL, '560c6c1f-5185-4ef4-9e8f-ef1315f38930', '608dc9d1-26e0-441d-8144-45f74c53a846', 'high', 'pending', '2025-07-26 20:03:18', '2025-07-26 20:03:18', NULL, NULL),
('224de82d-cad8-4e8d-9c5d-f72ee90d5dd4', 'Course name not showing', 'The \"course name\" is not showing in the package view card', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'medium', 'fixed', '2025-06-04 07:18:26', '2025-09-10 03:13:09', '2', NULL),
('2250580b-1293-42fe-b78e-801a961e9851', 'Responsive ', 'student dashboard', NULL, NULL, NULL, '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'high', 'pending', '2025-06-20 07:18:29', '2025-06-26 15:09:31', NULL, NULL),
('276583ed-4997-465e-8ce0-465c28b9a208', 'Development', 'sssssssssssssssssssssssssssssss', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 'medium', 'fixed', '2025-06-27 05:48:15', '2025-09-10 03:13:09', 'd8edceb5-ca8a-446d-ad21-54d280064e69', 'd8edceb5-ca8a-446d-ad21-54d280064e69'),
('27c05415-aaef-4fe0-9c60-c0a289193816', 'Mobile App Design', 'sss', 'ssss', 'sss', 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 'medium', 'fixed', '2025-09-10 12:48:33', '2025-09-10 13:53:32', '608dc9d1-26e0-441d-8144-45f74c53a846', '244ab83a-5049-4e61-8fb9-67e67281a3c2'),
('2a966544-cb5a-4fac-b47e-0147a69d4042', 'Responsive issue', 'In the student\'s dashboard> the teacher Grid view is not responsive  \r\n\r\nAnd when zoomed out to 50% in the bottom left, it shows something else', NULL, NULL, NULL, '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'medium', 'pending', '2025-06-09 07:04:41', '2025-06-26 15:09:31', '6717', NULL),
('2d19daca-fe59-4d88-9a9d-96fd920a451c', 'Report', 'In finance and HR dashboard, report of teacher is not working', NULL, NULL, NULL, '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'high', 'pending', '2025-06-25 12:21:48', '2025-06-26 15:09:31', '6717', NULL),
('32113ce9-6e55-4bdc-bf44-607b8e885a3b', 'Feature is not available ', 'This is not available', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'high', 'fixed', '2025-06-03 05:16:21', '2025-09-10 03:13:09', '608', NULL),
('3354d7da-c0aa-4851-9bad-1cd157d60227', 'zfxgfzsdf', 'dfsd', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'medium', 'fixed', '2025-06-26 13:03:23', '2025-09-10 03:13:09', '608dc9d1-26e0-441d-8144-45f74c53a846', '608dc9d1-26e0-441d-8144-45f74c53a846'),
('34a4de24-43ed-40b6-b919-d93d018a8b8f', 'sample', 'aaaaaaaaaaaa', NULL, NULL, NULL, '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 'medium', 'pending', '2025-07-28 17:09:31', '2025-07-28 17:09:31', NULL, NULL),
('35214942-8782-4f71-8a04-58a65fa9ebae', 'ticket not showen', 'In the students\' dashboard and teacher\'s dashboard, I raised a Support ticket, but it does not show in \"Open ticket\" \n\nGet a notification of Tickets\n\n ', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'high', 'fixed', '2025-06-09 06:05:19', '2025-09-10 03:13:09', '608dc9d1-26e0-441d-8144-45f74c53a846', '608dc9d1-26e0-441d-8144-45f74c53a846'),
('36ec39e1-8b64-4a45-b36c-47f84caec3f9', 'Bulk update ', 'Students\' Bulk update is not working ', NULL, NULL, NULL, '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'high', 'pending', '2025-06-20 06:02:38', '2025-06-26 15:09:31', NULL, NULL),
('372460d4-3bc4-4b2a-981d-fca88a6b08b2', 'asdas', 'asdas', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'medium', 'fixed', '2025-06-26 13:02:58', '2025-09-10 03:13:09', '608dc9d1-26e0-441d-8144-45f74c53a846', '608dc9d1-26e0-441d-8144-45f74c53a846'),
('387fa609-8fba-4b96-a5d4-96bc4d4770af', 'Responsive issue', 'In the admin Dashboard, Student>payment details', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'medium', 'fixed', '2025-06-11 08:54:40', '2025-09-10 03:13:09', '6717', NULL),
('389def33-ecb5-434b-a141-22c6ff735d45', 'Mobile App Design', 'ssx', '', '', 'Fixed, Can U check Now', '560c6c1f-5185-4ef4-9e8f-ef1315f38930', '608dc9d1-26e0-441d-8144-45f74c53a846', 'medium', 'fixed', '2025-09-10 15:09:56', '2025-09-10 15:14:47', '608dc9d1-26e0-441d-8144-45f74c53a846', 'd84019a3-575f-403c-aa12-02482422bcfa'),
('410896cf-2b98-49c7-8d21-79d790e23e4b', 'Request failed', 'In the admin dashboard > student > Payment Details > ✓ and ❌ not working', NULL, NULL, NULL, '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'medium', 'pending', '2025-06-11 10:19:12', '2025-06-11 10:19:12', NULL, NULL),
('42d0f42a-2e41-47a7-9e77-20f37247355c', 'Package adding issue', 'In the admin dashboard package can be added, but it indicates an error message, not a successful alert \n\nWhen there was an error, I clicked the save button twice, then the same package added twice', NULL, NULL, NULL, '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'high', 'pending', '2025-06-17 09:34:01', '2025-06-26 15:09:31', '6717', NULL),
('4452ef00-1e11-4fd0-81ee-dd14c96a7467', 'wrong list', 'In the admin dashboard, in student view, choosing advisor shows the mentors list, not the advisors', NULL, NULL, NULL, '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'high', 'pending', '2025-06-26 06:40:02', '2025-06-26 15:09:31', NULL, NULL),
('4455f52e-da2e-4c7f-bf72-0150621a1e6e', 'Development', 'sssssssss', NULL, NULL, NULL, '560c6c1f-5185-4ef4-9e8f-ef1315f38930', '608dc9d1-26e0-441d-8144-45f74c53a846', 'medium', 'pending', '2025-07-28 16:39:14', '2025-07-28 16:39:14', NULL, NULL),
('47eb4792-501a-402a-8717-6e66a312c3fe', 'Dashboard', 'Graph in every Dashboard', NULL, NULL, NULL, '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'high', 'pending', '2025-06-25 04:25:17', '2025-06-26 15:09:31', NULL, NULL),
('506789bb-1caa-4e7c-8406-0bb828e69869', 'click back', 'In the student dashboard I click in dark mode that time get  a new alert message but it is not going back even after click profile', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'medium', 'fixed', '2025-06-26 04:41:37', '2025-09-10 03:13:09', '2', NULL),
('5402f209-9090-4d3b-b0cf-0a15ab004915', 'Development', 'sssssssssssssss', NULL, NULL, NULL, '560c6c1f-5185-4ef4-9e8f-ef1315f38930', '608dc9d1-26e0-441d-8144-45f74c53a846', 'medium', 'pending', '2025-07-28 15:43:13', '2025-07-28 15:43:13', NULL, NULL),
('54354937-01a8-40a4-8353-61dbbeea6641', 'Voice', 'Voice of teacher can not be heard ', NULL, NULL, NULL, '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'high', 'pending', '2025-06-26 06:08:06', '2025-06-26 15:09:31', NULL, NULL),
('556ef664-acbe-45c9-b8b7-adc004985a11', 'Search issue', 'In the Mentor Dashboard can not search in the session tab. When we type the second letter, it shows an error', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'high', 'fixed', '2025-06-09 05:01:01', '2025-09-10 03:13:09', '608dc9d1-26e0-441d-8144-45f74c53a846', '608dc9d1-26e0-441d-8144-45f74c53a846'),
('5a62e197-76b4-4ca6-b907-e3bba493d9e3', 'Student view card', 'In the mentor dashboard, student personal data area> when choosing the assigned advisor, it showed the mentor list ', NULL, NULL, NULL, '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'high', 'pending', '2025-06-24 06:55:04', '2025-06-26 15:09:31', NULL, NULL),
('5ddf401e-aae0-4fa5-a3ec-6edf1fdec3eb', 'Development', 'ssssssss', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 'medium', 'fixed', '2025-06-26 15:37:43', '2025-09-10 03:13:09', '608dc9d1-26e0-441d-8144-45f74c53a846', '608dc9d1-26e0-441d-8144-45f74c53a846'),
('60f688fd-46c2-4efc-8b9f-753ccc2e5f26', 'responsive', 'HR Dashboard Home session not responsive, (Mobile View) ', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'high', 'fixed', '2025-06-25 12:44:22', '2025-09-10 03:13:09', '2', NULL),
('62822b7f-6c89-4be3-95c5-7b73634438fc', 'Mobile App Design', 'ddd', 'ddd', 'ddd', NULL, '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 'medium', 'fixed', '2025-09-10 14:27:00', '2025-09-10 14:32:45', '244ab83a-5049-4e61-8fb9-67e67281a3c2', '244ab83a-5049-4e61-8fb9-67e67281a3c2'),
('62bb8143-53ba-45fa-bc2e-df98a0a59f60', 'Reschedule Request of teacher', 'Teacher\'s Reschedule Request not showing in the assistant admin, mentor, and student dashboard', NULL, NULL, NULL, '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'high', 'pending', '2025-06-26 06:49:09', '2025-06-26 15:09:31', NULL, NULL),
('679b83b9-aa60-438a-ae7c-986b85641bee', 'Mobile App Design', 'dddd', 'dd', 'dd', NULL, '560c6c1f-5185-4ef4-9e8f-ef1315f38930', '608dc9d1-26e0-441d-8144-45f74c53a846', 'medium', 'fixed', '2025-09-10 15:02:29', '2025-09-10 15:16:25', 'd84019a3-575f-403c-aa12-02482422bcfa', 'd84019a3-575f-403c-aa12-02482422bcfa'),
('6ce0bfd5-d20f-44b5-89d4-18b784031370', 'Development', 'sssssss', NULL, NULL, NULL, '560c6c1f-5185-4ef4-9e8f-ef1315f38930', '608dc9d1-26e0-441d-8144-45f74c53a846', 'medium', 'pending', '2025-07-28 16:46:29', '2025-07-28 16:46:29', NULL, NULL),
('6f204ff1-55f4-47b8-a474-656286ae3260', 'Assessment PDF download', 'The assessment downloaded PDF does not fit', NULL, NULL, NULL, '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'high', 'pending', '2025-06-17 09:41:52', '2025-06-26 15:09:31', NULL, NULL),
('726cf5b7-75ff-4a3e-a3a5-b917ed90a166', 'Development', 'ssssssssss', NULL, NULL, NULL, '560c6c1f-5185-4ef4-9e8f-ef1315f38930', '608dc9d1-26e0-441d-8144-45f74c53a846', 'medium', 'pending', '2025-07-28 16:06:59', '2025-07-28 16:06:59', NULL, NULL),
('737abbda-30fb-40f2-8f0d-f028aa200d9b', 'Development', 'sssssssssssssssssss', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 'medium', 'fixed', '2025-08-22 19:40:25', '2025-09-10 15:17:05', 'd84019a3-575f-403c-aa12-02482422bcfa', 'd84019a3-575f-403c-aa12-02482422bcfa'),
('73be336c-98da-4eb3-8262-d1d554fafa4e', 'dddd', 'ss', '', '', 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'd84019a3-575f-403c-aa12-02482422bcfa', 'medium', 'fixed', '2025-09-10 14:47:14', '2025-09-10 14:54:14', '608dc9d1-26e0-441d-8144-45f74c53a846', '799b8406-7a20-4b8c-b7a9-c467f0c6268e'),
('7734ccd1-5226-4729-8418-b9254d942d5c', 'Development', 'aaaaaaaa', NULL, NULL, NULL, '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 'medium', 'pending', '2025-07-26 20:56:44', '2025-07-26 20:56:44', NULL, NULL),
('7b29d86e-bb95-4794-a5e6-fa254ae5195e', 'join now button', 'The meeting will start within 28 minutes, but the \'Join Now\' button color is not green while the meeting link is loaded. ', NULL, NULL, NULL, '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'low', 'pending', '2025-06-26 05:56:01', '2025-06-26 15:09:31', NULL, NULL),
('7b56ed9d-6e4b-42f0-8841-758e31190f45', 'Session', 'In the student dashboard active session not showing after conform from finance \r\n', NULL, NULL, NULL, '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'high', 'pending', '2025-06-21 10:00:59', '2025-06-26 15:09:31', NULL, NULL),
('7b71d512-991c-42ab-97a0-5122e61ef9ec', 'Development', 'ssssssssssss', NULL, NULL, NULL, '560c6c1f-5185-4ef4-9e8f-ef1315f38930', '608dc9d1-26e0-441d-8144-45f74c53a846', 'medium', 'pending', '2025-07-28 16:17:34', '2025-07-28 16:17:34', NULL, NULL),
('7b9ea0ae-3e55-46e7-aff9-eabf666bdbc9', 'ssssssss', 'ssssssssssssssssss', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 'medium', 'fixed', '2025-06-26 15:20:52', '2025-09-10 03:13:09', '608dc9d1-26e0-441d-8144-45f74c53a846', '608dc9d1-26e0-441d-8144-45f74c53a846'),
('7c87bd81-4d12-403d-95d3-80a96c1c1a04', 'Development', 'aaaaaaaaaaa', NULL, NULL, NULL, '560c6c1f-5185-4ef4-9e8f-ef1315f38930', '608dc9d1-26e0-441d-8144-45f74c53a846', 'high', 'pending', '2025-07-26 21:37:41', '2025-07-26 21:37:41', NULL, NULL),
('83dec68d-3f33-4f91-970e-5385eca4ec2d', 'Responsive issue', 'Hr dashboard Home ', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'medium', 'fixed', '2025-06-05 06:11:47', '2025-09-10 03:13:09', '608dc9d1-26e0-441d-8144-45f74c53a846', '608dc9d1-26e0-441d-8144-45f74c53a846'),
('847e37b1-1582-43d0-b8cd-a6f1e5980064', 'Development', 'sssssssssss', NULL, NULL, NULL, '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 'medium', 'pending', '2025-07-28 15:21:54', '2025-07-28 15:21:54', NULL, NULL),
('891776f0-853c-4541-a460-7dfcca208507', 'package adding', 'In the sales head dashboard adding package for a student, these fields (as per screenshort) not required  ', NULL, NULL, NULL, '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'high', 'pending', '2025-06-25 05:33:38', '2025-06-26 15:09:31', NULL, NULL),
('8a28839d-b208-4b50-acbc-55b36aa46613', 'Change the mandatory ', 'In the advisor dashboard, when adding a package for a student, mark the Teacher selection, and the Teacher Salary is mandatory.\r\n\r\nTruly, it is not mandatory \r\n\r\nippo ath mark cheythittullath mandatory ayittan, sharikkum ath mandatory alla', NULL, NULL, NULL, '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'high', 'pending', '2025-06-13 10:51:50', '2025-06-26 15:09:31', '6717', NULL),
('8c0f4677-6a8a-4038-9fe8-d57811dfba82', 'Mobile App Design', 'zz', '', '', NULL, '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'c18ce191-e34b-4ca7-b69b-6a78488d3de5', 'medium', 'fixed', '2025-09-10 15:14:26', '2025-09-10 15:15:49', 'd84019a3-575f-403c-aa12-02482422bcfa', 'd84019a3-575f-403c-aa12-02482422bcfa'),
('8de5f320-d2ac-4a05-9993-bf90f12f6db8', 'Banner Ads', 'In this \"Redirect URL\" is not working.\r\n\r\nAnd add an Input field to add \"More info\" Google form link \r\n', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'medium', 'fixed', '2025-06-02 09:19:40', '2025-09-10 03:13:09', '608dc9d1-26e0-441d-8144-45f74c53a846', '608dc9d1-26e0-441d-8144-45f74c53a846'),
('9042de6c-54cf-40be-8104-7094764bb854', 'sample', 'ssssssssssss', NULL, NULL, NULL, '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 'high', 'pending', '2025-07-28 16:59:42', '2025-07-28 16:59:42', NULL, NULL),
('90c4c9d2-26d9-403f-bd43-799d7758ff8a', 'ASDasd', 'asdasd', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'medium', 'fixed', '2025-06-26 13:03:34', '2025-09-10 03:13:09', '608dc9d1-26e0-441d-8144-45f74c53a846', '608dc9d1-26e0-441d-8144-45f74c53a846'),
('90cf4d56-499b-4b39-9c1d-acfb241d5a2c', 'Teacher tab', 'In the admin dashboard>teacher tab, it shows all teachers in all tabs, such as online teacher, Offline teacher, and Home teacher', NULL, NULL, NULL, '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'high', 'pending', '2025-06-17 10:04:12', '2025-06-26 15:09:31', NULL, NULL),
('90ea392e-92da-4483-a272-3523d2f3b8c5', 'Development', 'ssssssssssss', NULL, NULL, NULL, '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 'medium', 'pending', '2025-07-28 16:48:00', '2025-07-28 16:48:00', NULL, NULL),
('9351eca3-59aa-4022-8550-121e89d225d5', 'dddddd', 'dddddddddddddd', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 'high', 'fixed', '2025-06-26 15:18:41', '2025-09-10 03:13:09', '608dc9d1-26e0-441d-8144-45f74c53a846', '608dc9d1-26e0-441d-8144-45f74c53a846'),
('94823d86-0259-4919-8720-33ce9e591103', 'sfdasdf', 'asdfczxc', NULL, NULL, NULL, '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'medium', 'pending', '2025-06-26 13:04:24', '2025-06-26 15:09:31', NULL, NULL),
('94b051d3-a2b6-485a-b0bd-21395c3f6b81', 'Option Remove', 'In the Advisor Dashboard, when adding a new package, remove the \"Teacher, Teacher salary\" Options', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'high', 'fixed', '2025-06-03 04:43:24', '2025-09-10 03:13:09', '608dc9d1-26e0-441d-8144-45f74c53a846', '608dc9d1-26e0-441d-8144-45f74c53a846'),
('9f06dc76-aecf-42dd-94a8-23025196e4e5', 'Development', 'sssssss', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 'medium', 'fixed', '2025-06-26 15:35:20', '2025-09-10 03:13:09', '608dc9d1-26e0-441d-8144-45f74c53a846', '608dc9d1-26e0-441d-8144-45f74c53a846'),
('9f59ba8d-d3dc-4f77-9c98-4d410231f5e1', 'Star of month not responsive in tablet view', 'Star of month not responsive in tablet view', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 'medium', 'fixed', '2025-06-08 06:15:32', '2025-09-10 03:13:09', '608dc9d1-26e0-441d-8144-45f74c53a846', '608dc9d1-26e0-441d-8144-45f74c53a846'),
('a15921c3-da55-40c2-9bb2-86d12d48b262', 'Mobile App Design', 'sdsds', 'dsd', '', 'Fixed, Can U check Now', '560c6c1f-5185-4ef4-9e8f-ef1315f38930', '608dc9d1-26e0-441d-8144-45f74c53a846', 'medium', 'fixed', '2025-09-10 15:02:06', '2025-09-10 15:16:32', 'd84019a3-575f-403c-aa12-02482422bcfa', 'd84019a3-575f-403c-aa12-02482422bcfa'),
('a20f1792-2ad2-4808-9d51-be821e92a395', 'Assistant admin edit', 'In the admin dashboard, when editing an Assistant admin\'s data, the \"Update Assistant\" button is not enabled, and when clicking on the \"Import\" button, it updates', NULL, NULL, NULL, '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'high', 'pending', '2025-06-24 06:31:23', '2025-06-26 15:09:31', NULL, NULL),
('a394e0c6-81c7-46a4-a285-6a71a8730036', 'ssssssssss', 'sssssssssssss', NULL, NULL, 'Fixed, Can U check Now', '560c6c1f-5185-4ef4-9e8f-ef1315f38930', '608dc9d1-26e0-441d-8144-45f74c53a846', 'medium', 'fixed', '2025-07-02 18:47:28', '2025-09-10 03:13:09', '608dc9d1-26e0-441d-8144-45f74c53a846', '608dc9d1-26e0-441d-8144-45f74c53a846'),
('a4c5aa14-4ed0-4304-87d1-445ad164dcb3', 'Teacher Card', 'In the student dashboard, techer view not availble', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'high', 'fixed', '2025-06-12 05:19:10', '2025-09-10 03:13:09', '6717', NULL),
('a5919d51-e5d0-4bdd-af41-67ac96111814', 'Deletion of \"other user\"', 'In the admin dashboard, when deleting a user added in \"other\", after a successful delete alert, it shows again ', NULL, NULL, NULL, '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'medium', 'pending', '2025-06-24 06:19:03', '2025-06-26 15:09:31', NULL, NULL),
('a6809481-6426-4cd7-b41b-b41e1cf97cba', 'DNS resolution failure', 'In the admin dashboard > student > error in console', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'medium', 'fixed', '2025-06-12 07:02:46', '2025-09-10 03:13:09', '6717', NULL),
('a79ff314-b290-4b59-b6cd-57a94115dcfd', 'Responsive issue', 'In the teacher dashboard> the Students\' view on tablet view is not responsive ', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'medium', 'fixed', '2025-06-09 09:06:33', '2025-09-10 03:13:09', '6717', NULL),
('a991ac8d-8342-40e0-abf1-44dda6e3404f', 'sample', 'sssssssss', NULL, NULL, 'Fixed, Can U check Now', '560c6c1f-5185-4ef4-9e8f-ef1315f38930', '608dc9d1-26e0-441d-8144-45f74c53a846', 'medium', 'fixed', '2025-08-25 08:29:59', '2025-09-10 03:13:09', '608dc9d1-26e0-441d-8144-45f74c53a846', '608dc9d1-26e0-441d-8144-45f74c53a846'),
('abe0f7e5-9e3c-43be-8ccf-f5135c42f961', 'Label text change ', 'Student id', NULL, NULL, NULL, '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'high', 'pending', '2025-06-20 07:22:51', '2025-06-26 15:09:31', NULL, NULL),
('b0e4dedf-dd62-4e99-9f6d-c06605e2267a', 'Unexpected Isuue', 'In the teacher dashboard home, when choosing \"Today,\" it is showing \"Unexpected Issue\"', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'medium', 'fixed', '2025-06-05 04:34:32', '2025-09-10 03:13:09', '2', NULL),
('b1deb45f-4441-4f8e-9fb1-7c6866f80ac7', 'Pre KG', 'ahksdgb', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'high', 'fixed', '2025-06-26 13:02:49', '2025-09-10 03:13:09', '608dc9d1-26e0-441d-8144-45f74c53a846', '608dc9d1-26e0-441d-8144-45f74c53a846'),
('b1e5e78b-8dad-4c9a-831b-b8d4cabadc4b', 'Mohammed Ajmal Nk', 'ssssssssss', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 'medium', 'fixed', '2025-06-26 15:10:05', '2025-09-10 03:13:09', '608dc9d1-26e0-441d-8144-45f74c53a846', '608dc9d1-26e0-441d-8144-45f74c53a846'),
('b4ca3b0b-ecb2-43a2-8a22-d351c65c772f', 'xxxxxxxxxxxxxxxx', 'xxxxxxxxxxxxxxxxxx', NULL, NULL, NULL, '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 'medium', 'fixed', '2025-08-25 07:42:54', '2025-09-10 15:19:38', 'd84019a3-575f-403c-aa12-02482422bcfa', 'd84019a3-575f-403c-aa12-02482422bcfa'),
('b5570224-446d-4103-afa6-daff7c812c32', 'payment details of student', 'mentor dashboard> student> payment details > when click on admission fee it shows in bottom status code 404', NULL, NULL, NULL, '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'high', 'pending', '2025-06-25 11:06:57', '2025-06-26 15:09:31', NULL, NULL),
('b6463237-bda9-411c-a9f8-6da5bc613c13', 'sdas', 'safafefasef', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'medium', 'fixed', '2025-06-26 13:04:52', '2025-09-10 03:13:09', '608dc9d1-26e0-441d-8144-45f74c53a846', '608dc9d1-26e0-441d-8144-45f74c53a846'),
('bac41e51-515f-401b-966a-ad50ca1e5263', 'button color', 'mentor dashboard \"Join Now\" button is working, but it\'s color not green', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'medium', 'fixed', '2025-06-25 10:59:43', '2025-09-10 03:13:09', '2', NULL),
('bc20d24d-a8ee-43be-9402-73e01b0742af', 'Position Changing ', 'In the advisor Dashboard, there is an option to choose days when adding a package, which removes it from there and adds this option in the \"Add New Student\"', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'high', 'fixed', '2025-06-02 10:34:04', '2025-09-10 03:13:09', '608dc9d1-26e0-441d-8144-45f74c53a846', '608dc9d1-26e0-441d-8144-45f74c53a846'),
('bc62f85a-a89c-4109-9ad7-5cfee4fffe42', 'Generate Link', 'Generate link is not proper working', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'high', 'fixed', '2025-06-11 08:56:37', '2025-09-10 03:13:09', '6717', NULL),
('bfbfdaf0-06ab-4ca2-8be3-9ec5ab044b1c', 'Session', 'In the admin dashboard i create a new session for now, but it is not available in active', NULL, NULL, NULL, '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'high', 'pending', '2025-06-23 03:59:28', '2025-06-23 03:59:28', NULL, NULL),
('bff6b03d-33eb-4ae3-911b-6c736f49ee2f', 'Data', 'In the assistant admin dashboard summery data is not okay', NULL, NULL, NULL, '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'high', 'pending', '2025-06-26 04:38:20', '2025-06-26 15:09:31', NULL, NULL),
('c1c28de6-62f2-420c-b5a8-6b6b681be5c1', 'Sayyid Suhail ', 'vdsvdscdsvdsvvdsvdscdsvdsvvdsvdscdsvdsvvdsvdscdsvdsvvdsvdscdsvdsvvdsvdscdsvdsvvdsvdscdsvdsvvdsvdscdsvdsvvdsvdscdsvdsvvdsvdscdsvdsvvdsvdscdsvdsvvdsvdscdsvdsvvdsvdscdsvdsvvdsvdscdsvdsvvdsvdscdsvdsvvdsvdscdsvdsvvdsvdscdsvdsvvdsvdscdsvdsvvdsvdscdsvdsvvdsvdscdsvdsvvdsvdscdsvdsvvdsvdscdsvdsvvdsvdscdsvdsvvdsvdscdsvdsvvdsvdscdsvdsvvdsvdscdsvdsvvdsvdscdsvdsvvdsvdscdsvdsvvdsvdscdsvdsvvdsvdscdsvdsvvdsvdscdsvdsvvdsvdscdsvdsvvdsvdscdsvdsvvdsvdscdsvdsvvdsvdscdsvdsv', NULL, NULL, 'Fixed, Can U check Now', '560c6c1f-5185-4ef4-9e8f-ef1315f38930', '608dc9d1-26e0-441d-8144-45f74c53a846', 'medium', 'fixed', '2025-09-09 09:10:53', '2025-09-10 03:13:09', '608dc9d1-26e0-441d-8144-45f74c53a846', '608dc9d1-26e0-441d-8144-45f74c53a846'),
('c35a7b38-3d5f-4e3f-85dc-244dfba9eaac', 'Package issue', 'In the admin Dashboard, I can not add a package, it shows an error\r\n\r\nand in the mentor and admin dashboards, appearing different package cards, which means inthe  admin dashboard, there is no option to choose the course', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'high', 'fixed', '2025-06-05 04:59:50', '2025-09-10 03:13:09', '2', NULL),
('c75d23ab-3e5d-4d2a-9676-c3d5b136aae4', 'Responsive', 'In the teacher\'s dashboard, sessions are not responsive', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'medium', 'fixed', '2025-06-09 09:37:36', '2025-09-10 03:13:09', '6717', NULL),
('c79c07a3-44b5-486f-96ba-5dff50071c09', 'Support ticket update', 'in admin dashboard support ticket can not edit', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'high', 'fixed', '2025-06-25 10:27:03', '2025-09-10 03:13:09', '2', NULL),
('c979c34f-f5ef-40e5-8e80-70bcf88852b4', 'sssssssssssssssssssssss', 'ssssssssssssssssssssssssssss', NULL, NULL, NULL, '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 'medium', 'fixed', '2025-08-28 19:11:33', '2025-09-10 13:56:57', 'd84019a3-575f-403c-aa12-02482422bcfa', 'd84019a3-575f-403c-aa12-02482422bcfa'),
('ccfcf0cc-0741-40c0-8d64-50ad0be4b46c', 'Development', 'aaaaaaaaaaaaaa', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 'medium', 'fixed', '2025-06-27 11:04:29', '2025-09-10 03:13:09', '608dc9d1-26e0-441d-8144-45f74c53a846', '608dc9d1-26e0-441d-8144-45f74c53a846'),
('ceb2f80c-6e97-4d7a-9b5f-0b639b5372ca', 'asdaszd', 'asdad', NULL, NULL, NULL, '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'medium', 'pending', '2025-06-26 13:03:58', '2025-06-26 15:09:31', NULL, NULL),
('cf5d263b-dbfd-4a6e-afa9-3b24db01c527', 'Development', 'ssssssssssssss', NULL, NULL, NULL, '560c6c1f-5185-4ef4-9e8f-ef1315f38930', '608dc9d1-26e0-441d-8144-45f74c53a846', 'high', 'pending', '2025-08-25 06:53:10', '2025-08-25 06:53:10', NULL, NULL),
('d03dfc17-ed5b-4341-be2c-202c8beee6eb', 'Development', 'sssssssssssssss', NULL, NULL, NULL, '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 'medium', 'pending', '2025-07-28 17:04:33', '2025-07-28 17:04:33', NULL, NULL),
('d436a7ba-5338-49e8-9011-efe770580438', 'time not ready', 'In the student dashboard, created feedback for the teacher and mentor in the posting times is wrong in both', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'medium', 'fixed', '2025-06-26 08:59:56', '2025-09-10 03:13:09', '608dc9d1-26e0-441d-8144-45f74c53a846', '608dc9d1-26e0-441d-8144-45f74c53a846'),
('d459305b-c579-4487-9b48-9d3484f3bf55', 'Assessment', 'In the admin dashboard, an assessment can not to created ', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'high', 'fixed', '2025-06-12 06:52:21', '2025-09-10 03:13:09', '6717', NULL),
('d45c880e-f422-4713-b860-4384cb9e188f', 'Mobile App Design', 'ddd', 'dddd', 'ddd', NULL, '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 'medium', 'fixed', '2025-09-10 14:23:55', '2025-09-10 14:38:53', 'd84019a3-575f-403c-aa12-02482422bcfa', 'd84019a3-575f-403c-aa12-02482422bcfa'),
('d9056581-a48e-45f2-bb1d-4e88ff658f6e', 'Add new ', 'In the session duration dropdown, Add \"30 Minutes\"', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'medium', 'fixed', '2025-06-05 04:28:33', '2025-09-10 03:13:09', '6717', NULL),
('dbd13771-2c0d-45ed-bef9-2a1837e96803', 'wrrong title', 'In the student dashboard,  profile settings show \"Employing ID\" instead of \"Student ID\"\r\n\r\n\r\n', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'medium', 'fixed', '2025-06-26 04:44:33', '2025-09-10 03:13:09', '2', NULL),
('dc1dafdc-cc71-4398-a582-49a5d2d2664a', 'Development', 'ccccccccc', NULL, NULL, NULL, '560c6c1f-5185-4ef4-9e8f-ef1315f38930', '608dc9d1-26e0-441d-8144-45f74c53a846', 'medium', 'pending', '2025-07-26 20:55:46', '2025-07-26 20:55:46', NULL, NULL),
('dc7fe5bc-b3b6-4f30-85c5-389216f8de57', 'Alice Johnsona', 'cvvc', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 'medium', 'fixed', '2025-08-25 08:09:43', '2025-09-10 14:09:42', '608dc9d1-26e0-441d-8144-45f74c53a846', '244ab83a-5049-4e61-8fb9-67e67281a3c2'),
('de5a65bd-c1b4-44db-a76b-723de292b723', 'teachers ', 'In HR dashboard, teachers tab, in offline and home tuition teachers asper Online teachers', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'high', 'fixed', '2025-06-25 12:34:09', '2025-09-10 03:13:09', '2', NULL),
('dfd0673e-f365-497b-830e-f2eb813b4920', 'Payment invoice ', 'When downloading a payment invoice from the student dashboard in the student\'s name is not showing\r\n\r\nAnd the PDF is not perfectly aligned ', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'medium', 'fixed', '2025-06-26 07:25:41', '2025-09-10 03:13:09', '608dc9d1-26e0-441d-8144-45f74c53a846', '608dc9d1-26e0-441d-8144-45f74c53a846'),
('e4ade0c4-915e-41d8-8c6d-cd53c0e327ef', 'dddd', 'dddd', 'dddd', 'dddd', NULL, '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 'medium', 'fixed', '2025-09-10 14:21:09', '2025-09-10 14:39:17', 'd84019a3-575f-403c-aa12-02482422bcfa', 'd84019a3-575f-403c-aa12-02482422bcfa'),
('e6427bd1-93a3-4576-9210-1a00f6334539', 'Error message', 'In the admin dashboard, open student view card and When click in certificate it indicates error ', NULL, NULL, NULL, '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'high', 'pending', '2025-06-18 06:53:14', '2025-06-26 15:09:31', NULL, NULL),
('e6d24bdf-bf34-4f37-9280-9318a25a4428', 'efefe', 'efeef', 'dfwdwd', 'efefefe', 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'd84019a3-575f-403c-aa12-02482422bcfa', 'medium', 'fixed', '2025-09-10 13:48:09', '2025-09-10 13:49:06', 'd84019a3-575f-403c-aa12-02482422bcfa', 'd84019a3-575f-403c-aa12-02482422bcfa'),
('e752bbcb-2f77-4c7a-a661-5cdfabee7601', 'Search issue', 'Sometimes, in the mentor dashboard, searching for students in the session tab, it looks like this ', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'medium', 'fixed', '2025-06-03 09:46:41', '2025-09-10 03:13:09', '608dc9d1-26e0-441d-8144-45f74c53a846', '608dc9d1-26e0-441d-8144-45f74c53a846'),
('e79dcad2-d0ea-4ea7-9fd9-5da539c31091', 'asda', 'zdfsf', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'medium', 'fixed', '2025-06-26 13:04:32', '2025-09-10 03:13:09', '608dc9d1-26e0-441d-8144-45f74c53a846', '608dc9d1-26e0-441d-8144-45f74c53a846'),
('ea53102f-bb77-4ffa-a571-362cf32a8417', 'display', 'In the admin dashboard, when adding a new student, we will choose an advisor and mentor, but it after the  assessment and package is added as a flow not show in the personal details of the student', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'medium', 'fixed', '2025-06-26 10:57:17', '2025-09-10 03:13:09', '608dc9d1-26e0-441d-8144-45f74c53a846', '608dc9d1-26e0-441d-8144-45f74c53a846'),
('ea94febb-402a-4c20-8870-da1bfa2c8b00', 'asdfsdf', 'ASFdhfgn ', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'medium', 'fixed', '2025-06-26 13:04:43', '2025-09-10 03:13:09', '608dc9d1-26e0-441d-8144-45f74c53a846', '608dc9d1-26e0-441d-8144-45f74c53a846'),
('eb9d9a46-1f72-40c3-a18b-4e433eb068f7', 'Development', 'ssssssss', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 'medium', 'fixed', '2025-06-26 15:22:04', '2025-09-10 03:13:09', '608dc9d1-26e0-441d-8144-45f74c53a846', '608dc9d1-26e0-441d-8144-45f74c53a846'),
('eba47b99-8ee7-48ce-8970-d3bbdd9e8901', 'WhatsApp number', 'In the student dashboard>teacher\r\n\r\nWe are collecting a WhatsApp number, but it is showing 2 numbers', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'medium', 'fixed', '2025-06-26 07:20:43', '2025-09-10 03:13:09', NULL, NULL),
('ec095625-7fe5-44e6-8cc8-5e656d4cd424', 'ssssss', 'ssssssssssssssssssssssssssss', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 'medium', 'fixed', '2025-06-26 15:43:19', '2025-09-10 03:13:09', '608dc9d1-26e0-441d-8144-45f74c53a846', '608dc9d1-26e0-441d-8144-45f74c53a846'),
('ed1011e6-8ca7-4907-9403-10a02052a65e', 'login', 'I created a assistant admin, but can not login\n\nI had changed phone number of this assistant admin, and when use old number as last four number can to login ', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'high', 'fixed', '2025-06-19 06:55:18', '2025-09-10 03:13:09', '608', NULL),
('f1144366-b337-4165-a413-61cde08b3c2a', 'Session', 'I created a new session for a student which starts in 5 minutes, but it is not showing in Admin, Mentor, Teacher, or Student  dashboard in active or other tabs', NULL, NULL, 'Fixed, Can U check Now', '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'high', 'fixed', '2025-06-05 04:21:48', '2025-09-10 03:13:09', '608dc9d1-26e0-441d-8144-45f74c53a846', '608dc9d1-26e0-441d-8144-45f74c53a846'),
('f92a617b-b49b-4c86-802a-e767f3f0739b', 'package in advisor dashboard', 'In the advisor dashboard, when add a package for my student, choosing days not required ', NULL, NULL, NULL, '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'high', 'pending', '2025-06-25 10:50:05', '2025-06-26 15:09:31', NULL, NULL),
('f9c37bfb-f697-43b4-9b19-79151791fb16', 'Session', 'In the admin and mentor dashboard session tab ', NULL, NULL, NULL, '672ff940-9c60-48ef-9444-ae8903b7b0cc', NULL, 'high', 'pending', '2025-06-24 07:43:36', '2025-06-26 15:09:31', NULL, NULL),
('fea4caee-bf2d-486b-970a-848aeb0825eb', 'Development', 'ssssssssssssssssssssssssssssssssss', NULL, NULL, NULL, '560c6c1f-5185-4ef4-9e8f-ef1315f38930', '608dc9d1-26e0-441d-8144-45f74c53a846', 'medium', 'pending', '2025-08-22 17:40:53', '2025-08-22 17:40:53', NULL, NULL);

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

--
-- Dumping data for table `bug_attachments`
--

INSERT INTO `bug_attachments` (`id`, `bug_id`, `file_name`, `file_path`, `file_type`, `uploaded_by`, `created_at`) VALUES
('007b7148-81f9-4347-8a24-7482c2d1b20c', '7b71d512-991c-42ab-97a0-5122e61ef9ec', 'PASSPORT BACK-2033.jpeg', 'uploads/screenshots/6887a29ee759b_PASSPORT BACK-2033.jpeg', 'image/jpeg', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-28 16:17:34'),
('02166863-060e-4f5e-8a57-f2383c24dadf', '6ce0bfd5-d20f-44b5-89d4-18b784031370', 'Voice Note 4.webm', 'uploads/voice_notes/6887a965c0a38_Voice Note 4.webm', 'audio/webm', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-28 16:46:29'),
('0f0f660a-e213-4516-8977-a36d6f77b795', '7734ccd1-5226-4729-8418-b9254d942d5c', 'hostinger payment.jpeg', 'uploads/screenshots/6885410c28346_hostinger payment.jpeg', 'image/jpeg', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-26 20:56:44'),
('1027c59a-2b01-4c72-b2a5-29f5b1feaaf4', '4455f52e-da2e-4c7f-bf72-0150621a1e6e', 'Voice Note 4.webm', 'uploads/voice_notes/6887a7b26062e_Voice Note 4.webm', 'audio/webm', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-28 16:39:14'),
('123fb8b4-c1d2-4386-9b2e-2c1bb60eaf60', '215dd067-2023-45ef-a5a1-d436cf85984a', 'hostinger payment.jpeg', 'uploads/screenshots/688534864f82a_hostinger payment.jpeg', 'image/jpeg', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-26 20:03:18'),
('14d8b998-ee61-4e65-9750-656cd3b8508d', 'ccfcf0cc-0741-40c0-8d64-50ad0be4b46c', 'WhatsApp Image 2025-06-09 at 9.17.12 PM (1).jpeg', 'uploads/screenshots/685e7abd7d02c_WhatsApp Image 2025-06-09 at 9.17.12 PM (1).jpeg', 'image/jpeg', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-06-27 11:04:29'),
('14f927c6-7afa-4d36-9dc5-7fa4562c3525', '7c87bd81-4d12-403d-95d3-80a96c1c1a04', 'Voice Note 1.wav', 'uploads/voice_notes/68854aa5b9957_Voice Note 1.wav', 'audio/wav', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-26 21:37:41'),
('17b49ccc-7396-44d9-a176-be76ece0e96f', '0b6eaa55-e466-4a2c-8a77-ddd7bd055e4d', 'PASSPORT-2033.jpeg', 'uploads/screenshots/68879c5a7f04f_PASSPORT-2033.jpeg', 'image/jpeg', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-28 15:50:50'),
('17ffb651-7ce5-4380-aca7-b0d0c7f50343', '7b71d512-991c-42ab-97a0-5122e61ef9ec', 'Voice Note 3.webm', 'uploads/voice_notes/6887a29ee8b04_Voice Note 3.webm', 'audio/webm', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-28 16:17:34'),
('1bb8fe19-1d56-4456-b055-ffe8499c1e1a', 'fea4caee-bf2d-486b-970a-848aeb0825eb', 'WhatsApp Image 2025-07-12 at 1.55.24 PM.jpeg', 'uploads/screenshots/68a8aba5b4ee2_WhatsApp Image 2025-07-12 at 1.55.24 PM.jpeg', 'image/jpeg', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-08-22 17:40:53'),
('1c628ad3-0597-43a3-b6e8-3616859618df', 'fea4caee-bf2d-486b-970a-848aeb0825eb', 'evoka.png', 'uploads/files/68a8aba5bf015_evoka.png', 'image/png', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-08-22 17:40:53'),
('25f047c4-767a-4aa1-a972-57ad6df891ad', '7c87bd81-4d12-403d-95d3-80a96c1c1a04', 'PASSPORT BACK-2033.jpeg', 'uploads/screenshots/68854aa5b7546_PASSPORT BACK-2033.jpeg', 'image/jpeg', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-26 21:37:41'),
('284b8ebe-9401-43ac-bfe8-0f430a773557', 'fea4caee-bf2d-486b-970a-848aeb0825eb', '1 (1).jpg', 'uploads/screenshots/68a8aba5b7b9c_1 (1).jpg', 'image/jpeg', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-08-22 17:40:53'),
('32616097-9f11-4d31-9752-0d262db18900', '6ce0bfd5-d20f-44b5-89d4-18b784031370', 'Voice Note 3.webm', 'uploads/voice_notes/6887a965c0477_Voice Note 3.webm', 'audio/webm', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-28 16:46:29'),
('33ed56b1-a2f9-41d5-b74b-3f9fea174141', '726cf5b7-75ff-4a3e-a3a5-b917ed90a166', 'Voice Note 1.webm', 'uploads/voice_notes/6887a023483ca_Voice Note 1.webm', 'audio/webm', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-28 16:06:59'),
('34a84a08-5cb8-4bd0-87af-6f86f6872aa6', '205301a7-367f-4d68-9c24-ba87458cdba2', 'Voice Note 1.wav', 'uploads/voice_notes/688545228dc7e_Voice Note 1.wav', 'audio/wav', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-26 21:14:10'),
('36728f05-5a46-4ac3-b4dc-a8242089d6a1', 'dc1dafdc-cc71-4398-a582-49a5d2d2664a', 'Voice Note 1.wav', 'uploads/voice_notes/688540d2903a7_Voice Note 1.wav', 'audio/wav', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-26 20:55:46'),
('391e6f77-35f5-42ce-a8a2-f53bd023478a', 'd03dfc17-ed5b-4341-be2c-202c8beee6eb', 'Voice Note 1.webm', 'uploads/voice_notes/6887ada1c644e_Voice Note 1.webm', 'audio/webm', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-28 17:04:33'),
('3958dde8-6207-45ba-905f-23297464789c', '90ea392e-92da-4483-a272-3523d2f3b8c5', 'Voice Note 1.webm', 'uploads/voice_notes/6887a9c0c61a6_Voice Note 1.webm', 'audio/webm', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-28 16:48:00'),
('3f10a4f2-f93e-4aae-a0a7-96fc68625691', 'cf5d263b-dbfd-4a6e-afa9-3b24db01c527', 'WhatsApp Image 2024-12-12 at 9.58.35 AM.jpeg', 'uploads/screenshots/68ac0856e9944_WhatsApp Image 2024-12-12 at 9.58.35 AM.jpeg', 'image/jpeg', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-08-25 06:53:10'),
('3fdfd597-3fbe-41e6-9b44-dfa4642cb99b', 'cf5d263b-dbfd-4a6e-afa9-3b24db01c527', 'Voice Note 1.webm', 'uploads/voice_notes/68ac0856ec4a9_Voice Note 1.webm', 'audio/webm', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-08-25 06:53:10'),
('3ffb2072-1543-45de-85eb-1cf37126a3a9', 'ccfcf0cc-0741-40c0-8d64-50ad0be4b46c', 'WhatsApp Image 2025-06-09 at 9.17.12 PM (2).jpeg', 'uploads/screenshots/685e7abd7cb26_WhatsApp Image 2025-06-09 at 9.17.12 PM (2).jpeg', 'image/jpeg', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-06-27 11:04:29'),
('4366e844-63b7-4f48-bc73-b7c404f9cab1', '847e37b1-1582-43d0-b8cd-a6f1e5980064', 'Playstore 004.png', 'uploads/screenshots/6887959222ea9_Playstore 004.png', 'image/png', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-28 15:21:54'),
('46c71ac2-7465-4a27-af0e-e2c60e909f0a', 'fea4caee-bf2d-486b-970a-848aeb0825eb', 'Playstore 006.png', 'uploads/screenshots/68a8aba5b36df_Playstore 006.png', 'image/png', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-08-22 17:40:53'),
('4795b2da-8694-4d92-86a6-96feae6e4062', '6ce0bfd5-d20f-44b5-89d4-18b784031370', 'Voice Note 2.webm', 'uploads/voice_notes/6887a965be28a_Voice Note 2.webm', 'audio/webm', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-28 16:46:29'),
('4827601d-fb61-4a9b-8232-f1348fae80dd', '4455f52e-da2e-4c7f-bf72-0150621a1e6e', 'Voice Note 2.webm', 'uploads/voice_notes/6887a7b25fc53_Voice Note 2.webm', 'audio/webm', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-28 16:39:14'),
('49eb0e13-864e-491f-8473-bf0810924d6f', 'fea4caee-bf2d-486b-970a-848aeb0825eb', '6 (1).jpg', 'uploads/screenshots/68a8aba5b727a_6 (1).jpg', 'image/jpeg', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-08-22 17:40:53'),
('49f4ca50-6dac-40d1-acc7-e015580bdb84', '215dd067-2023-45ef-a5a1-d436cf85984a', 'PASSPORT-2033.jpeg', 'uploads/screenshots/688534864f4bf_PASSPORT-2033.jpeg', 'image/jpeg', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-26 20:03:18'),
('4c29afb1-c3ea-46a4-929a-bb3883d62599', '215dd067-2023-45ef-a5a1-d436cf85984a', 'PASSPORT BACK-2033.jpeg', 'uploads/screenshots/688534864e43e_PASSPORT BACK-2033.jpeg', 'image/jpeg', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-26 20:03:18'),
('4ffb3199-1a6b-4007-bde4-3258783ec913', '847e37b1-1582-43d0-b8cd-a6f1e5980064', 'voice_notes.sql', 'uploads/files/6887959224d2f_voice_notes.sql', 'application/octet-stream', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-28 15:21:54'),
('51754b78-4785-4f45-9c4f-b4e9f09d20fd', '9042de6c-54cf-40be-8104-7094764bb854', 'Voice Note 2.webm', 'uploads/voice_notes/6887ac7ec6f5c_Voice Note 2.webm', 'audio/webm', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-28 16:59:42'),
('5194127b-28e4-46d6-83eb-c0ef1ab8d744', 'fea4caee-bf2d-486b-970a-848aeb0825eb', 'Playstore 002.png', 'uploads/screenshots/68a8aba5b792f_Playstore 002.png', 'image/png', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-08-22 17:40:53'),
('5ceafb1a-4a1b-4953-b549-50c67cabc154', '205301a7-367f-4d68-9c24-ba87458cdba2', 'PASSPORT-2033.jpeg', 'uploads/files/6885452288e0e_PASSPORT-2033.jpeg', 'image/jpeg', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-26 21:14:10'),
('5e030657-f792-4ea5-a9e5-64e1bf34bcb9', 'fea4caee-bf2d-486b-970a-848aeb0825eb', 'profile screenshot.png', 'uploads/files/68a8aba5baf2b_profile screenshot.png', 'image/png', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-08-22 17:40:53'),
('5e344636-a4b6-4bb4-856d-a442065d63ab', '4455f52e-da2e-4c7f-bf72-0150621a1e6e', 'Voice Note 3.webm', 'uploads/voice_notes/6887a7b26000e_Voice Note 3.webm', 'audio/webm', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-28 16:39:14'),
('5f106421-33c4-4afd-900c-4aa52f12fe14', 'fea4caee-bf2d-486b-970a-848aeb0825eb', 'evoka-logo.png', 'uploads/files/68a8aba5bb3e9_evoka-logo.png', 'image/png', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-08-22 17:40:53'),
('5f5d9d4f-26e6-4467-9608-e0b2404a7a2d', 'fea4caee-bf2d-486b-970a-848aeb0825eb', 'WhatsApp Image 2025-07-12 at 2.11.24 PM.jpeg', 'uploads/screenshots/68a8aba5b492c_WhatsApp Image 2025-07-12 at 2.11.24 PM.jpeg', 'image/jpeg', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-08-22 17:40:53'),
('618fb1ac-8e60-4e17-b34d-58538a7f51eb', 'ccfcf0cc-0741-40c0-8d64-50ad0be4b46c', 'WhatsApp Image 2025-06-10 at 8.12.18 PM.jpeg', 'uploads/screenshots/685e7abd7414a_WhatsApp Image 2025-06-10 at 8.12.18 PM.jpeg', 'image/jpeg', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-06-27 11:04:29'),
('63244c8d-da0e-4c66-a1c2-84bdf8edea89', 'fea4caee-bf2d-486b-970a-848aeb0825eb', 'iPhone 13 & 14 - 1.jpg', 'uploads/screenshots/68a8aba5b7668_iPhone 13 & 14 - 1.jpg', 'image/jpeg', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-08-22 17:40:53'),
('651a7a8c-de8f-4d7a-a436-347464e675a6', '7b71d512-991c-42ab-97a0-5122e61ef9ec', 'Voice Note 2.webm', 'uploads/voice_notes/6887a29ee8827_Voice Note 2.webm', 'audio/webm', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-28 16:17:34'),
('67867cf1-9410-4f46-894f-ee9b121cb8d6', 'd03dfc17-ed5b-4341-be2c-202c8beee6eb', 'Voice Note 2.webm', 'uploads/voice_notes/6887ada1c6a50_Voice Note 2.webm', 'audio/webm', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-28 17:04:33'),
('67aa4c73-b7d7-4d54-9882-9c56361d061a', '0b6eaa55-e466-4a2c-8a77-ddd7bd055e4d', 'hostinger payment.jpeg', 'uploads/screenshots/68879c5a7f5dd_hostinger payment.jpeg', 'image/jpeg', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-28 15:50:50'),
('68427551-b429-4703-892e-6ac78c5aecd3', 'fea4caee-bf2d-486b-970a-848aeb0825eb', 'Playstore 003.png', 'uploads/screenshots/68a8aba5b5526_Playstore 003.png', 'image/png', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-08-22 17:40:53'),
('6d48300a-ba62-4ab3-b4e6-5df77e678fa6', 'fea4caee-bf2d-486b-970a-848aeb0825eb', 'WhatsApp Image 2025-07-12 at 1.56.14 PM.jpeg', 'uploads/screenshots/68a8aba5b4c2f_WhatsApp Image 2025-07-12 at 1.56.14 PM.jpeg', 'image/jpeg', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-08-22 17:40:53'),
('6e30d457-c1f4-4874-9f64-95f422356258', '7b71d512-991c-42ab-97a0-5122e61ef9ec', 'Voice Note 1.webm', 'uploads/voice_notes/6887a29ee852d_Voice Note 1.webm', 'audio/webm', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-28 16:17:34'),
('721ecfa8-0829-41da-9208-84bc7025d52c', '0b6eaa55-e466-4a2c-8a77-ddd7bd055e4d', 'Voice Note 1.webm', 'uploads/voice_notes/68879c5a7ff82_Voice Note 1.webm', 'audio/webm', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-28 15:50:50'),
('74f672c4-465d-475c-9cef-7b217fa4f7fc', '847e37b1-1582-43d0-b8cd-a6f1e5980064', 'WhatsApp Image 2025-07-12 at 1.55.24 PM.jpeg', 'uploads/screenshots/6887959222b32_WhatsApp Image 2025-07-12 at 1.55.24 PM.jpeg', 'image/jpeg', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-28 15:21:54'),
('7823a06d-1095-4636-8b48-a34b65f04fad', '847e37b1-1582-43d0-b8cd-a6f1e5980064', 'WhatsApp Image 2025-07-12 at 1.56.14 PM.jpeg', 'uploads/screenshots/6887959221f3f_WhatsApp Image 2025-07-12 at 1.56.14 PM.jpeg', 'image/jpeg', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-28 15:21:54'),
('7ec6b96b-e719-4f57-add0-3d3323a1c5df', '410896cf-2b98-49c7-8d21-79d790e23e4b', 'image.png', 'uploads/screenshots/68495820a64a4_image.png', 'image/png', NULL, '2025-06-11 10:19:12'),
('8082f156-8bc4-473b-82d0-5e901fb7907a', '276583ed-4997-465e-8ce0-465c28b9a208', 'WhatsApp Image 2025-06-09 at 9.17.12 PM (2).jpeg', 'uploads/screenshots/685e309f4c3a2_WhatsApp Image 2025-06-09 at 9.17.12 PM (2).jpeg', 'image/jpeg', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-06-27 05:48:15'),
('816719ae-f224-44af-8ccb-fffb76f350f5', 'ccfcf0cc-0741-40c0-8d64-50ad0be4b46c', 'media files-Cover.jpg', 'uploads/screenshots/685e7abd7df9f_media files-Cover.jpg', 'image/jpeg', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-06-27 11:04:29'),
('825ce833-b7fc-4fbd-972b-b8b595f58a50', 'ccfcf0cc-0741-40c0-8d64-50ad0be4b46c', 'WhatsApp Image 2025-06-19 at 8.42.41 AM (1).jpeg', 'uploads/screenshots/685e7abd69082_WhatsApp Image 2025-06-19 at 8.42.41 AM (1).jpeg', 'image/jpeg', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-06-27 11:04:29'),
('836b58b4-2e84-42ef-9ba3-5a130feaf057', 'ccfcf0cc-0741-40c0-8d64-50ad0be4b46c', 'WhatsApp Image 2025-06-09 at 9.17.11 PM.jpeg', 'uploads/screenshots/685e7abd7d5fb_WhatsApp Image 2025-06-09 at 9.17.11 PM.jpeg', 'image/jpeg', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-06-27 11:04:29'),
('83d22d16-80b9-4786-8bd9-ceade29edb3d', '847e37b1-1582-43d0-b8cd-a6f1e5980064', '6 (1).jpg', 'uploads/screenshots/688795922449d_6 (1).jpg', 'image/jpeg', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-28 15:21:54'),
('8580eb16-3da6-44f0-9e8a-9285773c210c', '205301a7-367f-4d68-9c24-ba87458cdba2', 'hostinger payment.jpeg', 'uploads/screenshots/6885452288574_hostinger payment.jpeg', 'image/jpeg', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-26 21:14:10'),
('8888db6a-4c4d-4c7f-8eff-dc36c848e7e9', '7734ccd1-5226-4729-8418-b9254d942d5c', 'whatsapp_messages_2025-07-26T19_45_47.214Z.csv', 'uploads/files/6885410c28cf9_whatsapp_messages_2025-07-26T19_45_47.214Z.csv', 'text/csv', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-26 20:56:44'),
('8ba42951-c4d8-405d-9858-7c9801271b59', '276583ed-4997-465e-8ce0-465c28b9a208', 'WhatsApp Image 2025-06-10 at 8.12.18 PM.jpeg', 'uploads/screenshots/685e309f49393_WhatsApp Image 2025-06-10 at 8.12.18 PM.jpeg', 'image/jpeg', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-06-27 05:48:15'),
('9094f5f5-9af5-49ed-9938-889bd1eea1b7', '7c87bd81-4d12-403d-95d3-80a96c1c1a04', 'PASSPORT-2033.jpeg', 'uploads/screenshots/68854aa5b8fcd_PASSPORT-2033.jpeg', 'image/jpeg', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-26 21:37:41'),
('93b3e313-649d-4b5b-aa22-bd58a3193ba1', '0c3397b8-a651-4970-a26b-a4854f075e84', 'image.png', 'uploads/screenshots/683ff1c814383_image.png', 'image/png', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-06-04 07:12:08'),
('98f41502-22fc-454c-8202-f811f0235997', '9042de6c-54cf-40be-8104-7094764bb854', 'Voice Note 1.webm', 'uploads/voice_notes/6887ac7ec3630_Voice Note 1.webm', 'audio/webm', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-28 16:59:42'),
('9ac141b9-24cf-4abb-943b-7301bd780f31', 'ccfcf0cc-0741-40c0-8d64-50ad0be4b46c', 'brandkit-16.png', 'uploads/screenshots/685e7abd7d892_brandkit-16.png', 'image/png', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-06-27 11:04:29'),
('9adfd83d-b8fe-427d-a119-4f4d8415d0f0', '276583ed-4997-465e-8ce0-465c28b9a208', 'Adobe Express - file.jpg', 'uploads/screenshots/685e309f4b97b_Adobe Express - file.jpg', 'image/jpeg', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-06-27 05:48:15'),
('9e842840-1334-4f92-96ef-83d347c64e13', '726cf5b7-75ff-4a3e-a3a5-b917ed90a166', 'Voice Note 2.webm', 'uploads/voice_notes/6887a023491c8_Voice Note 2.webm', 'audio/webm', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-28 16:06:59'),
('a8d7b334-18f8-464d-b57d-6191576f823b', '276583ed-4997-465e-8ce0-465c28b9a208', 'WhatsApp Image 2025-06-09 at 9.17.12 PM (3).jpeg', 'uploads/screenshots/685e309f4bf19_WhatsApp Image 2025-06-09 at 9.17.12 PM (3).jpeg', 'image/jpeg', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-06-27 05:48:15'),
('abaca69b-2fed-4cae-b84d-1b09df964a00', 'fea4caee-bf2d-486b-970a-848aeb0825eb', 'Playstore 001.png', 'uploads/screenshots/68a8aba5b7e49_Playstore 001.png', 'image/png', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-08-22 17:40:53'),
('ada09f90-5d4e-48d1-9184-51a47f7cb90b', 'cf5d263b-dbfd-4a6e-afa9-3b24db01c527', 'WhatsApp Image 2024-12-12 at 9.59.43 AM.jpeg', 'uploads/screenshots/68ac0856ebf1d_WhatsApp Image 2024-12-12 at 9.59.43 AM.jpeg', 'image/jpeg', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-08-25 06:53:10'),
('af50187a-35f0-4353-a8f7-9a243bae232f', '205301a7-367f-4d68-9c24-ba87458cdba2', 'PASSPORT BACK-2033.jpeg', 'uploads/screenshots/688545228772a_PASSPORT BACK-2033.jpeg', 'image/jpeg', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-26 21:14:10'),
('b1573268-4b4b-4221-b88d-8391d253b841', '34a4de24-43ed-40b6-b919-d93d018a8b8f', 'Voice Note 2.webm', 'uploads/voice_notes/6887aecb2f149_Voice Note 2.webm', 'audio/webm', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-28 17:09:31'),
('b8e34f2b-c628-4b1c-9106-5df5f3248604', '4455f52e-da2e-4c7f-bf72-0150621a1e6e', 'Voice Note 1.webm', 'uploads/voice_notes/6887a7b25f125_Voice Note 1.webm', 'audio/webm', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-28 16:39:14'),
('ba0797b4-b62c-4557-8954-e0b55d1ba39a', 'ccfcf0cc-0741-40c0-8d64-50ad0be4b46c', 'comments-Cover.jpg', 'uploads/screenshots/685e7abd745a2_comments-Cover.jpg', 'image/jpeg', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-06-27 11:04:29'),
('bb72f9ba-26ae-405a-b4d1-45608c1803be', '847e37b1-1582-43d0-b8cd-a6f1e5980064', 'Playstore 003.png', 'uploads/screenshots/68879592232b4_Playstore 003.png', 'image/png', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-28 15:21:54'),
('bdaa27f6-3957-49c6-83bb-54057320c693', '7734ccd1-5226-4729-8418-b9254d942d5c', 'PASSPORT-2033.jpeg', 'uploads/screenshots/6885410c1b90b_PASSPORT-2033.jpeg', 'image/jpeg', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-26 20:56:44'),
('c17c4d2c-44e5-49da-939a-d0458663d249', '205301a7-367f-4d68-9c24-ba87458cdba2', 'PASSPORT-2033.jpeg', 'uploads/screenshots/6885452288166_PASSPORT-2033.jpeg', 'image/jpeg', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-26 21:14:10'),
('c2015b85-eb78-42d6-94c8-9c9fbd6ab857', '7734ccd1-5226-4729-8418-b9254d942d5c', 'Voice Note 2.wav', 'uploads/voice_notes/6885410c2b4d3_Voice Note 2.wav', 'audio/wav', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-26 20:56:44'),
('c2548142-03fb-48de-8d0d-8d4549d72874', '0b6eaa55-e466-4a2c-8a77-ddd7bd055e4d', 'Voice Note 2.webm', 'uploads/voice_notes/68879c5a80351_Voice Note 2.webm', 'audio/webm', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-28 15:50:50'),
('c420d923-3d6f-4f38-bd75-53a11cb913b0', 'fea4caee-bf2d-486b-970a-848aeb0825eb', 'Playstore 005.png', 'uploads/screenshots/68a8aba5b443c_Playstore 005.png', 'image/png', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-08-22 17:40:53'),
('cb31bd7a-9057-4e8a-8be3-f0c7f8f85dcd', 'ccfcf0cc-0741-40c0-8d64-50ad0be4b46c', 'Adobe Express - file.jpg', 'uploads/screenshots/685e7abd74a7a_Adobe Express - file.jpg', 'image/jpeg', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-06-27 11:04:29'),
('cb4d2d7b-7ff5-42a3-9f44-38739bf1e59a', '7734ccd1-5226-4729-8418-b9254d942d5c', 'Voice Note 1.wav', 'uploads/voice_notes/6885410c294bb_Voice Note 1.wav', 'audio/wav', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-26 20:56:44'),
('d0c83493-21fa-4722-ac93-f207c8fbc2ff', '90ea392e-92da-4483-a272-3523d2f3b8c5', 'Voice Note 2.webm', 'uploads/voice_notes/6887a9c0c68a1_Voice Note 2.webm', 'audio/webm', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-28 16:48:00'),
('d45919f0-5138-45c6-a852-cd45fcefbd93', 'fea4caee-bf2d-486b-970a-848aeb0825eb', 'Playstore 004.png', 'uploads/screenshots/68a8aba5b5178_Playstore 004.png', 'image/png', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-08-22 17:40:53'),
('d5c19c38-46ea-4238-bd5c-82c9fea69df7', 'ccfcf0cc-0741-40c0-8d64-50ad0be4b46c', 'image.png', 'uploads/screenshots/685e7abd7dd00_image.png', 'image/png', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-06-27 11:04:29'),
('d5c377de-42d5-4dd5-9e23-fe0f49ffb49e', 'ccfcf0cc-0741-40c0-8d64-50ad0be4b46c', 'WhatsApp Image 2025-06-09 at 9.17.12 PM.jpeg', 'uploads/screenshots/685e7abd7d3a1_WhatsApp Image 2025-06-09 at 9.17.12 PM.jpeg', 'image/jpeg', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-06-27 11:04:29'),
('d7878bd1-03cd-4026-8174-56e960551d82', '7734ccd1-5226-4729-8418-b9254d942d5c', 'PASSPORT BACK-2033.jpeg', 'uploads/screenshots/6885410c1b1f6_PASSPORT BACK-2033.jpeg', 'image/jpeg', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-26 20:56:44'),
('de19943a-d2b0-44f4-ba73-af69c36006f1', '205301a7-367f-4d68-9c24-ba87458cdba2', 'PASSPORT BACK-2033.jpeg', 'uploads/files/6885452288aee_PASSPORT BACK-2033.jpeg', 'image/jpeg', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-26 21:14:10'),
('e05944fa-3cc0-4f57-8364-86138b9f367e', '7b71d512-991c-42ab-97a0-5122e61ef9ec', 'hostinger payment.jpeg', 'uploads/screenshots/6887a29ee8117_hostinger payment.jpeg', 'image/jpeg', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-28 16:17:34'),
('e4e53989-728e-4e87-b2d2-92a6df6801ac', 'fea4caee-bf2d-486b-970a-848aeb0825eb', 'Voice Note 1.webm', 'uploads/voice_notes/68a8aba5bf369_Voice Note 1.webm', 'audio/webm', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-08-22 17:40:53'),
('e5b2fa46-1f96-41ed-aae3-42894c404d2f', '7b71d512-991c-42ab-97a0-5122e61ef9ec', 'Voice Note 4.webm', 'uploads/voice_notes/6887a29ee8dc8_Voice Note 4.webm', 'audio/webm', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-28 16:17:34'),
('e7296fae-2919-477e-ba69-7e5fd1a5f97d', '7b71d512-991c-42ab-97a0-5122e61ef9ec', 'PASSPORT-2033.jpeg', 'uploads/screenshots/6887a29ee7dd7_PASSPORT-2033.jpeg', 'image/jpeg', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-28 16:17:34'),
('ecbcb539-be4a-4bd2-8c42-0f873d45891b', '0b6eaa55-e466-4a2c-8a77-ddd7bd055e4d', 'PASSPORT BACK-2033.jpeg', 'uploads/screenshots/68879c5a7d5a9_PASSPORT BACK-2033.jpeg', 'image/jpeg', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-28 15:50:50'),
('f25a3f3d-6415-4a70-8b30-19e50cab4d85', '34a4de24-43ed-40b6-b919-d93d018a8b8f', 'Voice Note 1.webm', 'uploads/voice_notes/6887aecb29212_Voice Note 1.webm', 'audio/webm', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-28 17:09:31'),
('f449f46a-876d-49ba-950a-2e9bb2fb0dea', '9f59ba8d-d3dc-4f77-9c98-4d410231f5e1', 'Operations  Albedo Educator.png', 'uploads/screenshots/68452a8435374_Operations  Albedo Educator.png', 'image/png', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-06-08 06:15:32'),
('f81c4a73-2892-4485-ab02-2aaff2e5b6ed', '847e37b1-1582-43d0-b8cd-a6f1e5980064', 'Voice Note 1.webm', 'uploads/voice_notes/68879592250f7_Voice Note 1.webm', 'audio/webm', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-28 15:21:54'),
('f846ae25-ca03-4b0b-af8e-bccbf1cd2931', '6ce0bfd5-d20f-44b5-89d4-18b784031370', 'Voice Note 1.webm', 'uploads/voice_notes/6887a965bbdc6_Voice Note 1.webm', 'audio/webm', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-28 16:46:29'),
('fa3e4208-f5be-4a46-85b3-bc4111de13db', '7c87bd81-4d12-403d-95d3-80a96c1c1a04', 'hostinger payment.jpeg', 'uploads/screenshots/68854aa5b956e_hostinger payment.jpeg', 'image/jpeg', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-26 21:37:41'),
('fbe0860e-4e47-4f00-b633-d52659064482', '847e37b1-1582-43d0-b8cd-a6f1e5980064', 'voice_note_history.sql', 'uploads/files/68879592249a9_voice_note_history.sql', 'application/octet-stream', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-28 15:21:54');

-- --------------------------------------------------------

--
-- Table structure for table `chat_groups`
--

CREATE TABLE `chat_groups` (
  `id` varchar(36) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `project_id` varchar(36) NOT NULL,
  `created_by` varchar(36) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `chat_groups`
--

INSERT INTO `chat_groups` (`id`, `name`, `description`, `project_id`, `created_by`, `is_active`, `created_at`, `updated_at`) VALUES
('021b67d7-3b6b-4073-bcbf-cd95f4e180ac', 'ssssssssss', 'sssssssssss', '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 0, '2025-06-29 12:12:40', '2025-07-28 13:57:31'),
('091b266c-4b15-469f-96ef-1f6bb54ab957', 'ddddddd', 'dddddddddddddddddd', '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 1, '2025-07-28 14:28:08', '2025-07-28 14:28:08'),
('12d5fb2d-a75c-484b-b30b-4d0457595039', 'Ajmal', 'aaaaaaaaaa', '560c6c1f-5185-4ef4-9e8f-ef1315f38930', '608dc9d1-26e0-441d-8144-45f74c53a846', 0, '2025-07-28 13:45:18', '2025-07-28 13:57:04'),
('29f3ccc7-c594-4235-b41c-15e9c8ca2cbf', 'ssssssssssssssssss', 'sssssssssssssssss', '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 0, '2025-06-29 13:54:14', '2025-07-28 13:57:17'),
('2d2ab265-3678-4f3c-9ad0-142697568a4d', 'sssss', 'sssssssssssssssss', '560c6c1f-5185-4ef4-9e8f-ef1315f38930', '608dc9d1-26e0-441d-8144-45f74c53a846', 0, '2025-06-29 13:18:16', '2025-07-28 13:36:46'),
('39b73268-3371-49a1-a82b-b90f24aede5a', 'ssssssssssss', 'ssssssssssssssssssssssss', '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 0, '2025-06-29 15:45:42', '2025-07-28 13:57:08'),
('3abc3005-dae2-4809-87e6-a3712f538e2b', 'ssssssss', '', '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 0, '2025-06-29 12:12:32', '2025-07-28 13:57:34'),
('496312d9-5261-4d03-8648-988d955878c8', 'ssssss', 'ssssssssssssssssss', '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 0, '2025-06-29 13:54:03', '2025-07-28 13:57:19'),
('4fe42670-79e9-4e64-b282-cdb934a0c235', 'asaaa', 'aaaaaaaaaaaaaaaa', '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 0, '2025-06-29 13:56:37', '2025-07-28 13:57:15'),
('5cc8b012-50a4-466b-bbb1-18c9419591c5', 'Ajmal', 'sssssssssss', '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 0, '2025-06-29 12:35:00', '2025-07-28 13:57:26'),
('5e57e7d1-3ab9-49a4-8f00-1b160cc35ab8', 'ssssssssssssssss', 'sssssssssssssssssss', '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 0, '2025-06-29 12:12:47', '2025-07-28 13:57:29'),
('6266e703-d279-4dbb-9ad7-f479ca35155c', 'sssss', 'sssssssssssssssssssssss', '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 0, '2025-06-29 12:12:29', '2025-07-28 14:00:27'),
('74f8c6ef-47d3-414f-aebf-64000df45c2f', 'xxxxxxxxxxx', 'xxxxxxxxxxxxxxxxxxxxx', '560c6c1f-5185-4ef4-9e8f-ef1315f38930', '608dc9d1-26e0-441d-8144-45f74c53a846', 0, '2025-07-02 17:25:55', '2025-07-28 13:36:44'),
('7f15b5cb-1123-4996-be44-ac0d2944f9bc', 'aaaaaaaaaaaaa', 'aaaaaaaaaaaa', '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 0, '2025-07-28 14:31:28', '2025-09-10 05:46:18'),
('82ad955d-ab50-4520-8c28-727821f91d03', 'sss', 'sss', '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 0, '2025-06-29 10:15:53', '2025-07-28 13:54:26'),
('8b2ef1ea-ba4e-493c-93d0-bfe58d93a98c', 'Bug', 'ssssssssssss', '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 0, '2025-06-29 15:44:13', '2025-07-28 13:46:03'),
('8d4d495c-3593-4a5f-9ea6-631447c0e450', 'sssssssssssssss', 'sssssssssssssssssssssssss', '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 0, '2025-06-29 13:18:02', '2025-07-28 13:57:24'),
('9fb89d35-06b3-443e-95c6-e6565028ecb4', 'sssssssssss', 'sssssssssssssssssssssss', '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 0, '2025-06-29 15:45:59', '2025-07-28 13:57:06'),
('c7d37ecb-bd0c-4dac-9212-3877ee4efecc', 'ddddddd', 'hjk,lkjhgf', '560c6c1f-5185-4ef4-9e8f-ef1315f38930', '608dc9d1-26e0-441d-8144-45f74c53a846', 0, '2025-06-29 12:31:23', '2025-06-29 12:35:20'),
('cd409fbd-d7c0-4448-94b3-c0f6fd9429de', 'ssssssssssss', 'ssssssssssssssssssss', '560c6c1f-5185-4ef4-9e8f-ef1315f38930', '608dc9d1-26e0-441d-8144-45f74c53a846', 1, '2025-07-28 14:25:21', '2025-07-28 14:25:21'),
('ceab5248-fa3c-4b8f-a4c4-bc265c258863', 'aaaaaaaaaaaa', 'ssssssssssss', '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 0, '2025-06-29 12:34:48', '2025-07-28 13:57:28'),
('d21a00ad-075c-479a-a1aa-9cb2681318ff', 'sssssssssssssssssssssssssssssssssss', 'ssssssssssssssssssssssssss', '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 0, '2025-06-29 14:19:47', '2025-07-28 13:57:11'),
('d408d372-b159-4509-8050-900a04239400', 'ssss', 'ssssssssssssssssssss', '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 0, '2025-06-29 12:12:36', '2025-07-28 13:57:32'),
('f117401b-7122-411f-803c-290b57ad34c7', 'sssssssss', 'sssssssssssssss', '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 0, '2025-06-29 13:52:53', '2025-07-28 13:57:21'),
('f73e0117-5738-4267-a5a0-953df2a3794e', 'sssssssssssssssssssssssssssssssss', 'sssssssssssssssssssss', '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 0, '2025-06-29 14:05:36', '2025-07-28 13:57:13'),
('fdba56d6-de98-44fc-be44-c3e8e8b8e24f', 'sssssss', 'ssssss', '560c6c1f-5185-4ef4-9e8f-ef1315f38930', '608dc9d1-26e0-441d-8144-45f74c53a846', 0, '2025-07-28 14:04:15', '2025-09-10 05:46:19'),
('fe0c51a1-3d9f-448e-8dd9-c0cb56865b8e', 'Ajmal', 'aaaaaaaa', '560c6c1f-5185-4ef4-9e8f-ef1315f38930', '608dc9d1-26e0-441d-8144-45f74c53a846', 0, '2025-07-28 14:22:06', '2025-09-10 05:46:31'),
('ff149ca9-ea79-4972-b491-b42cb5c4bfed', 'dddddddddddddddd', 'ddddddddddddddddddddddd', '672ff940-9c60-48ef-9444-ae8903b7b0cc', '608dc9d1-26e0-441d-8144-45f74c53a846', 0, '2025-06-29 11:38:30', '2025-07-28 14:00:23');

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

--
-- Dumping data for table `chat_group_members`
--

INSERT INTO `chat_group_members` (`group_id`, `user_id`, `joined_at`, `last_read_at`, `is_muted`, `muted_until`, `show_read_receipts`) VALUES
('021b67d7-3b6b-4073-bcbf-cd95f4e180ac', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-06-29 14:18:05', NULL, 0, NULL, 1),
('021b67d7-3b6b-4073-bcbf-cd95f4e180ac', 'd84019a3-575f-403c-aa12-02482422bcfa', '2025-06-29 12:12:40', NULL, 0, NULL, 1),
('021b67d7-3b6b-4073-bcbf-cd95f4e180ac', 'd8edceb5-ca8a-446d-ad21-54d280064e69', '2025-06-29 12:12:40', NULL, 0, NULL, 1),
('091b266c-4b15-469f-96ef-1f6bb54ab957', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-28 14:28:08', NULL, 0, NULL, 1),
('091b266c-4b15-469f-96ef-1f6bb54ab957', 'd84019a3-575f-403c-aa12-02482422bcfa', '2025-07-28 14:28:08', NULL, 0, NULL, 1),
('091b266c-4b15-469f-96ef-1f6bb54ab957', 'd8edceb5-ca8a-446d-ad21-54d280064e69', '2025-07-28 14:28:08', NULL, 0, NULL, 1),
('12d5fb2d-a75c-484b-b30b-4d0457595039', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-28 13:45:18', '2025-07-28 13:57:01', 0, NULL, 1),
('12d5fb2d-a75c-484b-b30b-4d0457595039', 'd8edceb5-ca8a-446d-ad21-54d280064e69', '2025-07-28 13:45:18', NULL, 0, NULL, 1),
('29f3ccc7-c594-4235-b41c-15e9c8ca2cbf', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-06-29 13:54:14', NULL, 0, NULL, 1),
('29f3ccc7-c594-4235-b41c-15e9c8ca2cbf', 'd84019a3-575f-403c-aa12-02482422bcfa', '2025-06-29 13:54:14', NULL, 0, NULL, 1),
('29f3ccc7-c594-4235-b41c-15e9c8ca2cbf', 'd8edceb5-ca8a-446d-ad21-54d280064e69', '2025-06-29 13:54:14', NULL, 0, NULL, 1),
('39b73268-3371-49a1-a82b-b90f24aede5a', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-06-29 15:45:42', '2025-07-28 13:37:53', 0, NULL, 1),
('39b73268-3371-49a1-a82b-b90f24aede5a', 'd84019a3-575f-403c-aa12-02482422bcfa', '2025-06-29 15:45:42', NULL, 0, NULL, 1),
('39b73268-3371-49a1-a82b-b90f24aede5a', 'd8edceb5-ca8a-446d-ad21-54d280064e69', '2025-06-29 15:45:42', NULL, 0, NULL, 1),
('3abc3005-dae2-4809-87e6-a3712f538e2b', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-06-29 12:12:32', NULL, 0, NULL, 1),
('3abc3005-dae2-4809-87e6-a3712f538e2b', 'd84019a3-575f-403c-aa12-02482422bcfa', '2025-06-29 12:12:32', NULL, 0, NULL, 1),
('3abc3005-dae2-4809-87e6-a3712f538e2b', 'd8edceb5-ca8a-446d-ad21-54d280064e69', '2025-06-29 12:12:32', NULL, 0, NULL, 1),
('496312d9-5261-4d03-8648-988d955878c8', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-06-29 13:54:03', NULL, 0, NULL, 1),
('496312d9-5261-4d03-8648-988d955878c8', 'd84019a3-575f-403c-aa12-02482422bcfa', '2025-06-29 13:54:03', NULL, 0, NULL, 1),
('496312d9-5261-4d03-8648-988d955878c8', 'd8edceb5-ca8a-446d-ad21-54d280064e69', '2025-06-29 13:54:03', NULL, 0, NULL, 1),
('4fe42670-79e9-4e64-b282-cdb934a0c235', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-06-29 13:56:37', '2025-07-28 13:37:47', 0, NULL, 1),
('4fe42670-79e9-4e64-b282-cdb934a0c235', 'd84019a3-575f-403c-aa12-02482422bcfa', '2025-06-29 13:56:37', NULL, 0, NULL, 1),
('4fe42670-79e9-4e64-b282-cdb934a0c235', 'd8edceb5-ca8a-446d-ad21-54d280064e69', '2025-06-29 13:56:37', NULL, 0, NULL, 1),
('5cc8b012-50a4-466b-bbb1-18c9419591c5', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-06-29 12:35:00', '2025-07-28 13:47:02', 0, NULL, 1),
('5cc8b012-50a4-466b-bbb1-18c9419591c5', 'd84019a3-575f-403c-aa12-02482422bcfa', '2025-06-29 12:35:00', NULL, 0, NULL, 1),
('5cc8b012-50a4-466b-bbb1-18c9419591c5', 'd8edceb5-ca8a-446d-ad21-54d280064e69', '2025-06-29 12:35:00', NULL, 0, NULL, 1),
('5e57e7d1-3ab9-49a4-8f00-1b160cc35ab8', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-06-29 12:12:47', NULL, 0, NULL, 1),
('5e57e7d1-3ab9-49a4-8f00-1b160cc35ab8', 'd84019a3-575f-403c-aa12-02482422bcfa', '2025-06-29 12:12:47', NULL, 0, NULL, 1),
('5e57e7d1-3ab9-49a4-8f00-1b160cc35ab8', 'd8edceb5-ca8a-446d-ad21-54d280064e69', '2025-06-29 12:12:47', NULL, 0, NULL, 1),
('6266e703-d279-4dbb-9ad7-f479ca35155c', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-06-29 12:12:29', '2025-07-28 14:00:27', 0, NULL, 1),
('6266e703-d279-4dbb-9ad7-f479ca35155c', 'd84019a3-575f-403c-aa12-02482422bcfa', '2025-06-29 12:12:29', NULL, 0, NULL, 1),
('6266e703-d279-4dbb-9ad7-f479ca35155c', 'd8edceb5-ca8a-446d-ad21-54d280064e69', '2025-06-29 12:12:29', NULL, 0, NULL, 1),
('74f8c6ef-47d3-414f-aebf-64000df45c2f', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-02 17:25:55', '2025-07-02 19:14:39', 0, NULL, 1),
('74f8c6ef-47d3-414f-aebf-64000df45c2f', 'd8edceb5-ca8a-446d-ad21-54d280064e69', '2025-07-02 17:25:55', NULL, 0, NULL, 1),
('7f15b5cb-1123-4996-be44-ac0d2944f9bc', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-28 14:31:28', NULL, 0, NULL, 1),
('7f15b5cb-1123-4996-be44-ac0d2944f9bc', 'd84019a3-575f-403c-aa12-02482422bcfa', '2025-07-28 14:31:28', NULL, 0, NULL, 1),
('7f15b5cb-1123-4996-be44-ac0d2944f9bc', 'd8edceb5-ca8a-446d-ad21-54d280064e69', '2025-07-28 14:31:28', NULL, 0, NULL, 1),
('82ad955d-ab50-4520-8c28-727821f91d03', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-06-29 10:15:53', '2025-07-28 13:48:02', 0, NULL, 1),
('82ad955d-ab50-4520-8c28-727821f91d03', 'd84019a3-575f-403c-aa12-02482422bcfa', '2025-06-29 10:15:53', NULL, 0, NULL, 1),
('82ad955d-ab50-4520-8c28-727821f91d03', 'd8edceb5-ca8a-446d-ad21-54d280064e69', '2025-06-29 10:15:53', NULL, 0, NULL, 1),
('8b2ef1ea-ba4e-493c-93d0-bfe58d93a98c', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-06-29 15:44:13', '2025-06-29 15:44:55', 0, NULL, 1),
('8b2ef1ea-ba4e-493c-93d0-bfe58d93a98c', 'd84019a3-575f-403c-aa12-02482422bcfa', '2025-06-29 15:44:13', NULL, 0, NULL, 1),
('8b2ef1ea-ba4e-493c-93d0-bfe58d93a98c', 'd8edceb5-ca8a-446d-ad21-54d280064e69', '2025-06-29 15:44:13', NULL, 0, NULL, 1),
('8d4d495c-3593-4a5f-9ea6-631447c0e450', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-06-29 13:18:02', NULL, 0, NULL, 1),
('8d4d495c-3593-4a5f-9ea6-631447c0e450', 'd84019a3-575f-403c-aa12-02482422bcfa', '2025-06-29 13:18:02', NULL, 0, NULL, 1),
('8d4d495c-3593-4a5f-9ea6-631447c0e450', 'd8edceb5-ca8a-446d-ad21-54d280064e69', '2025-06-29 13:18:02', NULL, 0, NULL, 1),
('9fb89d35-06b3-443e-95c6-e6565028ecb4', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-06-29 15:45:59', '2025-07-28 13:54:28', 0, NULL, 1),
('9fb89d35-06b3-443e-95c6-e6565028ecb4', 'd84019a3-575f-403c-aa12-02482422bcfa', '2025-06-29 15:45:59', NULL, 0, NULL, 1),
('9fb89d35-06b3-443e-95c6-e6565028ecb4', 'd8edceb5-ca8a-446d-ad21-54d280064e69', '2025-06-29 15:45:59', NULL, 0, NULL, 1),
('cd409fbd-d7c0-4448-94b3-c0f6fd9429de', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-28 14:25:21', '2025-09-10 05:46:21', 0, NULL, 1),
('cd409fbd-d7c0-4448-94b3-c0f6fd9429de', 'd8edceb5-ca8a-446d-ad21-54d280064e69', '2025-07-28 14:25:21', NULL, 0, NULL, 1),
('ceab5248-fa3c-4b8f-a4c4-bc265c258863', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-06-29 12:34:48', NULL, 0, NULL, 1),
('ceab5248-fa3c-4b8f-a4c4-bc265c258863', 'd84019a3-575f-403c-aa12-02482422bcfa', '2025-06-29 12:34:48', NULL, 0, NULL, 1),
('ceab5248-fa3c-4b8f-a4c4-bc265c258863', 'd8edceb5-ca8a-446d-ad21-54d280064e69', '2025-06-29 12:34:48', NULL, 0, NULL, 1),
('d21a00ad-075c-479a-a1aa-9cb2681318ff', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-06-29 14:19:47', '2025-07-28 13:46:07', 0, NULL, 1),
('d21a00ad-075c-479a-a1aa-9cb2681318ff', 'd84019a3-575f-403c-aa12-02482422bcfa', '2025-06-29 14:19:47', NULL, 0, NULL, 1),
('d21a00ad-075c-479a-a1aa-9cb2681318ff', 'd8edceb5-ca8a-446d-ad21-54d280064e69', '2025-06-29 14:19:47', NULL, 0, NULL, 1),
('d408d372-b159-4509-8050-900a04239400', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-06-29 12:12:36', NULL, 0, NULL, 1),
('d408d372-b159-4509-8050-900a04239400', 'd84019a3-575f-403c-aa12-02482422bcfa', '2025-06-29 12:12:36', NULL, 0, NULL, 1),
('d408d372-b159-4509-8050-900a04239400', 'd8edceb5-ca8a-446d-ad21-54d280064e69', '2025-06-29 12:12:36', NULL, 0, NULL, 1),
('f117401b-7122-411f-803c-290b57ad34c7', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-06-29 13:52:53', NULL, 0, NULL, 1),
('f117401b-7122-411f-803c-290b57ad34c7', 'd84019a3-575f-403c-aa12-02482422bcfa', '2025-06-29 13:52:53', NULL, 0, NULL, 1),
('f117401b-7122-411f-803c-290b57ad34c7', 'd8edceb5-ca8a-446d-ad21-54d280064e69', '2025-06-29 13:52:53', NULL, 0, NULL, 1),
('f73e0117-5738-4267-a5a0-953df2a3794e', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-06-29 14:05:36', '2025-07-28 13:57:12', 0, NULL, 1),
('f73e0117-5738-4267-a5a0-953df2a3794e', 'd84019a3-575f-403c-aa12-02482422bcfa', '2025-06-29 14:05:36', NULL, 0, NULL, 1),
('f73e0117-5738-4267-a5a0-953df2a3794e', 'd8edceb5-ca8a-446d-ad21-54d280064e69', '2025-06-29 14:05:36', NULL, 0, NULL, 1),
('fdba56d6-de98-44fc-be44-c3e8e8b8e24f', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-28 14:04:15', '2025-07-28 14:52:28', 0, NULL, 1),
('fdba56d6-de98-44fc-be44-c3e8e8b8e24f', 'd8edceb5-ca8a-446d-ad21-54d280064e69', '2025-07-28 14:04:15', NULL, 0, NULL, 1),
('fe0c51a1-3d9f-448e-8dd9-c0cb56865b8e', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-28 14:22:07', NULL, 0, NULL, 1),
('fe0c51a1-3d9f-448e-8dd9-c0cb56865b8e', 'd8edceb5-ca8a-446d-ad21-54d280064e69', '2025-07-28 14:22:06', NULL, 0, NULL, 1),
('ff149ca9-ea79-4972-b491-b42cb5c4bfed', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-06-29 11:38:30', '2025-07-28 14:00:12', 0, NULL, 1),
('ff149ca9-ea79-4972-b491-b42cb5c4bfed', 'd84019a3-575f-403c-aa12-02482422bcfa', '2025-06-29 11:38:30', NULL, 0, NULL, 1),
('ff149ca9-ea79-4972-b491-b42cb5c4bfed', 'd8edceb5-ca8a-446d-ad21-54d280064e69', '2025-06-29 11:38:30', NULL, 0, NULL, 1);

-- --------------------------------------------------------

--
-- Table structure for table `chat_messages`
--

CREATE TABLE `chat_messages` (
  `id` varchar(36) NOT NULL,
  `group_id` varchar(36) NOT NULL,
  `sender_id` varchar(36) NOT NULL,
  `message_type` enum('text','voice','reply') NOT NULL DEFAULT 'text',
  `content` text DEFAULT NULL,
  `voice_file_path` varchar(500) DEFAULT NULL,
  `voice_duration` int(11) DEFAULT NULL COMMENT 'Duration in seconds',
  `reply_to_message_id` varchar(36) DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `is_pinned` tinyint(1) NOT NULL DEFAULT 0,
  `pinned_at` timestamp NULL DEFAULT NULL,
  `pinned_by` varchar(36) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `chat_messages`
--

INSERT INTO `chat_messages` (`id`, `group_id`, `sender_id`, `message_type`, `content`, `voice_file_path`, `voice_duration`, `reply_to_message_id`, `is_deleted`, `deleted_at`, `is_pinned`, `pinned_at`, `pinned_by`, `created_at`, `updated_at`) VALUES
('006194ff-36d6-4a74-b4f6-6eebf2ccd586', '12d5fb2d-a75c-484b-b30b-4d0457595039', '608dc9d1-26e0-441d-8144-45f74c53a846', 'text', 'sssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssss', NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, '2025-07-28 13:49:01', '2025-07-28 13:49:01'),
('0279a9e8-4960-4de9-8a80-ec9ff87274d3', '8b2ef1ea-ba4e-493c-93d0-bfe58d93a98c', '608dc9d1-26e0-441d-8144-45f74c53a846', 'text', 'hello', NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, '2025-06-29 15:44:30', '2025-06-29 15:44:30'),
('05a487e4-29b1-4ff4-874c-ee5473b4e8df', '82ad955d-ab50-4520-8c28-727821f91d03', '608dc9d1-26e0-441d-8144-45f74c53a846', 'text', 'ddddddd', NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, '2025-07-28 13:47:13', '2025-07-28 13:47:13'),
('0bdc07a8-1595-4cdf-8802-52014431fb13', '9fb89d35-06b3-443e-95c6-e6565028ecb4', '608dc9d1-26e0-441d-8144-45f74c53a846', 'text', 'ssssssssssssssssss', NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, '2025-06-29 15:46:34', '2025-06-29 15:46:34'),
('181de2ce-f096-48fd-9acb-8d1465413938', '5cc8b012-50a4-466b-bbb1-18c9419591c5', '608dc9d1-26e0-441d-8144-45f74c53a846', 'text', 'c', NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, '2025-06-29 12:42:03', '2025-06-29 12:42:03'),
('1d503437-24f7-4786-ab2e-ef196e516fd5', '6266e703-d279-4dbb-9ad7-f479ca35155c', '608dc9d1-26e0-441d-8144-45f74c53a846', 'voice', NULL, 'http://localhost/uploads/voice_messages/88a76ccb-1d0b-4c94-aa02-81d9ed4d4b25.webm', 2, NULL, 0, NULL, 0, NULL, NULL, '2025-07-28 13:59:32', '2025-07-28 13:59:32'),
('1d954edf-b48c-4d78-b450-9c6dcc0c9d07', '8b2ef1ea-ba4e-493c-93d0-bfe58d93a98c', '608dc9d1-26e0-441d-8144-45f74c53a846', 'voice', NULL, 'http://localhost/uploads/voice_messages/40083401-19fe-4950-8979-fbe053e5ded4.webm', 2, NULL, 0, NULL, 1, '2025-06-29 15:44:47', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-06-29 15:44:37', '2025-06-29 15:44:47'),
('2290b1d6-74da-40b5-b910-47760bcd553b', '12d5fb2d-a75c-484b-b30b-4d0457595039', '608dc9d1-26e0-441d-8144-45f74c53a846', 'voice', NULL, 'http://localhost/uploads/voice_messages/6e880542-b579-48b9-9369-3549942ec3b8.webm', 1, NULL, 0, NULL, 0, NULL, NULL, '2025-07-28 13:56:56', '2025-07-28 13:56:56'),
('3661b6eb-14d0-4e9d-a600-b6176b51e09b', '021b67d7-3b6b-4073-bcbf-cd95f4e180ac', '608dc9d1-26e0-441d-8144-45f74c53a846', 'text', 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff', NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, '2025-06-29 12:26:31', '2025-06-29 12:26:31'),
('3710c350-2575-4471-ad37-b4d93d3292aa', '5cc8b012-50a4-466b-bbb1-18c9419591c5', '608dc9d1-26e0-441d-8144-45f74c53a846', 'text', 'c', NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, '2025-06-29 12:42:02', '2025-06-29 12:42:02'),
('373ac045-92db-4f47-bf49-63af6a1f2a45', '5cc8b012-50a4-466b-bbb1-18c9419591c5', '608dc9d1-26e0-441d-8144-45f74c53a846', 'text', 'c', NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, '2025-06-29 12:41:59', '2025-06-29 12:41:59'),
('3905f570-8f55-4752-aa10-e3d7c940b0cb', '74f8c6ef-47d3-414f-aebf-64000df45c2f', '608dc9d1-26e0-441d-8144-45f74c53a846', 'voice', NULL, 'http://localhost/uploads/voice_messages/54e06e21-7c99-419e-989b-13963b08b5c1.webm', 2, NULL, 0, NULL, 0, NULL, NULL, '2025-07-02 17:26:24', '2025-07-02 17:26:24'),
('3b4d7f06-3afa-4b03-9d53-14dfb625e87d', '6266e703-d279-4dbb-9ad7-f479ca35155c', '608dc9d1-26e0-441d-8144-45f74c53a846', 'voice', NULL, 'http://localhost/uploads/voice_messages/a1a869b6-6195-4499-9732-b6fc063d6a09.webm', 8, NULL, 0, NULL, 0, NULL, NULL, '2025-07-28 14:00:07', '2025-07-28 14:00:07'),
('3ba52610-9468-4e7d-b1ea-8014b4613057', '5cc8b012-50a4-466b-bbb1-18c9419591c5', '608dc9d1-26e0-441d-8144-45f74c53a846', 'text', 'hhhhhhhhhhhhiii', NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, '2025-06-29 12:35:28', '2025-06-29 12:35:28'),
('3cf35e4c-a2af-4e3d-b142-1bab4516fc4d', '82ad955d-ab50-4520-8c28-727821f91d03', '608dc9d1-26e0-441d-8144-45f74c53a846', 'voice', NULL, 'http://localhost/uploads/voice_messages/414fc1a7-d15c-4775-85b4-3f8fda5e7969.webm', 1, NULL, 0, NULL, 0, NULL, NULL, '2025-06-29 11:47:22', '2025-06-29 11:47:22'),
('3e382ec9-e9d6-413d-8455-481d3fe2277f', 'fdba56d6-de98-44fc-be44-c3e8e8b8e24f', '608dc9d1-26e0-441d-8144-45f74c53a846', 'voice', NULL, 'http://localhost/uploads/voice_messages/6757f176-1e20-4582-b9c7-e4afddf3eb61.webm', 3, NULL, 0, NULL, 0, NULL, NULL, '2025-07-28 14:22:45', '2025-07-28 14:22:45'),
('400e575b-de56-45e9-bea2-f3e50639656a', '12d5fb2d-a75c-484b-b30b-4d0457595039', '608dc9d1-26e0-441d-8144-45f74c53a846', 'voice', NULL, 'http://localhost/uploads/voice_messages/418efdba-93b3-4cf0-9d59-f9704e4483b5.webm', 1, NULL, 0, NULL, 0, NULL, NULL, '2025-07-28 13:54:15', '2025-07-28 13:54:15'),
('40fd8378-15ec-4eb0-8672-6adc844e0888', 'c7d37ecb-bd0c-4dac-9212-3877ee4efecc', '608dc9d1-26e0-441d-8144-45f74c53a846', 'text', 'fghnjkl;', NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, '2025-06-29 12:31:44', '2025-06-29 12:31:44'),
('4484fe54-e3db-4819-9a4d-48264a34def9', '6266e703-d279-4dbb-9ad7-f479ca35155c', '608dc9d1-26e0-441d-8144-45f74c53a846', 'voice', NULL, 'http://localhost/uploads/voice_messages/e550821d-e2b8-4bdd-a749-9c636f27518f.webm', 3, NULL, 0, NULL, 0, NULL, NULL, '2025-07-28 13:57:52', '2025-07-28 13:57:52'),
('44d20bee-62c9-4356-a287-43f84382db90', '8b2ef1ea-ba4e-493c-93d0-bfe58d93a98c', '608dc9d1-26e0-441d-8144-45f74c53a846', 'text', 'hi', NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, '2025-06-29 15:44:25', '2025-06-29 15:44:25'),
('44e63a5f-092a-478c-8305-d7eef34c8574', '82ad955d-ab50-4520-8c28-727821f91d03', '608dc9d1-26e0-441d-8144-45f74c53a846', 'text', '@moajmalnk', NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, '2025-06-29 11:34:59', '2025-07-28 13:48:04'),
('4b2d57c3-062d-492a-aa74-0a1216b9339f', '6266e703-d279-4dbb-9ad7-f479ca35155c', '608dc9d1-26e0-441d-8144-45f74c53a846', 'voice', NULL, 'http://localhost/uploads/voice_messages/0eaf3b53-3427-49a7-af6e-213521b210fa.webm', 1, NULL, 0, NULL, 0, NULL, NULL, '2025-07-28 13:59:26', '2025-07-28 13:59:26'),
('4cb7aceb-10a2-45ab-a275-19ae68d34a8a', 'f73e0117-5738-4267-a5a0-953df2a3794e', '608dc9d1-26e0-441d-8144-45f74c53a846', 'voice', NULL, 'http://localhost/uploads/voice_messages/cda13af1-6651-46d1-b06f-9f17cfb30fbd.webm', 3, NULL, 0, NULL, 0, NULL, NULL, '2025-06-29 14:07:00', '2025-06-29 14:07:00'),
('5000ec8e-5b31-44d8-aca4-71f0c3521812', '39b73268-3371-49a1-a82b-b90f24aede5a', '608dc9d1-26e0-441d-8144-45f74c53a846', 'text', '@ajmalnk', NULL, NULL, NULL, 1, '2025-07-28 13:37:54', 0, NULL, NULL, '2025-06-29 15:46:20', '2025-07-28 13:38:03'),
('52416c31-e120-46ab-8950-b945a5dabc11', 'd21a00ad-075c-479a-a1aa-9cb2681318ff', '608dc9d1-26e0-441d-8144-45f74c53a846', 'text', 'sssssssssssss', NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, '2025-06-29 14:19:53', '2025-06-29 14:19:53'),
('574f0453-17e4-4788-861f-1b1ab933f58f', '5cc8b012-50a4-466b-bbb1-18c9419591c5', '608dc9d1-26e0-441d-8144-45f74c53a846', 'text', 'c', NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, '2025-06-29 12:41:58', '2025-06-29 12:41:58'),
('5d7b1772-34b8-468c-b232-b62fa4ebddd9', '5cc8b012-50a4-466b-bbb1-18c9419591c5', '608dc9d1-26e0-441d-8144-45f74c53a846', 'text', 'cc', NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, '2025-06-29 12:42:03', '2025-06-29 12:42:03'),
('5e020643-a934-4f11-a33e-3eb18ed56f28', '5cc8b012-50a4-466b-bbb1-18c9419591c5', '608dc9d1-26e0-441d-8144-45f74c53a846', 'text', 'c', NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, '2025-06-29 12:42:01', '2025-06-29 12:42:01'),
('64246511-e0ee-4914-b1c2-dfd93ee25bd2', '82ad955d-ab50-4520-8c28-727821f91d03', '608dc9d1-26e0-441d-8144-45f74c53a846', 'voice', NULL, 'http://localhost/uploads/voice_messages/fe8d5cc3-8841-4989-a8d7-1e1353d9c6a6.webm', 3, NULL, 0, NULL, 0, NULL, NULL, '2025-07-28 13:47:28', '2025-07-28 13:47:28'),
('6501aa5a-3a9b-4bef-a6e8-6e57c0255cdb', '5cc8b012-50a4-466b-bbb1-18c9419591c5', '608dc9d1-26e0-441d-8144-45f74c53a846', 'text', 'c', NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, '2025-06-29 12:41:58', '2025-06-29 12:41:58'),
('6648727a-047a-4a46-ae4a-104e281d2229', '5cc8b012-50a4-466b-bbb1-18c9419591c5', '608dc9d1-26e0-441d-8144-45f74c53a846', 'text', 'c', NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, '2025-06-29 12:42:00', '2025-06-29 12:42:00'),
('67be5983-3c57-488c-bf8a-17576d978d5e', '82ad955d-ab50-4520-8c28-727821f91d03', '608dc9d1-26e0-441d-8144-45f74c53a846', 'text', 'dddddddddd', NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, '2025-06-29 10:16:31', '2025-06-29 10:16:31'),
('688ba386-fbfc-4cdc-9607-b1ffffedb5db', '5cc8b012-50a4-466b-bbb1-18c9419591c5', '608dc9d1-26e0-441d-8144-45f74c53a846', 'text', 'c', NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, '2025-06-29 12:42:04', '2025-06-29 12:42:04'),
('68b33a65-6a76-4007-92cf-2e8dc7205c1b', '5cc8b012-50a4-466b-bbb1-18c9419591c5', '608dc9d1-26e0-441d-8144-45f74c53a846', 'text', 'c', NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, '2025-06-29 12:42:03', '2025-06-29 12:42:03'),
('6d1b32a3-87cc-42e4-a890-a65555c017a0', '6266e703-d279-4dbb-9ad7-f479ca35155c', '608dc9d1-26e0-441d-8144-45f74c53a846', 'voice', NULL, 'http://localhost/uploads/voice_messages/f5a675b2-f487-4dae-a3df-40016d1636f0.webm', 3, NULL, 1, '2025-07-28 14:00:04', 0, NULL, NULL, '2025-07-28 13:59:50', '2025-07-28 14:00:04'),
('6f16bc7a-7e9a-465c-8601-8b6df9f12539', 'fdba56d6-de98-44fc-be44-c3e8e8b8e24f', '608dc9d1-26e0-441d-8144-45f74c53a846', 'reply', 'ddd\\', NULL, NULL, '769d21c1-17ca-4cba-8eba-5ef37d5453e0', 0, NULL, 0, NULL, NULL, '2025-07-28 14:40:16', '2025-07-28 14:40:16'),
('722ad38b-b27c-4672-908f-ce55b2ec1c44', '6266e703-d279-4dbb-9ad7-f479ca35155c', '608dc9d1-26e0-441d-8144-45f74c53a846', 'voice', NULL, 'http://localhost/uploads/voice_messages/82da1986-4160-4e2e-8e58-c8eb5bb9bfaa.webm', 1, NULL, 0, NULL, 0, NULL, NULL, '2025-07-28 13:59:43', '2025-07-28 13:59:43'),
('74b7229e-52c7-445d-96a4-347b8243352c', '5cc8b012-50a4-466b-bbb1-18c9419591c5', '608dc9d1-26e0-441d-8144-45f74c53a846', 'text', 'c', NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, '2025-06-29 12:42:03', '2025-06-29 12:42:03'),
('74edb4af-6df9-4d15-80f8-bf334be89ca6', '5cc8b012-50a4-466b-bbb1-18c9419591c5', '608dc9d1-26e0-441d-8144-45f74c53a846', 'reply', 'ddddddddddddddddddddddddddddddddddddd', NULL, NULL, '3ba52610-9468-4e7d-b1ea-8014b4613057', 0, NULL, 0, NULL, NULL, '2025-06-29 12:36:01', '2025-06-29 12:36:01'),
('769d21c1-17ca-4cba-8eba-5ef37d5453e0', 'fdba56d6-de98-44fc-be44-c3e8e8b8e24f', '608dc9d1-26e0-441d-8144-45f74c53a846', 'text', 'ssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssss', NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, '2025-07-28 14:14:02', '2025-07-28 14:14:02'),
('791d5d4c-5055-4129-b147-28cc9c01a402', '6266e703-d279-4dbb-9ad7-f479ca35155c', '608dc9d1-26e0-441d-8144-45f74c53a846', 'voice', NULL, 'http://localhost/uploads/voice_messages/15891455-602e-4869-a6f2-af8947d41c1c.webm', 2, NULL, 0, NULL, 0, NULL, NULL, '2025-07-28 13:58:05', '2025-07-28 13:58:05'),
('81c6d427-f8e6-4daf-8d95-6967145f1750', '5cc8b012-50a4-466b-bbb1-18c9419591c5', '608dc9d1-26e0-441d-8144-45f74c53a846', 'text', 'ccccccccccccc\\', NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, '2025-06-29 12:41:57', '2025-06-29 12:41:57'),
('841db475-8727-4b06-8bed-51ee504a9e80', '82ad955d-ab50-4520-8c28-727821f91d03', '608dc9d1-26e0-441d-8144-45f74c53a846', 'text', 'ddddddddd', NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, '2025-06-29 11:40:34', '2025-06-29 11:40:34'),
('8434f79f-3e4b-471b-b4ba-4ce89de536c2', '74f8c6ef-47d3-414f-aebf-64000df45c2f', '608dc9d1-26e0-441d-8144-45f74c53a846', 'voice', NULL, 'http://localhost/uploads/voice_messages/92ea17b3-529f-4d91-ada8-778b4ae095b9.webm', 1, NULL, 0, NULL, 0, NULL, NULL, '2025-07-02 17:26:06', '2025-07-02 17:26:06'),
('883b2f10-fee0-4a00-a366-a1b619dfba70', '5cc8b012-50a4-466b-bbb1-18c9419591c5', '608dc9d1-26e0-441d-8144-45f74c53a846', 'text', 'c', NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, '2025-06-29 12:42:05', '2025-06-29 12:42:05'),
('9240a5fa-7de6-447d-a0a6-3ed0f5ac0f05', 'ff149ca9-ea79-4972-b491-b42cb5c4bfed', '608dc9d1-26e0-441d-8144-45f74c53a846', 'text', 'dddddddddddddddddddd', NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, '2025-06-29 11:38:45', '2025-06-29 11:38:45'),
('a2f90eeb-6664-490e-bf94-1f449c3ede1f', 'f73e0117-5738-4267-a5a0-953df2a3794e', '608dc9d1-26e0-441d-8144-45f74c53a846', 'text', 'dddddddddddddddddddddd', NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, '2025-06-29 14:06:48', '2025-06-29 14:06:48'),
('b1bb2c90-70f1-42d6-9c48-3298d7edab00', '8b2ef1ea-ba4e-493c-93d0-bfe58d93a98c', '608dc9d1-26e0-441d-8144-45f74c53a846', 'reply', 'hi', NULL, NULL, '1d954edf-b48c-4d78-b450-9c6dcc0c9d07', 0, NULL, 0, NULL, NULL, '2025-06-29 15:44:53', '2025-06-29 15:44:53'),
('b275addc-de34-4e7f-8f71-9127bf8f7dd0', '5cc8b012-50a4-466b-bbb1-18c9419591c5', '608dc9d1-26e0-441d-8144-45f74c53a846', 'text', 'c', NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, '2025-06-29 12:42:05', '2025-06-29 12:42:05'),
('b715d4e7-4d6d-4a20-80ff-594b816a4006', '5cc8b012-50a4-466b-bbb1-18c9419591c5', '608dc9d1-26e0-441d-8144-45f74c53a846', 'text', 'c', NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, '2025-06-29 12:42:03', '2025-06-29 12:42:03'),
('b8127252-55c3-46de-b337-66f95968ff34', '12d5fb2d-a75c-484b-b30b-4d0457595039', '608dc9d1-26e0-441d-8144-45f74c53a846', 'reply', 'dddddddddddddddddddd', NULL, NULL, '006194ff-36d6-4a74-b4f6-6eebf2ccd586', 0, NULL, 0, NULL, NULL, '2025-07-28 13:54:12', '2025-07-28 13:54:12'),
('b93816fb-3499-4cdb-a7a0-9560fa0d9e82', '5cc8b012-50a4-466b-bbb1-18c9419591c5', '608dc9d1-26e0-441d-8144-45f74c53a846', 'text', 'c', NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, '2025-06-29 12:42:05', '2025-06-29 12:42:05'),
('babb652c-4b3c-4844-b9ea-aba445e78579', '5cc8b012-50a4-466b-bbb1-18c9419591c5', '608dc9d1-26e0-441d-8144-45f74c53a846', 'text', 'c', NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, '2025-06-29 12:42:05', '2025-06-29 12:42:05'),
('bbb362a9-71ba-4148-87a9-9edd6a9511a5', '5cc8b012-50a4-466b-bbb1-18c9419591c5', '608dc9d1-26e0-441d-8144-45f74c53a846', 'text', 'c', NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, '2025-06-29 12:42:05', '2025-06-29 12:42:05'),
('be604332-9f2b-4b98-8501-d091912cfa94', 'fdba56d6-de98-44fc-be44-c3e8e8b8e24f', '608dc9d1-26e0-441d-8144-45f74c53a846', 'text', 'sssssssssss', NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, '2025-07-28 14:04:22', '2025-07-28 14:04:22'),
('bed98647-f1e2-4e78-a6ca-ff78feec8243', 'cd409fbd-d7c0-4448-94b3-c0f6fd9429de', '608dc9d1-26e0-441d-8144-45f74c53a846', 'voice', NULL, 'http://localhost/uploads/voice_messages/242846b8-c845-4fe7-b2ca-88478a29889b.webm', 1, NULL, 0, NULL, 0, NULL, NULL, '2025-07-28 14:25:34', '2025-07-28 14:39:40'),
('bfffc239-74d8-4eaf-a676-e1c141aec63f', '5cc8b012-50a4-466b-bbb1-18c9419591c5', '608dc9d1-26e0-441d-8144-45f74c53a846', 'text', 'cc', NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, '2025-06-29 12:42:04', '2025-06-29 12:42:04'),
('c06254e1-1d81-426e-9fe2-721c7bcd9476', '5cc8b012-50a4-466b-bbb1-18c9419591c5', '608dc9d1-26e0-441d-8144-45f74c53a846', 'text', 'c', NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, '2025-06-29 12:42:05', '2025-06-29 12:42:05'),
('c46e2cb9-47d8-4ac7-b2fa-d54e231d8cd2', '6266e703-d279-4dbb-9ad7-f479ca35155c', '608dc9d1-26e0-441d-8144-45f74c53a846', 'text', 'ssssssssssssssssssssssssssss', NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, '2025-07-28 13:54:38', '2025-07-28 13:54:38'),
('c5183ee1-88d6-4d2e-8f93-ce2c62739f75', '021b67d7-3b6b-4073-bcbf-cd95f4e180ac', '608dc9d1-26e0-441d-8144-45f74c53a846', 'text', 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff', NULL, NULL, NULL, 1, '2025-06-29 12:27:07', 0, NULL, NULL, '2025-06-29 12:26:41', '2025-06-29 12:27:07'),
('c93b9111-e9cd-4dee-8470-07676df32c82', 'fdba56d6-de98-44fc-be44-c3e8e8b8e24f', '608dc9d1-26e0-441d-8144-45f74c53a846', 'voice', NULL, 'http://localhost/uploads/voice_messages/89efd992-db65-4df6-8984-7d987921db81.webm', 2, NULL, 0, NULL, 0, NULL, NULL, '2025-07-28 14:22:31', '2025-07-28 14:22:31'),
('cb370d1f-51ba-4ef5-aa9d-82eb0095fd01', 'ff149ca9-ea79-4972-b491-b42cb5c4bfed', '608dc9d1-26e0-441d-8144-45f74c53a846', 'voice', NULL, 'http://localhost/uploads/voice_messages/9e6ce59c-90e0-463b-b2b4-21baf8024ccc.webm', 5, NULL, 0, NULL, 0, NULL, NULL, '2025-06-29 12:00:46', '2025-06-29 12:00:46'),
('ce8a90ce-f801-489b-a95b-51b8befaf200', '9fb89d35-06b3-443e-95c6-e6565028ecb4', '608dc9d1-26e0-441d-8144-45f74c53a846', 'text', 'aaaaaaaaaaaaaa', NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, '2025-07-28 13:36:55', '2025-07-28 13:36:55'),
('d1e03ad7-4e86-47bb-aac5-f4f0efcacebc', '12d5fb2d-a75c-484b-b30b-4d0457595039', '608dc9d1-26e0-441d-8144-45f74c53a846', 'voice', NULL, 'http://localhost/uploads/voice_messages/5a17e1bf-15f7-4e22-a54f-fe73dbb396ed.webm', 2, NULL, 0, NULL, 0, NULL, NULL, '2025-07-28 13:56:49', '2025-07-28 13:56:49'),
('d1eaad07-dade-4d83-b669-88685d8f2de5', '5cc8b012-50a4-466b-bbb1-18c9419591c5', '608dc9d1-26e0-441d-8144-45f74c53a846', 'text', 'c', NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, '2025-06-29 12:42:02', '2025-06-29 12:42:02'),
('d634b113-cca4-4156-b214-8f87d07bafec', '82ad955d-ab50-4520-8c28-727821f91d03', '608dc9d1-26e0-441d-8144-45f74c53a846', 'text', '@moajmalnk', NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, '2025-06-29 11:34:45', '2025-06-29 11:34:45'),
('d6b1948f-0754-4e18-8ef3-c94b31f93de5', '9fb89d35-06b3-443e-95c6-e6565028ecb4', '608dc9d1-26e0-441d-8144-45f74c53a846', 'voice', NULL, 'http://localhost/uploads/voice_messages/fee1f465-9847-4687-9a0e-07b01dba7b8a.webm', 2, NULL, 0, NULL, 0, NULL, NULL, '2025-07-28 13:37:07', '2025-07-28 13:37:07'),
('e1468d91-f491-481c-9595-c7d0b475da4f', '4fe42670-79e9-4e64-b282-cdb934a0c235', '608dc9d1-26e0-441d-8144-45f74c53a846', 'text', 'cccccccc', NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, '2025-06-29 13:56:49', '2025-06-29 13:56:49'),
('e21e1d99-692e-4ffe-962b-82eba9d6e4cd', '5cc8b012-50a4-466b-bbb1-18c9419591c5', '608dc9d1-26e0-441d-8144-45f74c53a846', 'text', 'c', NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, '2025-06-29 12:42:02', '2025-06-29 12:42:02'),
('e2a02c33-79e5-497d-87d2-f17759c55430', '6266e703-d279-4dbb-9ad7-f479ca35155c', '608dc9d1-26e0-441d-8144-45f74c53a846', 'voice', NULL, 'http://localhost/uploads/voice_messages/d254613f-7609-489e-9113-94e45c2e53df.webm', 1, NULL, 0, NULL, 0, NULL, NULL, '2025-07-28 13:57:57', '2025-07-28 13:57:57'),
('e5878d07-4906-4903-80bf-37091bf63cb1', 'fdba56d6-de98-44fc-be44-c3e8e8b8e24f', '608dc9d1-26e0-441d-8144-45f74c53a846', 'voice', NULL, 'http://localhost/uploads/voice_messages/9928a341-b266-4a5e-b9b2-9627ccda5440.webm', 1, NULL, 0, NULL, 0, NULL, NULL, '2025-07-28 14:04:26', '2025-07-28 14:04:26'),
('e8f3e6ca-feaa-4ee4-a775-f28fad8664b3', '5cc8b012-50a4-466b-bbb1-18c9419591c5', '608dc9d1-26e0-441d-8144-45f74c53a846', 'text', 'c', NULL, NULL, NULL, 0, NULL, 0, NULL, NULL, '2025-06-29 12:42:04', '2025-06-29 12:42:04'),
('e91e6c08-eeb4-4503-a981-ec145d31aab9', '82ad955d-ab50-4520-8c28-727821f91d03', '608dc9d1-26e0-441d-8144-45f74c53a846', 'voice', NULL, 'http://localhost/uploads/voice_messages/9a34418a-12a9-44a6-b0fc-93cc29c3934f.webm', 1, NULL, 0, NULL, 0, NULL, NULL, '2025-06-29 11:53:30', '2025-06-29 11:53:30'),
('eb7b8c89-51b3-456a-b8db-23acc3504a5e', '9fb89d35-06b3-443e-95c6-e6565028ecb4', '608dc9d1-26e0-441d-8144-45f74c53a846', 'voice', NULL, 'http://localhost/uploads/voice_messages/12710caf-3c0d-4b28-8e3c-65bffc89cb1a.webm', 1, NULL, 0, NULL, 0, NULL, NULL, '2025-07-28 13:36:58', '2025-07-28 13:36:58'),
('eefd517b-d7dc-4726-9fb0-6f4c750bf397', '82ad955d-ab50-4520-8c28-727821f91d03', '608dc9d1-26e0-441d-8144-45f74c53a846', 'voice', NULL, 'http://localhost/uploads/voice_messages/b59c1348-49fd-464b-a927-208b70a234f9.webm', 4, NULL, 0, NULL, 0, NULL, NULL, '2025-06-29 11:47:53', '2025-06-29 11:47:53'),
('fb9bd5c8-dd9c-4546-bc13-46edf4384175', '82ad955d-ab50-4520-8c28-727821f91d03', '608dc9d1-26e0-441d-8144-45f74c53a846', 'voice', NULL, 'http://localhost/uploads/voice_messages/c3bbe946-cf71-4b83-9545-e5476e557694.webm', 3, NULL, 0, NULL, 0, NULL, NULL, '2025-06-29 11:53:49', '2025-07-28 13:47:57');

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
-- Table structure for table `meetings`
--

CREATE TABLE `meetings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `meeting_code` varchar(16) NOT NULL,
  `title` varchar(255) NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
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
  `sender_id` bigint(20) UNSIGNED DEFAULT NULL,
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
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
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
-- Table structure for table `message_mentions`
--

CREATE TABLE `message_mentions` (
  `id` varchar(36) NOT NULL,
  `message_id` varchar(36) NOT NULL,
  `mentioned_user_id` varchar(36) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `message_mentions`
--

INSERT INTO `message_mentions` (`id`, `message_id`, `mentioned_user_id`, `created_at`) VALUES
('e02b6b04-5fdf-40d0-8a31-712847997b72', '44e63a5f-092a-478c-8305-d7eef34c8574', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-06-29 11:34:59'),
('e1dfd665-b285-4089-afb9-e75df62a2b50', 'd634b113-cca4-4156-b214-8f87d07bafec', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-06-29 11:34:45');

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

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `type`, `title`, `message`, `bug_id`, `bug_title`, `status`, `created_by`, `created_at`) VALUES
(1, 'new_bug', 'New Bug Reported', 'A new bug has been reported: Test Bug - Broadcast Notification', 0, 'Test Bug - Broadcast Notification', NULL, 'Test User', '2025-06-03 12:46:40'),
(2, 'new_bug', 'Test Notification from Postman', 'This is a test notification sent via Postman', 123, 'Test Bug from Postman', NULL, 'Postman Tester', '2025-06-03 12:53:04'),
(3, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Course name not showing', 224, 'Course name not showing', 'fixed', 'Bug Ricer User', '2025-06-04 10:48:31'),
(4, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Responsive issue', 546, 'Responsive issue', 'fixed', 'Bug Ricer User', '2025-06-04 13:20:53'),
(5, 'new_bug', 'New Bug Reported', 'A new bug has been reported: Test Bug - Broadcast Notification', 0, 'Test Bug - Broadcast Notification', NULL, 'Test User', '2025-06-04 13:23:45'),
(6, 'new_bug', 'New Bug Reported', 'A new bug has been reported: Test Bug - Broadcast Notification', 0, 'Test Bug - Broadcast Notification', NULL, 'Test User', '2025-06-04 13:23:47'),
(7, 'new_bug', 'New Bug Reported', 'A new bug has been reported: Test Bug - Broadcast Notification', 0, 'Test Bug - Broadcast Notification', NULL, 'Test User', '2025-06-04 13:24:33'),
(8, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Responsive issue', 546, 'Responsive issue', 'fixed', 'Bug Ricer User', '2025-06-04 13:30:17'),
(9, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Responsive issue', 546, 'Responsive issue', 'fixed', 'Bug Ricer User', '2025-06-04 17:50:44'),
(10, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Package issue', 0, 'Package issue', 'fixed', 'Bug Ricer User', '2025-06-05 08:30:03'),
(11, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Unexpected Isuue', 0, 'Unexpected Isuue', 'fixed', 'Bug Ricer User', '2025-06-05 08:33:11'),
(12, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Course name not showing', 224, 'Course name not showing', 'fixed', 'Bug Ricer User', '2025-06-05 08:34:17'),
(13, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Add new ', 0, 'Add new ', 'fixed', 'Bug Ricer User', '2025-06-09 04:10:25'),
(14, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Responsive issue', 546, 'Responsive issue', 'fixed', 'Bug Ricer User', '2025-06-09 04:21:47'),
(15, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Assessment', 0, 'Assessment', 'fixed', 'Bug Ricer User', '2025-06-14 10:41:03'),
(16, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Teacher Card', 0, 'Teacher Card', 'fixed', 'Bug Ricer User', '2025-06-14 10:41:59'),
(17, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Generate Link', 0, 'Generate Link', 'fixed', 'Bug Ricer User', '2025-06-14 10:43:16'),
(18, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Responsive issue', 387, 'Responsive issue', 'fixed', 'Bug Ricer User', '2025-06-14 10:43:33'),
(19, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Responsive', 0, 'Responsive', 'fixed', 'Bug Ricer User', '2025-06-14 10:47:03'),
(20, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Responsive issue', 0, 'Responsive issue', 'fixed', 'Bug Ricer User', '2025-06-14 10:47:54'),
(21, 'new_bug', 'New Bug Reported', 'A new bug has been reported: Test Bug - Broadcast Notification', 0, 'Test Bug - Broadcast Notification', NULL, 'Test User', '2025-06-17 05:41:53'),
(22, 'new_bug', 'New Bug Reported', 'A new bug has been reported: Test Bug - Broadcast Notification', 0, 'Test Bug - Broadcast Notification', NULL, 'Test User', '2025-06-19 07:19:47'),
(23, 'status_change', 'Bug Status Updated', 'Bug has been fixed: DNS resolution failure', 0, 'DNS resolution failure', 'fixed', 'Bug Ricer User', '2025-06-19 15:14:46'),
(24, 'status_change', 'Bug Status Updated', 'Bug has been fixed: teachers ', 0, 'teachers ', 'fixed', 'Bug Ricer User', '2025-06-25 19:57:58'),
(25, 'status_change', 'Bug Status Updated', 'Bug has been fixed: responsive', 60, 'responsive', 'fixed', 'Bug Ricer User', '2025-06-25 20:18:28'),
(26, 'status_change', 'Bug Status Updated', 'Bug has been fixed: button color', 0, 'button color', 'fixed', 'Bug Ricer User', '2025-06-25 20:34:58'),
(27, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Support ticket update', 0, 'Support ticket update', 'fixed', 'Bug Ricer User', '2025-06-25 20:36:11'),
(28, 'status_change', 'Bug Status Updated', 'Bug has been fixed: advisor is not displaying', 10437484, 'advisor is not displaying', 'fixed', 'Bug Ricer User', '2025-06-25 20:38:08'),
(29, 'status_change', 'Bug Status Updated', 'Bug has been fixed: wrrong title', 0, 'wrrong title', 'fixed', 'Bug Ricer User', '2025-06-26 07:10:37'),
(30, 'status_change', 'Bug Status Updated', 'Bug has been fixed: click back', 506789, 'click back', 'fixed', 'Bug Ricer User', '2025-06-26 07:16:47'),
(31, 'new_bug', 'New Bug Reported', 'A new bug has been reported: Test Bug - Broadcast Notification', 0, 'Test Bug - Broadcast Notification', NULL, 'Test User', '2025-06-26 08:30:49'),
(32, 'new_bug', 'New Bug Reported', 'A new bug has been reported: Login not working', 123, 'Login not working', NULL, 'admin', '2025-06-27 05:56:51'),
(33, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Development', 276583, 'Development', 'fixed', 'Bug Ricer', '2025-06-27 06:26:17'),
(34, 'status_change', 'Bug Status Updated', 'Bug has been fixed: ssssss', 0, 'ssssss', 'fixed', 'Bug Ricer User', '2025-06-27 06:33:28'),
(35, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Development', 276583, 'Development', 'fixed', 'Bug Ricer User', '2025-06-27 06:33:44'),
(36, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Development', 276583, 'Development', 'fixed', 'Bug Ricer User', '2025-06-27 06:34:14'),
(37, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Development', 276583, 'Development', 'fixed', 'Bug Ricer User', '2025-06-27 06:36:12'),
(38, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Development', 276583, 'Development', 'fixed', 'Bug Ricer User', '2025-06-27 06:37:51'),
(39, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Development', 276583, 'Development', 'fixed', 'Bug Ricer User', '2025-06-27 07:18:01'),
(40, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Development', 0, 'Development', 'fixed', 'Bug Ricer User', '2025-06-27 11:08:48'),
(41, 'status_change', 'Bug Status Updated', 'Bug has been fixed: demo', 817008, 'demo', 'fixed', 'Bug Ricer User', '2025-06-28 06:02:47'),
(42, 'status_change', 'Bug Status Updated', 'Bug has been fixed: ssssss', 66, 'ssssss', 'fixed', 'Bug Ricer User', '2025-06-28 06:04:46'),
(43, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Development', 5, 'Development', 'fixed', 'Bug Ricer User', '2025-06-28 06:27:08'),
(44, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Add Class Taken Amount', 0, 'Add Class Taken Amount', 'fixed', 'Bug Ricer User', '2025-06-28 08:41:27'),
(45, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Development', 9, 'Development', 'fixed', 'Bug Ricer User', '2025-06-28 08:41:34'),
(46, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Development', 0, 'Development', 'fixed', 'Bug Ricer User', '2025-06-28 08:41:49'),
(47, 'status_change', 'Bug Status Updated', 'Bug has been fixed: ssssssss', 7, 'ssssssss', 'fixed', 'Bug Ricer User', '2025-06-28 08:48:47'),
(48, 'status_change', 'Bug Status Updated', 'Bug has been fixed: ssss', 0, 'ssss', 'fixed', 'Bug Ricer User', '2025-06-28 08:49:29'),
(49, 'status_change', 'Bug Status Updated', 'Bug has been fixed: dddddd', 9351, 'dddddd', 'fixed', 'Bug Ricer User', '2025-06-28 08:57:11'),
(50, 'status_change', 'Bug Status Updated', 'Bug has been fixed: asdfsdf', 0, 'asdfsdf', 'fixed', 'Bug Ricer User', '2025-06-28 08:57:28'),
(51, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Mohammed Ajmal Nk', 0, 'Mohammed Ajmal Nk', 'fixed', 'Bug Ricer User', '2025-06-28 10:00:28'),
(52, 'status_change', 'Bug Status Updated', 'Bug has been fixed: sdas', 0, 'sdas', 'fixed', 'Bug Ricer User', '2025-06-28 10:00:31'),
(53, 'status_change', 'Bug Status Updated', 'Bug has been fixed: asda', 0, 'asda', 'fixed', 'Bug Ricer User', '2025-06-28 10:00:35'),
(54, 'new_bug', 'New Bug Reported', 'A new bug has been reported: Test Bug - Broadcast Notification', 0, 'Test Bug - Broadcast Notification', NULL, 'Test User', '2025-07-02 18:26:36'),
(55, 'status_change', 'Bug Status Updated', 'Bug has been fixed: ssssssssss', 0, 'ssssssssss', 'fixed', 'Bug Ricer', '2025-07-02 18:47:40'),
(56, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Payment invoice ', 0, 'Payment invoice ', 'fixed', 'Bug Ricer User', '2025-07-24 13:34:19'),
(57, 'status_change', 'Bug Status Updated', 'Bug has been fixed: time not ready', 0, 'time not ready', 'fixed', 'Bug Ricer User', '2025-07-24 13:34:26'),
(58, 'status_change', 'Bug Status Updated', 'Bug has been fixed: display', 0, 'display', 'fixed', 'Bug Ricer User', '2025-07-24 13:34:30'),
(59, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Pre KG', 0, 'Pre KG', 'fixed', 'Bug Ricer User', '2025-07-24 13:34:35'),
(60, 'status_change', 'Bug Status Updated', 'Bug has been fixed: asdas', 372460, 'asdas', 'fixed', 'Bug Ricer User', '2025-07-24 13:34:40'),
(61, 'status_change', 'Bug Status Updated', 'Bug has been fixed: sdfasd', 173000, 'sdfasd', 'fixed', 'Bug Ricer User', '2025-07-24 13:34:44'),
(62, 'status_change', 'Bug Status Updated', 'Bug has been fixed: zfxgfzsdf', 3354, 'zfxgfzsdf', 'fixed', 'Bug Ricer User', '2025-07-24 13:34:48'),
(63, 'status_change', 'Bug Status Updated', 'Bug has been fixed: ASDasd', 90, 'ASDasd', 'fixed', 'Bug Ricer User', '2025-07-24 13:34:51'),
(64, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Star of month not responsive in tablet view', 9, 'Star of month not responsive in tablet view', 'fixed', 'Bug Ricer User', '2025-08-22 17:22:48'),
(65, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Responsive issue', 83, 'Responsive issue', 'fixed', 'Bug Ricer User', '2025-08-22 17:22:52'),
(66, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Session', 0, 'Session', 'fixed', 'Bug Ricer User', '2025-08-22 17:23:03'),
(67, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Search issue', 0, 'Search issue', 'fixed', 'Bug Ricer User', '2025-08-22 17:23:06'),
(68, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Editing not updating', 1, 'Editing not updating', 'fixed', 'Bug Ricer User', '2025-08-22 17:23:08'),
(69, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Option Remove', 94, 'Option Remove', 'fixed', 'Bug Ricer User', '2025-08-22 17:23:11'),
(70, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Position Changing ', 0, 'Position Changing ', 'fixed', 'Bug Ricer User', '2025-08-22 17:23:13'),
(71, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Banner Ads', 8, 'Banner Ads', 'fixed', 'Bug Ricer User', '2025-08-22 17:23:16'),
(72, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Search issue', 556, 'Search issue', 'fixed', 'Bug Ricer User', '2025-08-22 17:23:24'),
(73, 'status_change', 'Bug Status Updated', 'Bug has been fixed: ticket not showen', 35214942, 'ticket not showen', 'fixed', 'Bug Ricer User', '2025-08-22 17:23:27'),
(74, 'status_change', 'Bug Status Updated', 'Bug has been fixed: sample', 0, 'sample', 'fixed', 'Bug Ricer User', '2025-08-25 09:21:44'),
(75, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Sayyid Suhail ', 168, 'Sayyid Suhail ', 'fixed', 'Bug Ricer User', '2025-09-09 09:23:21'),
(76, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Sayyid Suhail ', 0, 'Sayyid Suhail ', 'fixed', 'Bug Ricer', '2025-09-10 01:38:55'),
(77, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Mobile App Design', 1, 'Mobile App Design', 'fixed', 'Bug Ricer', '2025-09-10 02:44:39'),
(78, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Mobile App Design', 1, 'Mobile App Design', 'fixed', 'Bug Ricer', '2025-09-10 02:46:09'),
(79, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Mobile App Design', 1, 'Mobile App Design', 'fixed', 'Bug Ricer', '2025-09-10 02:54:53'),
(80, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Mobile App Design', 1, 'Mobile App Design', 'fixed', 'Bug Ricer', '2025-09-10 03:10:11'),
(81, 'new_bug', 'New Bug Reported', 'A new bug has been reported: Test Bug - Broadcast Notification', 0, 'Test Bug - Broadcast Notification', NULL, 'Test User', '2025-09-10 06:06:49'),
(82, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Mobile App Design', 0, 'Mobile App Design', 'fixed', 'Bug Ricer User', '2025-09-10 08:27:57'),
(83, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Sayyid Suhail ', 168, 'Sayyid Suhail ', 'fixed', 'Bug Ricer', '2025-09-10 08:28:27'),
(84, 'status_change', 'Bug Status Updated', 'Bug has been fixed: efefe', 0, 'efefe', 'fixed', 'Bug Ricer', '2025-09-10 13:49:06'),
(85, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Mobile App Design', 27, 'Mobile App Design', 'fixed', 'Bug Ricer', '2025-09-10 13:53:32'),
(86, 'status_change', 'Bug Status Updated', 'Bug has been fixed: sssssssssssssssssssssss', 0, 'sssssssssssssssssssssss', 'fixed', 'Bug Ricer User', '2025-09-10 13:56:57'),
(87, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Mobile App Design', 15, 'Mobile App Design', 'fixed', 'Bug Ricer', '2025-09-10 14:08:00'),
(88, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Alice Johnsona', 0, 'Alice Johnsona', 'fixed', 'Bug Ricer', '2025-09-10 14:09:42'),
(89, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Mobile App Design', 62822, 'Mobile App Design', 'fixed', 'Bug Ricer User', '2025-09-10 14:32:45'),
(90, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Mobile App Design', 0, 'Mobile App Design', 'fixed', 'Bug Ricer User', '2025-09-10 14:38:53'),
(91, 'status_change', 'Bug Status Updated', 'Bug has been fixed: dddd', 0, 'dddd', 'fixed', 'Bug Ricer User', '2025-09-10 14:39:17'),
(92, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Mobile App Design', 0, 'Mobile App Design', 'fixed', 'Bug Ricer User', '2025-09-10 14:42:17'),
(93, 'status_change', 'Bug Status Updated', 'Bug has been fixed: dd', 14, 'dd', 'fixed', 'Bug Ricer', '2025-09-10 14:53:49'),
(94, 'status_change', 'Bug Status Updated', 'Bug has been fixed: dddd', 73, 'dddd', 'fixed', 'Bug Ricer', '2025-09-10 14:54:14'),
(95, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Mobile App Design', 389, 'Mobile App Design', 'fixed', 'Bug Ricer', '2025-09-10 15:14:47'),
(96, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Mobile App Design', 8, 'Mobile App Design', 'fixed', 'Bug Ricer User', '2025-09-10 15:15:49'),
(97, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Mobile App Design', 679, 'Mobile App Design', 'fixed', 'Bug Ricer User', '2025-09-10 15:16:25'),
(98, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Mobile App Design', 0, 'Mobile App Design', 'fixed', 'Bug Ricer', '2025-09-10 15:16:32'),
(99, 'status_change', 'Bug Status Updated', 'Bug has been fixed: Development', 737, 'Development', 'fixed', 'Bug Ricer', '2025-09-10 15:17:05'),
(100, 'status_change', 'Bug Status Updated', 'Bug has been fixed: xxxxxxxxxxxxxxxx', 0, 'xxxxxxxxxxxxxxxx', 'fixed', 'Bug Ricer User', '2025-09-10 15:19:38');

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `password_resets`
--

INSERT INTO `password_resets` (`id`, `user_id`, `email`, `token`, `expires_at`, `used_at`, `created_at`, `updated_at`) VALUES
(1, '608dc9d1-26e0-441d-8144-45f74c53a846', 'moajmalnk@gmail.com', 'a24ab74819ca4ea5e19e67435dae26e523222b621121b7918742cef2bf6a4f52', '2025-09-11 05:24:30', NULL, '2025-09-11 02:24:30', '2025-09-11 02:24:30'),
(2, '608dc9d1-26e0-441d-8144-45f74c53a846', 'moajmalnk@gmail.com', '181fb19be8726227b7df1e65d946a2233c85c144add178b46f630999f77e99a9', '2025-09-11 15:12:56', NULL, '2025-09-11 12:12:56', '2025-09-11 12:12:56');

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

--
-- Dumping data for table `projects`
--

INSERT INTO `projects` (`id`, `name`, `description`, `status`, `created_by`, `created_at`, `updated_at`) VALUES
('560c6c1f-5185-4ef4-9e8f-ef1315f38930', 'MedocSuite', 'MedocSuite', 'completed', NULL, '2025-06-04 13:25:06', '2025-09-09 15:28:09'),
('672ff940-9c60-48ef-9444-ae8903b7b0cc', 'Albedo Operations', 'Empowering Educators and Students with a Digital Learning Space\nalbedoedu.com', 'active', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-05-05 05:48:21', '2025-06-02 09:00:10'),
('910a5137-3001-4560-b001-9c8a7f9678a7', 'cccc', 'cc', 'active', '799b8406-7a20-4b8c-b7a9-c467f0c6268e', '2025-09-10 14:50:00', '2025-09-10 14:50:00');

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
  `created_at` timestamp NULL DEFAULT current_timestamp() COMMENT 'When the activity occurred'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `project_activities`
--

INSERT INTO `project_activities` (`id`, `user_id`, `project_id`, `activity_type`, `description`, `related_id`, `metadata`, `created_at`) VALUES
(1, '608dc9d1-26e0-441d-8144-45f74c53a846', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'project_created', 'Project was created', NULL, '{\"initial_setup\": true}', '2025-06-04 08:23:10'),
(2, '608dc9d1-26e0-441d-8144-45f74c53a846', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'member_added', 'Initial project setup completed', NULL, '{\"role\": \"admin\", \"setup\": \"automated\"}', '2025-06-04 08:23:10'),
(3, '608dc9d1-26e0-441d-8144-45f74c53a846', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'bug_reported', 'Sample bug was reported for testing', NULL, '{\"severity\": \"medium\", \"sample\": true}', '2025-06-04 08:23:10'),
(8, '6717efed-5466-4204-93a4-3287978f9fff', '560c6c1f-5185-4ef4-9e8f-ef1315f38930', 'member_added', 'added rishan to the project', NULL, '{\"member_username\":\"rishan\",\"role\":\"tester\"}', '2025-06-04 13:25:14'),
(9, '6717efed-5466-4204-93a4-3287978f9fff', '560c6c1f-5185-4ef4-9e8f-ef1315f38930', 'member_added', 'added rahoof to the project', NULL, '{\"member_username\":\"rahoof\",\"role\":\"developer\"}', '2025-06-04 13:25:22'),
(11, '608dc9d1-26e0-441d-8144-45f74c53a846', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'member_added', 'added fahis to the project', NULL, '{\"member_username\":\"fahis\",\"role\":\"developer\"}', '2025-06-19 07:19:21'),
(12, '608dc9d1-26e0-441d-8144-45f74c53a846', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'member_removed', 'removed fahis from the project', NULL, '{\"member_username\":\"fahis\",\"role\":\"developer\"}', '2025-06-19 07:19:24'),
(15, '608dc9d1-26e0-441d-8144-45f74c53a846', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'member_added', 'added adnaj to the project', NULL, '{\"member_username\":\"adnaj\",\"role\":\"developer\"}', '2025-06-25 12:19:09'),
(16, '608dc9d1-26e0-441d-8144-45f74c53a846', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'member_removed', 'removed ajmalnk from the project', NULL, '{\"member_username\":\"ajmalnk\",\"role\":\"developer\"}', '2025-06-26 13:01:37'),
(17, '608dc9d1-26e0-441d-8144-45f74c53a846', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'member_added', 'added aaaaaaaa to the project', NULL, '{\"member_username\":\"aaaaaaaa\",\"role\":\"tester\"}', '2025-06-27 03:08:29'),
(18, '608dc9d1-26e0-441d-8144-45f74c53a846', '560c6c1f-5185-4ef4-9e8f-ef1315f38930', 'member_added', 'added ssssssss to the project', NULL, '{\"member_username\":\"ssssssss\",\"role\":\"tester\"}', '2025-08-23 13:37:58'),
(20, '608dc9d1-26e0-441d-8144-45f74c53a846', 'f0282cd2-6384-40bf-9090-2f1ddc64ba1b', 'project_updated', 'updated project details', NULL, '{\"updated_fields\":[\"name\"]}', '2025-09-09 07:44:42'),
(22, '608dc9d1-26e0-441d-8144-45f74c53a846', '560c6c1f-5185-4ef4-9e8f-ef1315f38930', 'project_updated', 'updated project details', NULL, '{\"updated_fields\":[\"status\"]}', '2025-09-09 15:28:09'),
(24, '608dc9d1-26e0-441d-8144-45f74c53a846', '560c6c1f-5185-4ef4-9e8f-ef1315f38930', 'member_added', 'added essdd to the project', NULL, '{\"member_username\":\"essdd\",\"role\":\"developer\"}', '2025-09-10 07:11:23'),
(25, '608dc9d1-26e0-441d-8144-45f74c53a846', '560c6c1f-5185-4ef4-9e8f-ef1315f38930', 'member_removed', 'removed essdd from the project', NULL, '{\"member_username\":\"essdd\",\"role\":\"developer\"}', '2025-09-10 07:44:59'),
(33, '608dc9d1-26e0-441d-8144-45f74c53a846', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'member_added', 'added tester to the project', NULL, '{\"member_username\":\"tester\",\"role\":\"tester\"}', '2025-09-10 13:13:54'),
(34, '608dc9d1-26e0-441d-8144-45f74c53a846', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'member_added', 'added developer to the project', NULL, '{\"member_username\":\"developer\",\"role\":\"developer\"}', '2025-09-10 13:13:54'),
(35, '608dc9d1-26e0-441d-8144-45f74c53a846', '560c6c1f-5185-4ef4-9e8f-ef1315f38930', 'member_added', 'added tester to the project', NULL, '{\"member_username\":\"tester\",\"role\":\"tester\"}', '2025-09-10 13:14:15'),
(36, '608dc9d1-26e0-441d-8144-45f74c53a846', '560c6c1f-5185-4ef4-9e8f-ef1315f38930', 'member_added', 'added developer to the project', NULL, '{\"member_username\":\"developer\",\"role\":\"developer\"}', '2025-09-10 13:14:15'),
(37, '608dc9d1-26e0-441d-8144-45f74c53a846', '560c6c1f-5185-4ef4-9e8f-ef1315f38930', 'member_added', 'added developer to the project', NULL, '{\"member_username\":\"developer\",\"role\":\"developer\"}', '2025-09-10 14:40:50');

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

--
-- Dumping data for table `project_members`
--

INSERT INTO `project_members` (`project_id`, `user_id`, `role`, `joined_at`) VALUES
('560c6c1f-5185-4ef4-9e8f-ef1315f38930', '244ab83a-5049-4e61-8fb9-67e67281a3c2', 'developer', '2025-09-10 13:14:15'),
('560c6c1f-5185-4ef4-9e8f-ef1315f38930', 'd84019a3-575f-403c-aa12-02482422bcfa', 'developer', '2025-09-10 14:40:50'),
('560c6c1f-5185-4ef4-9e8f-ef1315f38930', 'd8edceb5-ca8a-446d-ad21-54d280064e69', 'tester', '2025-06-29 13:50:10'),
('560c6c1f-5185-4ef4-9e8f-ef1315f38930', 'b6b1ab7a-f3c0-4b33-b953-ef4428a2c318', 'tester', '2025-08-23 13:37:58'),
('560c6c1f-5185-4ef4-9e8f-ef1315f38930', 'c18ce191-e34b-4ca7-b69b-6a78488d3de5', 'tester', '2025-09-10 13:14:15'),
('672ff940-9c60-48ef-9444-ae8903b7b0cc', '244ab83a-5049-4e61-8fb9-67e67281a3c2', 'developer', '2025-09-10 13:13:54'),
('672ff940-9c60-48ef-9444-ae8903b7b0cc', 'd84019a3-575f-403c-aa12-02482422bcfa', 'tester', '2025-06-03 09:58:02'),
('672ff940-9c60-48ef-9444-ae8903b7b0cc', 'd8edceb5-ca8a-446d-ad21-54d280064e69', 'tester', '2025-06-27 06:38:29'),
('672ff940-9c60-48ef-9444-ae8903b7b0cc', 'c18ce191-e34b-4ca7-b69b-6a78488d3de5', 'tester', '2025-09-10 13:13:54');

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

--
-- Dumping data for table `updates`
--

INSERT INTO `updates` (`id`, `project_id`, `title`, `type`, `description`, `created_by`, `created_at`, `updated_at`, `status`) VALUES
('02bb19ce-8dfa-4771-af31-eee45fd30493', '560c6c1f-5185-4ef4-9e8f-ef1315f38930', 'xxxxxxxxx', 'updation', 'xxxxxxxxxxxxxxxxxxxx', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-04 13:09:04', NULL, 'pending'),
('03047fa4-40af-4b97-881a-18ec935a425a', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'xss', 'updation', 'sss', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-09-10 13:50:23', NULL, 'pending'),
('09eb1c8d-6cb4-42b7-81a9-3652530fcb54', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'dfghj', 'updation', 'n', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-09-10 13:42:42', NULL, 'pending'),
('09fd9b10-1bc8-43c9-917b-5ad44a92061d', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'sss', 'feature', 'sss', 'd84019a3-575f-403c-aa12-02482422bcfa', '2025-09-10 14:47:02', NULL, 'pending'),
('0cb78268-0481-4334-8230-faf95d2568b5', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'ssssssss', 'maintenance', 'sssssssss', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-02 17:52:59', NULL, 'pending'),
('0f36253d-1431-4f81-80e0-6bcdfd0b451e', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'xxx', 'feature', 'xx', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-09-10 14:38:14', NULL, 'pending'),
('18e36f85-338c-4aac-af5c-16a72cc5f48e', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'new sample feature', 'feature', 'sdsdsdsds', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-09-10 13:21:31', NULL, 'pending'),
('1dcc9de5-25e4-4476-847d-ccc4568182c5', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'xx', 'updation', 'ccxcxc', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-09-10 13:39:19', NULL, 'pending'),
('25dedb45-18b4-409a-b9f1-ecb83a54914c', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'eeee', 'updation', 'eee', 'd84019a3-575f-403c-aa12-02482422bcfa', '2025-09-10 13:47:11', NULL, 'pending'),
('285ffb32-3b50-4119-be07-6fde93d389cc', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'qqqqqqqqqqq', 'updation', 'qqqqqqqqqqqqq', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-04 13:10:25', NULL, 'pending'),
('470d6e74-7710-4821-9855-26015b1a2a5a', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'd', 'feature', 'd', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-09-10 15:02:46', NULL, 'pending'),
('5a99b48d-3711-461f-99f6-208e95400d4f', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'sss', 'feature', 'ddd', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-09-10 14:35:25', NULL, 'pending'),
('5f04bf24-da43-44cc-bbfc-d8c5659ad3d4', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'qqqqqqqqqqqq', 'updation', 'qqqqqqqqqqqqqqq', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-06-26 22:12:13', NULL, 'pending'),
('633143a5-0718-4ef4-ad01-4be8e8eb198b', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'aaa', 'feature', 'aaa', 'd84019a3-575f-403c-aa12-02482422bcfa', '2025-09-10 14:37:43', NULL, 'pending'),
('64e63505-52d5-467f-941c-0a67b74f0663', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 's', 'updation', 's', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-09-10 15:12:27', NULL, 'pending'),
('6cf7dfc2-5a2f-4c3a-a814-8bc79f4f4675', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'xxxxxxx', 'updation', 'xxxxxxxxxxxxx', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-04 13:08:50', NULL, 'pending'),
('72da5f34-ff9f-492b-8ead-eb5c6b77a4ca', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'ddd', 'maintenance', 'ddd', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-09-10 14:48:05', NULL, 'pending'),
('7a4df35f-7a07-447a-a260-7c7853335bad', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'Sample', 'updation', 'sdsdsdsds', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-09-10 13:36:14', NULL, 'pending'),
('7bde0630-8557-4921-aa0b-1b976b4c88f9', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'sss', 'updation', 'dddd', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-09-10 14:10:32', NULL, 'pending'),
('8841b877-7d22-43e8-9bcd-70fef5186475', '560c6c1f-5185-4ef4-9e8f-ef1315f38930', 'ddd', 'updation', 'dddd', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-09-10 14:18:30', NULL, 'pending'),
('8b391a9e-f055-484a-a3eb-162959ba7a0d', '560c6c1f-5185-4ef4-9e8f-ef1315f38930', 'ccc', 'feature', 'c', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-09-10 15:04:14', NULL, 'pending'),
('a67b36ce-6066-48f7-a0a6-4599de247d7e', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'qqqqqqqqqq', 'feature', 'qqqqqqqqqqq', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-04 13:10:42', NULL, 'pending'),
('a740a5a8-6d51-44cb-9310-112f318197fa', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'xssss', 'updation', 'sss', 'd84019a3-575f-403c-aa12-02482422bcfa', '2025-09-10 14:46:48', NULL, 'pending'),
('afcc6379-e9fc-4575-aa31-4aedc09ebb33', '560c6c1f-5185-4ef4-9e8f-ef1315f38930', 'ssssssss', 'updation', 'sssssssssss', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-02 17:52:43', NULL, 'pending'),
('b0f6b621-db32-4b39-89d3-b5a340224e26', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'demo', 'updation', 'ddsdsd', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-09-10 14:20:15', NULL, 'pending'),
('b6ccbd56-8252-4214-aef8-edc1963407ba', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'dd', 'feature', 'dd', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-09-10 14:55:50', NULL, 'pending'),
('c1c9f666-8b40-4c9c-810c-9f3fb12a3703', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'qqqqqqqqq', 'updation', 'qqqqqqqqqqqq', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-04 13:10:17', NULL, 'pending'),
('c6afa7bd-dace-4419-a465-b00eddead797', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'x', 'feature', 'xx', 'c18ce191-e34b-4ca7-b69b-6a78488d3de5', '2025-09-10 15:13:56', NULL, 'pending'),
('d5b22970-403b-44e4-af35-48e410bd68c9', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'sssssssss', 'feature', 'ssssssssss', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-02 17:52:52', NULL, 'pending'),
('e4251e73-1d09-4d4e-a412-9a4269075142', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'sdsdwd', 'feature', 'sdsdsd', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-09-10 14:33:24', NULL, 'pending'),
('e91f9bbd-8bfb-44dc-8e4c-e38fa929206a', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'ff', 'feature', 'dddd', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-09-10 13:34:31', NULL, 'pending'),
('eb901c7a-969c-45d4-80da-f33261ccf85c', '560c6c1f-5185-4ef4-9e8f-ef1315f38930', 'xxxxxxxxxx', 'feature', 'xxxxxxxxxxxxxxx', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-07-04 13:08:41', NULL, 'pending'),
('ecb926d6-c78b-437e-9d9c-85c434422d71', '560c6c1f-5185-4ef4-9e8f-ef1315f38930', 'ddd', 'feature', 'd', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-09-10 14:52:56', NULL, 'pending'),
('ed4c8f0e-db12-46c4-88ce-b60eb03b5371', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'dddd', 'updation', 'dddd', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-09-10 13:35:05', NULL, 'pending'),
('f7d39e0c-3156-42aa-9246-2f69a8fa8a7c', '672ff940-9c60-48ef-9444-ae8903b7b0cc', 'aaa', 'updation', 'aaa', '608dc9d1-26e0-441d-8144-45f74c53a846', '2025-09-10 14:42:35', NULL, 'pending');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` varchar(36) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `password_changed_at` timestamp NULL DEFAULT NULL,
  `role` enum('admin','developer','tester','user') NOT NULL DEFAULT 'user',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `fcm_token` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `phone`, `password`, `password_changed_at`, `role`, `created_at`, `updated_at`, `fcm_token`) VALUES
('244ab83a-5049-4e61-8fb9-67e67281a3c2', 'developer1', 'moajmalnkdw@gmail.com', '+918848622227', '$2y$10$aQmCuU7nqa2W3hxTGJFeCepwp2rDXdcQcNfG13.Tar1dZcuGrejui', NULL, 'developer', '2025-08-22 17:57:57', '2025-09-10 14:40:10', NULL),
('5b5a0a88-480b-4605-8b75-e6757dbdcd3a', 'admin1', 'moajma1lnk@gmail.com', '+918848676627', '$2y$10$MsO085B5wDjg2oZ/Ztxv7uREFxRPi6EQLlQODDOO5EmiE6LOkqo2W', NULL, 'admin', '2025-07-24 12:33:06', '2025-09-10 05:36:36', NULL),
('608dc9d1-26e0-441d-8144-45f74c53a846', 'moajmalnk', 'moajmalnk@gmail.com', '+919526271123', '$2y$10$RTfsi6ck/GnzkAMhHHfvoeJZ1Uqr6q1C/LuOK7i1D/hv0ZwzH2qsm', NULL, 'admin', '2025-04-10 12:22:47', '2025-09-11 12:15:59', 'dDF1V-uiZ8c7MEvPRQrBO1:APA91bF_dONe9tAEFP8C5t5HpL6l3vtAfj4zklhnM6ma7ExlaYGkrCoZ7qtCYoG0rolRXJ_IrVcsTR4r5MabUmbXpehLwUyU5tiEZhAv2K7KPLkbqu1XG7A'),
('6f9956a6-533b-48a5-98cb-4ddcc2cdf101', 'sssssssssssssssssss', 'moajmalnssssssk@gmail.com', '+918086995559', '$2y$10$QezQ60llxrIXtCB59lxEe.q5GtR.TL0AjyMG36kt/tHHehWvqQPQy', NULL, 'tester', '2025-07-04 12:22:44', '2025-07-26 18:46:28', NULL),
('799b8406-7a20-4b8c-b7a9-c467f0c6268e', 'ajmalnk', 'premium.codomail@gmail.com', '+919188486766', '$2y$10$RTfsi6ck/GnzkAMhHHfvoeJZ1Uqr6q1C/LuOK7i1D/hv0ZwzH2qsm', NULL, 'admin', '2025-05-27 12:41:20', '2025-09-11 12:15:37', 'fXeB5cgbt_RhQbL2KriAb9:APA91bGBsp1elfJMH5OpgX34MHGXULoWQxEm2Lf2mmYiiuNT4yKFF0ANDXW097tUKwuoF6Y4zUMQ3TciWl8kffnksZ9r8EUtK29ys0Twka6f3GboGeqDdyA'),
('b6b1ab7a-f3c0-4b33-b953-ef4428a2c318', 'ssssssss', 'moasssssssssjmalnk@gmail.com', '', '$2y$10$Qz4lnfLXWSXljOOxpPt3zehJMnlqiTVt15xNzx9rBNE/jmKO0h4di', NULL, 'tester', '2025-07-04 12:50:43', '2025-07-24 12:27:28', NULL),
('c18ce191-e34b-4ca7-b69b-6a78488d3de5', 'tester', 'moajmalssnk@gmail.com', '+918848344627', '$2y$10$wIISVTafaS9XmMZfEhszueBvFQIhN5WAoU1bMBIswFqIS.6gGJDp2', NULL, 'tester', '2025-08-22 17:57:27', '2025-09-10 13:12:52', NULL),
('d2c3b775-f07c-4c92-83d7-4300f4cf9a1b', '11111111111111', 'moajmal11111111nk@gmail.com', NULL, '$2y$10$nJuFUDEIlBXlqVce4S0s8OxROH6eFvLoVRi1bYi/kbhhmsbDcWI1m', NULL, 'tester', '2025-07-04 11:46:44', '2025-07-04 11:46:44', NULL),
('d84019a3-575f-403c-aa12-02482422bcfa', 'developer', 'hi.ajmalnk@gmail.com', '+919526271192', '$2y$10$2eKr3ADViuRc0is3GM53xOsKntjvJoou9I26Wu7B.IzpfI7U3lGmm', NULL, 'developer', '2025-04-18 17:34:28', '2025-09-10 14:47:38', 'c2xfeP3Zpc7FltjprjGag1:APA91bEPrRljjh2__NzSRPj-bOse7hVv4692mumqlNw9KvJesokqB0-j1gsOa5lV0h9o_J12QI6kHOoLUCAOs_e23hWKuG9zfhZxMBVocVASFtlf6anyxCg'),
('d8edceb5-ca8a-446d-ad21-54d280064e69', 'aaaaaaaa', 'ajmalnk10091@gmail.com', NULL, '$2y$10$dGM.GNZc9nfsuig9av2VmupBbPTqvVBxgpiRFUIQKfUj3W8/.xwCm', NULL, 'tester', '2025-06-26 15:54:51', '2025-06-27 07:19:04', NULL),
('f2d5c5f4-d34f-4034-a9b5-39783df2da4b', 'aaaaaaaaaa', 'moajaaaaaamalnk@gmail.com', '9526271193', '$2y$10$e02iYlZgyfcS.bmkYttEu.q4AkBeItN92ybEO0nrjApBkjKgMFnye', NULL, 'tester', '2025-07-04 11:51:11', '2025-07-04 12:26:08', NULL);

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

--
-- Dumping data for table `user_otps`
--

INSERT INTO `user_otps` (`id`, `email`, `phone`, `otp`, `expires_at`, `created_at`) VALUES
(1, 'moajmalnk@gmail.com', NULL, '413046', '2025-07-04 11:20:38', '2025-07-04 11:15:38'),
(2, NULL, '8086995559', '867957', '2025-07-04 11:20:56', '2025-07-04 11:15:56'),
(3, 'moajmalnk@gmail.com', NULL, '682804', '2025-07-04 11:22:18', '2025-07-04 11:17:18'),
(4, NULL, '8848676627', '218517', '2025-07-04 11:26:06', '2025-07-04 11:21:06'),
(5, NULL, '8848676627', '822981', '2025-07-04 11:27:45', '2025-07-04 11:22:45'),
(6, NULL, '8848676627', '225972', '2025-07-04 11:31:06', '2025-07-04 11:26:06'),
(8, NULL, '8848676627', '517800', '2025-07-04 11:37:11', '2025-07-04 11:32:11'),
(10, 'moajmalnk@gmail.com', NULL, '357977', '2025-07-04 12:34:49', '2025-07-04 12:29:49'),
(11, NULL, '918848676627', '339186', '2025-07-04 12:45:12', '2025-07-04 12:40:12'),
(12, NULL, '918848676627', '698733', '2025-07-04 12:51:38', '2025-07-04 12:46:38'),
(13, NULL, '918848676627', '122264', '2025-07-22 09:06:50', '2025-07-22 09:01:50'),
(14, 'moajmalnk@gmail.com', NULL, '936703', '2025-07-22 09:53:20', '2025-07-22 09:48:20'),
(15, NULL, '+918848676627', '128690', '2025-07-24 10:21:19', '2025-07-24 10:16:19'),
(16, 'moajmalnk@gmail.com', NULL, '609321', '2025-07-24 10:25:25', '2025-07-24 10:20:25'),
(17, 'moajmalnk@gmail.com', NULL, '978799', '2025-07-24 10:30:46', '2025-07-24 10:25:46'),
(18, 'moajmalnk@gmail.com', NULL, '941778', '2025-07-24 10:34:02', '2025-07-24 10:29:02'),
(19, 'moajmalnk@gmail.com', NULL, '640802', '2025-07-24 10:40:08', '2025-07-24 10:35:08'),
(22, 'moajmalnk@gmail.com', NULL, '002091', '2025-07-24 11:04:47', '2025-07-24 10:59:47'),
(23, NULL, '+918848676627', '604492', '2025-07-24 11:08:54', '2025-07-24 11:03:54'),
(24, NULL, '+918848676627', '868055', '2025-07-24 11:12:18', '2025-07-24 11:07:18'),
(25, 'moajmalnk@gmail.com', NULL, '851082', '2025-07-24 11:15:25', '2025-07-24 11:10:25'),
(26, NULL, '+918848676627', '042607', '2025-07-24 11:16:32', '2025-07-24 11:11:32'),
(27, 'moajmalnk@gmail.com', NULL, '618687', '2025-07-24 11:33:24', '2025-07-24 11:28:24'),
(28, 'moajmalnk@gmail.com', NULL, '643461', '2025-07-24 11:42:25', '2025-07-24 11:37:25'),
(29, 'moajmalnk@gmail.com', NULL, '326694', '2025-07-24 11:44:09', '2025-07-24 11:39:09'),
(30, 'moajmalnk@gmail.com', NULL, '184907', '2025-07-24 11:49:28', '2025-07-24 11:44:28'),
(31, 'moajmalnk@gmail.com', NULL, '268757', '2025-07-24 11:51:37', '2025-07-24 11:46:37'),
(35, 'moajmalnk@gmail.com', NULL, '321938', '2025-07-24 12:01:00', '2025-07-24 11:56:00'),
(45, NULL, '+918848676627', '804819', '2025-07-26 19:20:05', '2025-07-26 19:15:05'),
(46, NULL, '+918848676627', '606399', '2025-07-26 19:20:56', '2025-07-26 19:15:56'),
(51, NULL, '+918086995559', '612930', '2025-08-22 17:25:48', '2025-08-22 17:20:48'),
(54, NULL, '+918848676627', '258716', '2025-08-22 20:32:45', '2025-08-22 20:27:45'),
(62, 'moajmalnk@gmail.com', NULL, '206629', '2025-09-09 14:36:17', '2025-09-09 14:31:17'),
(63, 'moajmalnk@gmail.com', NULL, '482805', '2025-09-09 14:39:12', '2025-09-09 14:34:12'),
(65, 'moajmalnk@gmail.com', NULL, '276153', '2025-09-10 04:43:59', '2025-09-10 04:38:59'),
(69, 'moajmalnk@gmail.com', NULL, '226746', '2025-09-10 16:19:14', '2025-09-10 16:14:14'),
(70, 'moajmalnk@gmail.com', NULL, '936698', '2025-09-11 02:27:35', '2025-09-11 02:22:35'),
(72, NULL, '+918848676627', '824215', '2025-09-11 03:09:02', '2025-09-11 03:04:02'),
(73, 'moajmalnk@gmail.com', NULL, '855483', '2025-09-11 03:12:01', '2025-09-11 03:07:01'),
(74, 'moajmalnk@gmail.com', NULL, '277559', '2025-09-11 03:13:53', '2025-09-11 03:08:53'),
(75, NULL, '+918848676627', '461560', '2025-09-11 03:14:11', '2025-09-11 03:09:11');

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
-- Indexes for table `chat_groups`
--
ALTER TABLE `chat_groups`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_chat_groups_project_id` (`project_id`),
  ADD KEY `idx_chat_groups_created_by` (`created_by`),
  ADD KEY `idx_chat_groups_is_active` (`is_active`),
  ADD KEY `idx_chat_groups_project_active` (`project_id`,`is_active`);

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
  ADD KEY `idx_chat_messages_sender_created` (`sender_id`,`created_at`);

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
-- Indexes for table `message_mentions`
--
ALTER TABLE `message_mentions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_message_mentions_message_id` (`message_id`),
  ADD KEY `idx_message_mentions_user_id` (`mentioned_user_id`);

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
  ADD KEY `idx_project_activities_related` (`related_id`);

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
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `key_name` (`key_name`);

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
  ADD KEY `idx_users_role` (`role`),
  ADD KEY `idx_users_email` (`email`),
  ADD KEY `idx_users_id_role` (`id`,`role`),
  ADD KEY `idx_users_role_email` (`role`,`email`),
  ADD KEY `idx_users_created_at` (`created_at`),
  ADD KEY `idx_users_covering_profile` (`id`,`username`,`email`,`role`,`created_at`,`updated_at`),
  ADD KEY `idx_users_fcm_token` (`fcm_token`),
  ADD KEY `idx_users_phone` (`phone`);

--
-- Indexes for table `user_otps`
--
ALTER TABLE `user_otps`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_otps_email` (`email`),
  ADD KEY `idx_user_otps_phone` (`phone`);

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
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin_audit_log`
--
ALTER TABLE `admin_audit_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=91;

--
-- AUTO_INCREMENT for table `announcements`
--
ALTER TABLE `announcements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `meetings`
--
ALTER TABLE `meetings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `meeting_messages`
--
ALTER TABLE `meeting_messages`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `meeting_participants`
--
ALTER TABLE `meeting_participants`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `meeting_recordings`
--
ALTER TABLE `meeting_recordings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=101;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `project_activities`
--
ALTER TABLE `project_activities`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=38;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `user_otps`
--
ALTER TABLE `user_otps`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=78;

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
-- Constraints for table `message_mentions`
--
ALTER TABLE `message_mentions`
  ADD CONSTRAINT `message_mentions_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `chat_messages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `message_mentions_ibfk_2` FOREIGN KEY (`mentioned_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

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