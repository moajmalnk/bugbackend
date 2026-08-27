-- BugDates: unified events, growth sessions, hook ledger, permissions.
-- Safe to re-run.

CREATE TABLE IF NOT EXISTS `bug_dates_events` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `category` ENUM(
    'growth_program',
    'observance',
    'holiday',
    'milestone',
    'company_event'
  ) NOT NULL,
  `recurrence_type` ENUM('none', 'daily', 'weekly', 'monthly', 'yearly') NOT NULL DEFAULT 'none',
  `recurrence_days` JSON NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NULL,
  `start_time` TIME NULL,
  `end_time` TIME NULL,
  `location_or_link` TEXT NULL,
  `is_office_closed` TINYINT(1) NOT NULL DEFAULT 0,
  `auto_hooks` JSON NULL,
  `visibility` ENUM('company', 'hr_only', 'admins') NOT NULL DEFAULT 'company',
  `status` ENUM('approved', 'pending_approval', 'rejected') NOT NULL DEFAULT 'approved',
  `created_by` VARCHAR(36) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bug_dates_events_dates` (`start_date`, `end_date`),
  KEY `idx_bug_dates_events_category` (`category`),
  KEY `idx_bug_dates_events_status` (`status`),
  KEY `idx_bug_dates_events_closed` (`is_office_closed`, `start_date`),
  KEY `idx_bug_dates_events_visibility` (`visibility`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `growth_program_sessions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_id` INT UNSIGNED NOT NULL,
  `session_date` DATE NOT NULL,
  `host_user_id` VARCHAR(36) NULL,
  `agenda_topic` VARCHAR(255) NULL,
  `summary_notes` TEXT NULL,
  `recording_or_drive_link` TEXT NULL,
  `weekly_report_task_id` VARCHAR(64) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_growth_session_event_date` (`event_id`, `session_date`),
  KEY `idx_growth_sessions_date` (`session_date`),
  KEY `idx_growth_sessions_host` (`host_user_id`),
  CONSTRAINT `fk_growth_sessions_event`
    FOREIGN KEY (`event_id`) REFERENCES `bug_dates_events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bug_dates_hooks` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_id` INT UNSIGNED NULL,
  `occurrence_date` DATE NOT NULL,
  `hook_type` ENUM('creative_card', 'shared_task', 'checkout_bypass') NOT NULL,
  `target_table` VARCHAR(64) NULL,
  `target_id` VARCHAR(64) NULL,
  `created_by` VARCHAR(36) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bug_dates_hooks_dedupe` (`event_id`, `occurrence_date`, `hook_type`),
  KEY `idx_bug_dates_hooks_date` (`occurrence_date`),
  KEY `idx_bug_dates_hooks_type` (`hook_type`),
  CONSTRAINT `fk_bug_dates_hooks_event`
    FOREIGN KEY (`event_id`) REFERENCES `bug_dates_events` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'BUGDATES_VIEW', 'View BugDates Calendar', 'BugDates', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (
  SELECT 1 FROM `permissions` WHERE `permission_key` = 'BUGDATES_VIEW'
);

INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'BUGDATES_MANAGE', 'Manage BugDates Events', 'BugDates', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (
  SELECT 1 FROM `permissions` WHERE `permission_key` = 'BUGDATES_MANAGE'
);

INSERT INTO `role_permissions` (`role_id`, `permission_id`, `created_at`)
SELECT 1, p.id, NOW()
FROM `permissions` p
WHERE p.permission_key IN ('BUGDATES_VIEW', 'BUGDATES_MANAGE')
AND NOT EXISTS (
  SELECT 1 FROM `role_permissions` rp
  WHERE rp.role_id = 1 AND rp.permission_id = p.id
);

INSERT INTO `role_permissions` (`role_id`, `permission_id`, `created_at`)
SELECT r.id, p.id, NOW()
FROM `roles` r
CROSS JOIN `permissions` p
WHERE r.id IN (2, 3, 4)
  AND p.permission_key = 'BUGDATES_VIEW'
  AND NOT EXISTS (
    SELECT 1 FROM `role_permissions` rp
    WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );

