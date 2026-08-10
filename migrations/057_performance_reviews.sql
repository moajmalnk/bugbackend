-- =============================================================================
-- BugRicer Migration 057 — Monthly Performance Reviews (admin-only)
-- =============================================================================
-- Tables: review_templates, review_questions, performance_reviews, review_answers
-- Permission: PERFORMANCE_REVIEWS_MANAGE (granted to Admin role_id = 1)
-- Seeds one active Monthly Growth Review template with default questions.
-- Safe to re-run.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `review_templates` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(255) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` VARCHAR(36) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_review_templates_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `review_questions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `template_id` INT UNSIGNED NOT NULL,
  `section_name` VARCHAR(100) NOT NULL DEFAULT '',
  `question_text` TEXT NOT NULL,
  `question_type` ENUM('rating_1_5','short_text','long_text','multi_select','boolean') NOT NULL DEFAULT 'short_text',
  `options_json` TEXT NULL DEFAULT NULL,
  `is_required` TINYINT(1) NOT NULL DEFAULT 0,
  `display_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_review_questions_template_order` (`template_id`, `display_order`),
  CONSTRAINT `fk_review_questions_template`
    FOREIGN KEY (`template_id`) REFERENCES `review_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `performance_reviews` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` VARCHAR(36) NOT NULL,
  `reviewer_id` VARCHAR(36) NOT NULL,
  `department` VARCHAR(100) NOT NULL DEFAULT '',
  `review_month` VARCHAR(7) NOT NULL COMMENT 'YYYY-MM',
  `review_date` DATE NOT NULL,
  `status` ENUM('draft','completed') NOT NULL DEFAULT 'draft',
  `overall_rating` DECIMAL(3,2) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_employee_review_month` (`employee_id`, `review_month`),
  KEY `idx_perf_reviews_department` (`department`),
  KEY `idx_perf_reviews_status` (`status`),
  KEY `idx_perf_reviews_month` (`review_month`),
  KEY `idx_perf_reviews_created` (`created_at`),
  KEY `idx_perf_reviews_reviewer` (`reviewer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `review_answers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `review_id` INT UNSIGNED NOT NULL,
  `question_id` INT UNSIGNED NOT NULL,
  `answer_text` LONGTEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_review_question` (`review_id`, `question_id`),
  KEY `idx_review_answers_question` (`question_id`),
  CONSTRAINT `fk_review_answers_review`
    FOREIGN KEY (`review_id`) REFERENCES `performance_reviews` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_review_answers_question`
    FOREIGN KEY (`question_id`) REFERENCES `review_questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Permission seed
INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'PERFORMANCE_REVIEWS_MANAGE', 'Manage Performance Reviews', 'Performance Reviews', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (
  SELECT 1 FROM `permissions` WHERE `permission_key` = 'PERFORMANCE_REVIEWS_MANAGE'
);

INSERT INTO `role_permissions` (`role_id`, `permission_id`, `created_at`)
SELECT 1, p.id, NOW()
FROM `permissions` p
WHERE p.permission_key = 'PERFORMANCE_REVIEWS_MANAGE'
AND NOT EXISTS (
  SELECT 1 FROM `role_permissions` rp
  WHERE rp.role_id = 1 AND rp.permission_id = p.id
);

-- Seed default active template (only if none exists)
INSERT INTO `review_templates` (`title`, `is_active`, `created_by`)
SELECT 'Monthly Growth Review Template', 1, NULL
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `review_templates` LIMIT 1);

-- Seed default questions for the first active template (only if that template has none)
INSERT INTO `review_questions`
  (`template_id`, `section_name`, `question_text`, `question_type`, `options_json`, `is_required`, `display_order`)
SELECT t.id, q.section_name, q.question_text, q.question_type, q.options_json, q.is_required, q.display_order
FROM (
  SELECT id FROM `review_templates` WHERE `is_active` = 1 ORDER BY `id` ASC LIMIT 1
) t
CROSS JOIN (
  SELECT 'Core Execution & Quality' AS section_name,
         'Overall delivery quality this month (1–5)' AS question_text,
         'rating_1_5' AS question_type, NULL AS options_json, 1 AS is_required, 10 AS display_order
  UNION ALL SELECT 'Core Execution & Quality',
         'Key projects / deliverables completed',
         'long_text', NULL, 1, 20
  UNION ALL SELECT 'Core Execution & Quality',
         'Missed deadlines or quality issues?',
         'short_text', NULL, 0, 30
  UNION ALL SELECT 'Ownership & Collaboration',
         'Ownership & accountability rating (1–5)',
         'rating_1_5', NULL, 1, 40
  UNION ALL SELECT 'Ownership & Collaboration',
         'Collaboration with teammates / clients',
         'long_text', NULL, 0, 50
  UNION ALL SELECT 'Blockers & Challenges',
         'Main challenges / blockers this month',
         'long_text', NULL, 1, 60
  UNION ALL SELECT 'Blockers & Challenges',
         'Client delays or unclear requirements?',
         'long_text', NULL, 0, 70
  UNION ALL SELECT 'Blockers & Overtime',
         'Overtime causes (if any)',
         'multi_select',
         '["Client changes","Unclear requirements","Scope creep","Tight deadline","Technical debt","Other"]',
         0, 80
  UNION ALL SELECT 'Blockers & Overtime',
         'Needed overtime this month?',
         'boolean', NULL, 0, 90
  UNION ALL SELECT 'Innovation & Growth',
         'Growth goals / skills focus next month',
         'long_text', NULL, 0, 100
  UNION ALL SELECT 'Innovation & Growth',
         'Automation / process improvements contributed',
         'short_text', NULL, 0, 110
) q
WHERE NOT EXISTS (
  SELECT 1 FROM `review_questions` rq WHERE rq.template_id = t.id
);
