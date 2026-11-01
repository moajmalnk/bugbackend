-- Kerala Piravi (Nov 1) — force a clean yearly observance for BugDates + Design Poster.
-- Why: an existing row can block the calendar if end_date is set, start_date is a future
-- year, or recurrence_type stayed `none`. Expand-occurrences only emits dates on/after
-- start_date and on/before end_date — so we normalise those columns every run.
-- Safe to re-run.

SET @seed_user := (
  SELECT id FROM users WHERE role = 'admin' ORDER BY created_at ASC LIMIT 1
);
SET @seed_user := IFNULL(@seed_user, (
  SELECT id FROM users ORDER BY created_at ASC LIMIT 1
));

INSERT INTO `bug_dates_events` (
  `title`, `description`, `category`, `recurrence_type`, `start_date`, `end_date`,
  `is_office_closed`, `auto_hooks`, `visibility`, `status`, `created_by`
)
SELECT
  'Kerala Piravi',
  '[High] Kerala Formation Day (1 Nov 1956) — 70th anniversary in 2026. Content angle: bilingual Malayalam greeting (ഏവർക്കും കേരളപ്പിറവി ആശംസകൾ), CODO AI Innovations ''built in Kerala · building for the world'' pride post, tech-meets-heritage creatives. Templates: Malayalam Message, Heritage Hero. Priority: High.',
  'observance',
  'yearly',
  '2026-11-01',
  NULL,
  0,
  JSON_OBJECT('creative', true),
  'company',
  'approved',
  @seed_user
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `bug_dates_events` WHERE `title` = 'Kerala Piravi'
);

-- Normalise every Kerala Piravi row so Nov 1 always appears in the calendar.
UPDATE `bug_dates_events`
SET
  `description` = '[High] Kerala Formation Day (1 Nov 1956) — 70th anniversary in 2026. Content angle: bilingual Malayalam greeting (ഏവർക്കും കേരളപ്പിറവി ആശംസകൾ), CODO AI Innovations ''built in Kerala · building for the world'' pride post, tech-meets-heritage creatives. Templates: Malayalam Message, Heritage Hero. Priority: High.',
  `category` = 'observance',
  `recurrence_type` = 'yearly',
  `start_date` = '2026-11-01',
  `end_date` = NULL,
  `is_office_closed` = 0,
  `auto_hooks` = JSON_OBJECT('creative', true),
  `visibility` = 'company',
  `status` = 'approved'
WHERE `title` = 'Kerala Piravi';
