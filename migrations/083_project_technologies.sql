-- Shared technology catalog for project Technology Stack dropdowns.
-- Project field remains comma-separated `projects.technology_stack` (names).

CREATE TABLE IF NOT EXISTS `project_technologies` (
  `id` varchar(36) NOT NULL,
  `name` varchar(100) NOT NULL,
  `slug` varchar(64) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_project_technologies_slug` (`slug`),
  KEY `idx_project_technologies_active_sort` (`is_active`, `sort_order`),
  KEY `idx_project_technologies_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `project_technologies` (`id`, `name`, `slug`, `is_active`, `sort_order`)
VALUES
  ('pt-react-00000000000000000001', 'React', 'react', 1, 10),
  ('pt-typescript-000000000000002', 'TypeScript', 'typescript', 1, 20),
  ('pt-javascript-000000000000003', 'JavaScript', 'javascript', 1, 30),
  ('pt-php-000000000000000000004', 'PHP', 'php', 1, 40),
  ('pt-laravel-00000000000000005', 'Laravel', 'laravel', 1, 50),
  ('pt-mysql-0000000000000000006', 'MySQL', 'mysql', 1, 60),
  ('pt-nodejs-000000000000000007', 'Node.js', 'nodejs', 1, 70),
  ('pt-vue-000000000000000000008', 'Vue', 'vue', 1, 80),
  ('pt-angular-00000000000000009', 'Angular', 'angular', 1, 90),
  ('pt-python-000000000000000010', 'Python', 'python', 1, 100),
  ('pt-tailwind-0000000000000011', 'Tailwind CSS', 'tailwind_css', 1, 110),
  ('pt-nextjs-000000000000000012', 'Next.js', 'nextjs', 1, 120),
  ('pt-express-00000000000000013', 'Express', 'express', 1, 130),
  ('pt-postgresql-00000000000014', 'PostgreSQL', 'postgresql', 1, 140),
  ('pt-mongodb-00000000000000015', 'MongoDB', 'mongodb', 1, 150),
  ('pt-redis-000000000000000016', 'Redis', 'redis', 1, 160),
  ('pt-docker-000000000000000017', 'Docker', 'docker', 1, 170),
  ('pt-aws-000000000000000000018', 'AWS', 'aws', 1, 180),
  ('pt-firebase-0000000000000019', 'Firebase', 'firebase', 1, 190),
  ('pt-flutter-00000000000000020', 'Flutter', 'flutter', 1, 200)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `is_active` = VALUES(`is_active`),
  `sort_order` = VALUES(`sort_order`);
