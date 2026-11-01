-- Kerala Piravi (Nov 1) — ensure BugDates has the observance with CODO creative brief.
-- Why: Nov 1 showed empty in BugDates while peer observances existed; creators need a
-- Design Poster path with Malayalam + "built in Kerala" brand angle on Formation Day.
-- Safe to re-run: skips duplicate title+yearly, then refreshes description.

SET @seed_user := (
  SELECT id FROM users WHERE role = 'admin' ORDER BY created_at ASC LIMIT 1
);
SET @seed_user := IFNULL(@seed_user, (
  SELECT id FROM users ORDER BY created_at ASC LIMIT 1
));

INSERT INTO `bug_dates_events` (
  `title`, `description`, `category`, `recurrence_type`, `start_date`,
  `is_office_closed`, `auto_hooks`, `visibility`, `status`, `created_by`
)
SELECT
  'Kerala Piravi',
  '[High] Kerala Formation Day (1 Nov 1956) — 70th anniversary in 2026. Content angle: bilingual Malayalam greeting (ഏവർക്കും കേരളപ്പിറവി ആശംസകൾ), CODO AI Innovations ''built in Kerala · building for the world'' pride post, tech-meets-heritage creatives. Templates: Malayalam Message, Heritage Hero. Priority: High.',
  'observance',
  'yearly',
  '2026-11-01',
  0,
  JSON_OBJECT('creative', true),
  'company',
  'approved',
  @seed_user
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `bug_dates_events`
  WHERE `title` = 'Kerala Piravi' AND `recurrence_type` = 'yearly'
)
AND NOT EXISTS (
  SELECT 1 FROM `bug_dates_events`
  WHERE `title` = 'Kerala Piravi' AND `start_date` = '2026-11-01'
);

UPDATE `bug_dates_events`
SET `description` = '[High] Kerala Formation Day (1 Nov 1956) — 70th anniversary in 2026. Content angle: bilingual Malayalam greeting (ഏവർക്കും കേരളപ്പിറവി ആശംസകൾ), CODO AI Innovations ''built in Kerala · building for the world'' pride post, tech-meets-heritage creatives. Templates: Malayalam Message, Heritage Hero. Priority: High.',
    `category` = 'observance',
    `auto_hooks` = JSON_OBJECT('creative', true),
    `visibility` = 'company',
    `status` = 'approved'
WHERE `title` = 'Kerala Piravi'
  AND (`recurrence_type` = 'yearly' OR `start_date` = '2026-11-01');
