-- BugDates holiday fixes on top of 120.
-- 1) Yearly national holidays were anchored at their *next* date (e.g. Independence Day 2027-08-15),
--    and yearly events only expand on/after the anchor — so Aug 2026 showed no holiday. Re-anchor to
--    the previous year. Only rows still on the seeded date are touched, so admin edits are kept.
-- 2) Gazetted public holidays seeded as `observance` now use `holiday` so the Holidays filter finds them.
-- 3) Adds the Aug–Sep 2026 festivals (Onam season) that 120 did not cover (it starts Oct 2026).
-- is_office_closed stays 0: office closure drives attendance + checkout bypass, so an admin enables
-- it per event against the official company holiday list.
-- Safe to re-run.

UPDATE `bug_dates_events` SET `start_date` = '2026-08-15'
WHERE `title` = 'Independence Day' AND `recurrence_type` = 'yearly' AND `start_date` = '2027-08-15';

UPDATE `bug_dates_events` SET `start_date` = '2026-01-26'
WHERE `title` = 'Republic Day' AND `recurrence_type` = 'yearly' AND `start_date` = '2027-01-26';

UPDATE `bug_dates_events` SET `start_date` = '2025-10-02'
WHERE `title` = 'Gandhi Jayanti' AND `recurrence_type` = 'yearly' AND `start_date` = '2026-10-02';

UPDATE `bug_dates_events` SET `category` = 'holiday'
WHERE `category` = 'observance'
  AND (
    (`title` = 'Dussehra / Vijayadashami' AND `start_date` = '2026-10-20')
    OR (`title` = 'Diwali' AND `start_date` = '2026-11-08')
    OR (`title` = 'Christmas' AND `start_date` = '2026-12-25')
    OR (`title` = 'Eid al-Fitr' AND `start_date` = '2027-03-10')
    OR (`title` = 'Holi' AND `start_date` = '2027-03-22')
    OR (`title` = 'Good Friday' AND `start_date` = '2027-03-26')
    OR (`title` = 'Vishu' AND `start_date` = '2027-04-15')
    OR (`title` = 'May Day / Labour Day' AND `start_date` = '2027-05-01')
    OR (`title` = 'Eid al-Adha (Bakrid)' AND `start_date` = '2027-05-16')
  );

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
SELECT v.title, v.description, v.category, v.recurrence_type, v.start_date,
  0, JSON_OBJECT('creative', true), 'company', 'approved', @seed_user
FROM (
  SELECT 'First Onam (Uthradom)' AS title,
         'Tentative date (Malayalam calendar) — eve of Thiruvonam, Kerala holiday. Content angle: Onam shopping, pookalam prep, team sadya plans.' AS description,
         'holiday' AS category, 'none' AS recurrence_type, '2026-08-25' AS start_date
  UNION ALL SELECT 'Thiruvonam',
         'Tentative date (Malayalam calendar) — Kerala''s harvest festival, state holiday. Content angle: pookalam, sadya and Onam wishes from the team.',
         'holiday', 'none', '2026-08-26'
  UNION ALL SELECT 'Milad-un-Nabi',
         'Tentative date — Prophet''s birthday (12 Rabi al-Awwal 1448 AH); depends on moon sighting. Content angle: respectful greetings.',
         'holiday', 'none', '2026-08-26'
  UNION ALL SELECT 'Third Onam (Avittam)',
         'Tentative date (Malayalam calendar) — Kerala holiday after Thiruvonam. Content angle: Onam celebrations continue.',
         'holiday', 'none', '2026-08-27'
  UNION ALL SELECT 'Sree Narayana Guru Jayanthi',
         'Tentative date (Malayalam calendar) — birth anniversary of Sree Narayana Guru, Kerala holiday. Content angle: "One caste, one religion, one God" — equality and education.',
         'holiday', 'none', '2026-08-28'
  UNION ALL SELECT 'Raksha Bandhan',
         'Tentative date (Hindu calendar) — sibling bond festival. Content angle: team-as-family moments.',
         'observance', 'none', '2026-08-28'
  UNION ALL SELECT 'Sri Krishna Janmashtami',
         'Tentative date (Hindu calendar) — birth of Lord Krishna, gazetted holiday. Content angle: festive greetings.',
         'holiday', 'none', '2026-09-04'
  UNION ALL SELECT 'Ganesh Chaturthi',
         'Tentative date (Hindu calendar) — Lord Ganesha festival, new beginnings. Content angle: fresh starts and removing blockers.',
         'holiday', 'none', '2026-09-14'
  UNION ALL SELECT 'Sree Narayana Guru Samadhi',
         'Tentative date (Malayalam calendar) — Sree Narayana Guru''s samadhi day, Kerala holiday. Content angle: respectful tribute, no promotional tone.',
         'holiday', 'none', '2026-09-21'
) AS v
WHERE @seed_user IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM bug_dates_events e
    WHERE LOWER(e.title) = LOWER(v.title)
      AND (e.start_date = v.start_date OR e.recurrence_type = 'yearly')
  );