INSERT INTO `role_permissions` (`role_id`, `permission_id`, `created_at`)
SELECT r.id, p.id, NOW()
FROM `roles` r
CROSS JOIN `permissions` p
WHERE LOWER(r.role_name) = 'creator'
  AND p.permission_key = 'BUGDATES_VIEW'
  AND NOT EXISTS (
    SELECT 1 FROM `role_permissions` rp
    WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );

-- Seed system user for created_by when no admin UUID is known at migration time.
SET @seed_user := (
  SELECT id FROM users WHERE role = 'admin' ORDER BY created_at ASC LIMIT 1
);
SET @seed_user := IFNULL(@seed_user, (
  SELECT id FROM users ORDER BY created_at ASC LIMIT 1
));

INSERT INTO `bug_dates_events` (
  `title`, `description`, `category`, `recurrence_type`, `recurrence_days`,
  `start_date`, `start_time`, `end_time`, `is_office_closed`, `auto_hooks`,
  `visibility`, `status`, `created_by`
)
SELECT
  'Tuesday Growth Glimpse',
  'Mid-sprint progress check and internal showcase.',
  'growth_program',
  'weekly',
  '["tuesday"]',
  CURDATE(),
  '16:00:00',
  '17:00:00',
  0,
  JSON_OBJECT('todo', 'session_notes'),
  'company',
  'approved',
  @seed_user
FROM DUAL
WHERE @seed_user IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM bug_dates_events
    WHERE title = 'Tuesday Growth Glimpse' AND category = 'growth_program'
  );

INSERT INTO `bug_dates_events` (
  `title`, `description`, `category`, `recurrence_type`, `recurrence_days`,
  `start_date`, `start_time`, `end_time`, `is_office_closed`, `auto_hooks`,
  `visibility`, `status`, `created_by`
)
SELECT
  'Thursday Growth Glimpse',
  'Skill-share / tech breakdown and client progress sync.',
  'growth_program',
  'weekly',
  '["thursday"]',
  CURDATE(),
  '16:00:00',
  '17:00:00',
  0,
  JSON_OBJECT('todo', 'session_notes'),
  'company',
  'approved',
  @seed_user
FROM DUAL
WHERE @seed_user IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM bug_dates_events
    WHERE title = 'Thursday Growth Glimpse' AND category = 'growth_program'
  );

INSERT INTO `bug_dates_events` (
  `title`, `description`, `category`, `recurrence_type`, `recurrence_days`,
  `start_date`, `start_time`, `end_time`, `is_office_closed`, `auto_hooks`,
  `visibility`, `status`, `created_by`
)
SELECT
  'Saturday Growth Glimpse & Checkout',
  'Weekly retrospective, final asset wrap-up, and automated weekly report generation.',
  'growth_program',
  'weekly',
  '["saturday"]',
  CURDATE(),
  '11:00:00',
  '13:00:00',
  0,
  JSON_OBJECT('todo', 'weekly_report'),
  'company',
  'approved',
  @seed_user
FROM DUAL
WHERE @seed_user IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM bug_dates_events
    WHERE title = 'Saturday Growth Glimpse & Checkout' AND category = 'growth_program'
  );

INSERT INTO `bug_dates_events` (
  `title`, `description`, `category`, `recurrence_type`, `start_date`,
  `is_office_closed`, `auto_hooks`, `visibility`, `status`, `created_by`
)
SELECT v.title, v.description, 'observance', 'yearly', v.start_date,
  0, JSON_OBJECT('creative', true), 'company', 'approved', @seed_user
FROM (
  SELECT 'Teachers'' Day' AS title,
         'National Teachers'' Day — creative campaign alignment.' AS description,
         '2026-09-05' AS start_date
  UNION ALL SELECT 'Engineers'' Day',
         'National Engineers'' Day — creative campaign alignment.',
         '2026-09-15'
  UNION ALL SELECT 'World Tourism Day',
         'World Tourism Day — creative campaign alignment.',
         '2026-09-27'
  UNION ALL SELECT 'World Ozone Day',
         'International Day for the Preservation of the Ozone Layer.',
         '2026-09-16'
  UNION ALL SELECT 'International Literacy Day',
         'International Literacy Day — creative campaign alignment.',
         '2026-09-08'
  UNION ALL SELECT 'International Day of Peace',
         'World Peace Day — creative campaign alignment.',
         '2026-09-21'
) AS v
WHERE @seed_user IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM bug_dates_events e
    WHERE e.title = v.title AND e.category = 'observance'
  );
