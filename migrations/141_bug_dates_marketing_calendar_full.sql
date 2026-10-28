-- BugDates: full marketing / content-calendar important dates (Oct 2026 → Oct 2027).
-- Aligns with the CODO content sheet: Month, Date, Occasion, Category, Content angle, Priority.
-- High-priority rows start with "[High]" in the description for creative planning.
-- Lunar / moon-sighting / Malayalam-calendar dates are marked Tentative — confirm and edit in BugDates if they shift.
-- Safe to re-run: inserts skip title+date (or yearly) duplicates; description updates are keyed by title+date/recurrence.
-- Apply against a DB that already has migrations 095 + 120 + 121 (bug_dates_events + users).

-- ───────────── Refresh content angles on existing seeded rows ─────────────
UPDATE `bug_dates_events` SET `description` = 'Cybersecurity Awareness Month. Content angle: secure coding, app security tips, client site hardening. Priority: Normal.'
WHERE `title` = 'Cybersecurity Awareness Month' AND `start_date` IN ('2026-10-01', '2027-10-01');

UPDATE `bug_dates_events` SET `description` = 'Kerala Formation Day (1956). Content angle: Malayalam-themed creative, ''built in Kerala'' pride post. Priority: Normal.'
WHERE `title` = 'Kerala Piravi' AND `recurrence_type` = 'yearly';

UPDATE `bug_dates_events` SET `description` = 'Festival of Lights (Lakshmi Puja, 2026). Content angle: greeting post, festive offer for client projects. Priority: Normal.'
WHERE `title` = 'Diwali' AND `start_date` = '2026-11-08';

UPDATE `bug_dates_events` SET `description` = 'Birth anniversary of Jawaharlal Nehru. Content angle: educational app or edtech showcase. Priority: Normal.'
WHERE `title` = 'Children''s Day' AND `recurrence_type` = 'yearly';

UPDATE `bug_dates_events` SET `description` = 'Samvidhan Divas — Constitution of India (1949); also US Thanksgiving week. Content angle: greeting post, international client outreach. Priority: Normal.'
WHERE `title` = 'Constitution Day' AND `recurrence_type` = 'yearly';

UPDATE `bug_dates_events` SET `description` = '[High] Global shopping day after US Thanksgiving (2026). Content angle: Q1 project slot offers, hosting and maintenance bundles. Priority: High.'
WHERE `title` = 'Black Friday' AND `start_date` = '2026-11-27';

UPDATE `bug_dates_events` SET `description` = 'UN day for inclusion of persons with disabilities. Content angle: accessibility in web and app design. Priority: Normal.'
WHERE `title` = 'International Day of Persons with Disabilities' AND `recurrence_type` = 'yearly';

UPDATE `bug_dates_events` SET `description` = 'Celebration of the birth of Jesus Christ. Content angle: greeting post. Priority: Normal.'
WHERE `title` = 'Christmas' AND `recurrence_type` = 'yearly';

UPDATE `bug_dates_events` SET `description` = 'Start of the Gregorian new year. Content angle: roadmap and tech trends for 2027. Priority: Normal.'
WHERE `title` = 'New Year''s Day' AND `recurrence_type` = 'yearly';

UPDATE `bug_dates_events` SET `description` = 'Birth anniversary of Swami Vivekananda. Content angle: team and young-talent feature. Priority: Normal.'
WHERE `title` = 'National Youth Day' AND `recurrence_type` = 'yearly';

UPDATE `bug_dates_events` SET `description` = 'Harvest festival as the sun enters Capricorn (2027). Observed Jan 14–15 by region. Content angle: greeting post. Priority: Normal.'
WHERE `title` = 'Makar Sankranti / Pongal' AND `start_date` = '2027-01-15';

UPDATE `bug_dates_events` SET `description` = 'National holiday — Constitution of India came into effect (1950). Content angle: greeting post, Digital India angle. Priority: Normal.'
WHERE `title` = 'Republic Day' AND `recurrence_type` = 'yearly';

UPDATE `bug_dates_events` SET `description` = 'Tentative date — start of the holy month of Ramadan 1448 AH; depends on moon sighting. Content angle: greeting, GCC content calendar adjustment. Priority: Normal.'
WHERE `title` = 'Ramadan begins' AND `start_date` = '2027-02-08';

UPDATE `bug_dates_events` SET `description` = 'Global day for a safer, better internet (2027). Content angle: web security checklist. Priority: Normal.'
WHERE `title` = 'Safer Internet Day' AND `start_date` = '2027-02-09';

UPDATE `bug_dates_events` SET `description` = 'Celebration of love and affection. Content angle: light, fun brand creative. Priority: Normal.'
WHERE `title` = 'Valentine''s Day' AND `recurrence_type` = 'yearly';

UPDATE `bug_dates_events` SET `description` = 'Discovery of the Raman Effect by Sir C.V. Raman (1928). Content angle: tech innovation post. Priority: Normal.'
WHERE `title` = 'National Science Day' AND `recurrence_type` = 'yearly';

UPDATE `bug_dates_events` SET `description` = 'Tentative date — great night of Lord Shiva (2027); confirm with the panchang. Content angle: greeting post. Priority: Normal.'
WHERE `title` = 'Maha Shivaratri' AND `start_date` = '2027-03-06';

UPDATE `bug_dates_events` SET `description` = 'Global day for women''s achievements and equality. Content angle: women in tech, team spotlight. Priority: Normal.'
WHERE `title` = 'International Women''s Day' AND `recurrence_type` = 'yearly';

UPDATE `bug_dates_events` SET `description` = 'Tentative date — end of Ramadan (1 Shawwal 1448 AH); depends on moon sighting. Content angle: greeting, GCC audience. Priority: Normal.'
WHERE `title` = 'Eid al-Fitr' AND `start_date` = '2027-03-10';

UPDATE `bug_dates_events` SET `description` = 'Tentative date — festival of colours (2027); Holika Dahan the evening before. Content angle: greeting post. Priority: Normal.'
WHERE `title` = 'Holi' AND `start_date` = '2027-03-22';

UPDATE `bug_dates_events` SET `description` = 'Commemorates the crucifixion of Jesus Christ (2027). Content angle: respectful pause in posting — no celebration tone. Priority: Normal.'
WHERE `title` = 'Good Friday' AND `start_date` = '2027-03-26';

UPDATE `bug_dates_events` SET `description` = 'Celebration of the resurrection of Jesus Christ (2027). Content angle: greeting post. Priority: Normal.'
WHERE `title` = 'Easter' AND `start_date` = '2027-03-28';

UPDATE `bug_dates_events` SET `description` = 'Birth anniversary of Dr. B.R. Ambedkar, architect of the Constitution. Content angle: greeting post. Priority: Normal.'
WHERE `title` = 'Ambedkar Jayanti' AND `recurrence_type` = 'yearly';

UPDATE `bug_dates_events` SET `description` = '[High] Tentative date — Kerala new year (Medam 1); confirm with the Kerala calendar. Content angle: Vishu greeting, ''new beginnings, new website'' campaign. Priority: High.'
WHERE `title` = 'Vishu' AND `start_date` = '2027-04-15';

UPDATE `bug_dates_events` SET `description` = 'Global day for environmental protection. Content angle: green hosting, sustainable tech. Priority: Normal.'
WHERE `title` = 'Earth Day' AND `recurrence_type` = 'yearly';

UPDATE `bug_dates_events` SET `description` = 'UNESCO World Book and Copyright Day. Content angle: developer reading list. Priority: Normal.'
WHERE `title` = 'World Book Day' AND `recurrence_type` = 'yearly';

UPDATE `bug_dates_events` SET `description` = 'International Workers'' Day. Content angle: team appreciation post. Priority: Normal.'
WHERE `title` = 'May Day / Labour Day' AND `recurrence_type` = 'yearly';

UPDATE `bug_dates_events` SET `description` = 'Second Sunday of May (2027). Content angle: greeting post. Priority: Normal.'
WHERE `title` = 'Mother''s Day' AND `start_date` = '2027-05-09';

UPDATE `bug_dates_events` SET `description` = 'Commemorates the Pokhran-II tests (1998). Content angle: tech achievements and client wins. Priority: Normal.'
WHERE `title` = 'National Technology Day' AND `recurrence_type` = 'yearly';

UPDATE `bug_dates_events` SET `description` = 'Tentative date — festival of sacrifice (10 Dhul Hijjah 1448 AH); depends on moon sighting. Content angle: GCC greeting. Priority: Normal.'
WHERE `title` IN ('Eid al-Adha (Bakrid)', 'Eid al-Adha') AND `start_date` = '2027-05-16';

UPDATE `bug_dates_events` SET `description` = 'ITU founding anniversary. Content angle: digital connectivity post. Priority: Normal.'
WHERE `title` = 'World Telecommunication & Information Society Day' AND `recurrence_type` = 'yearly';

UPDATE `bug_dates_events` SET `description` = 'Tentative date — 1 Muharram 1449 AH; depends on moon sighting. Content angle: optional greeting. Priority: Normal.'
WHERE `title` = 'Islamic New Year' AND `start_date` = '2027-06-06';

UPDATE `bug_dates_events` SET `description` = 'UN environment awareness day. Content angle: green tech post. Priority: Normal.'
WHERE `title` = 'World Environment Day' AND `recurrence_type` = 'yearly';

UPDATE `bug_dates_events` SET `description` = 'Third Sunday of June (2027). Content angle: greeting post. Priority: Normal.'
WHERE `title` = 'Father''s Day' AND `start_date` = '2027-06-20';

UPDATE `bug_dates_events` SET `description` = 'UN International Day of Yoga. Content angle: wellness and work-life balance. Priority: Normal.'
WHERE `title` = 'International Yoga Day' AND `recurrence_type` = 'yearly';

UPDATE `bug_dates_events` SET `description` = 'Celebrates the impact of social media. Content angle: social media plus web strategy. Priority: Normal.'
WHERE `title` IN ('Social Media Day', 'World Social Media Day') AND `recurrence_type` = 'yearly';

UPDATE `bug_dates_events` SET `description` = 'Birth anniversary of Dr. B.C. Roy; also Digital India anniversary week. Content angle: healthcare app showcase. Priority: Normal.'
WHERE `title` = 'National Doctors'' Day' AND `recurrence_type` = 'yearly';

UPDATE `bug_dates_events` SET `description` = 'UN day for youth skills and employment. Content angle: training and internship promotion. Priority: Normal.'
WHERE `title` = 'World Youth Skills Day' AND `recurrence_type` = 'yearly';

UPDATE `bug_dates_events` SET `description` = 'First Sunday of August in India (2027); also World Wide Web Day. Content angle: client appreciation. Priority: Normal.'
WHERE `title` = 'Friendship Day' AND `start_date` = '2027-08-01';

UPDATE `bug_dates_events` SET `description` = 'UN day for youth. Content angle: fresher spotlight. Priority: Normal.'
WHERE `title` = 'International Youth Day' AND `recurrence_type` = 'yearly';

UPDATE `bug_dates_events` SET `description` = 'National holiday — India''s independence (1947). Content angle: greeting post. Priority: Normal.'
WHERE `title` = 'Independence Day' AND `recurrence_type` = 'yearly';

UPDATE `bug_dates_events` SET `description` = 'Birth anniversary of hockey legend Major Dhyan Chand. Content angle: sports app showcase. Priority: Normal.'
WHERE `title` = 'National Sports Day' AND `recurrence_type` = 'yearly';

UPDATE `bug_dates_events` SET `description` = 'National holiday — birth anniversary of Mahatma Gandhi (1869). Content angle: greeting post. Priority: Normal.'
WHERE `title` = 'Gandhi Jayanti' AND `recurrence_type` = 'yearly';

UPDATE `bug_dates_events` SET `description` = 'UNESCO day honouring teachers worldwide. Content angle: learning and mentorship. Priority: Normal.'
WHERE `title` = 'World Teachers'' Day' AND `recurrence_type` = 'yearly';

UPDATE `bug_dates_events` SET `description` = 'Birth anniversary of Dr. A.P.J. Abdul Kalam. Content angle: student-focused content. Priority: Normal.'
WHERE `title` = 'World Students'' Day' AND `recurrence_type` = 'yearly';

UPDATE `bug_dates_events` SET `description` = 'National Unity Day — birth anniversary of Sardar Vallabhbhai Patel. Content angle: brand-neutral greeting post. Priority: Normal.'
WHERE `title` = 'National Unity Day' AND (`recurrence_type` = 'yearly' OR `start_date` = '2026-10-31');

-- ───────────── Insert missing marketing calendar rows ─────────────
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
SELECT v.title, v.description, v.category, v.recurrence_type, v.start_date, v.end_date,
  0, JSON_OBJECT('creative', true), 'company', 'approved', @seed_user
FROM (
  -- October 2026
  SELECT 'Cybersecurity Awareness Month' AS title,
         'All-month awareness campaign. Content angle: secure coding, app security tips, client site hardening. Priority: Normal.' AS description,
         'observance' AS category, 'none' AS recurrence_type,
         '2026-10-01' AS start_date, '2026-10-31' AS end_date
  UNION ALL SELECT 'World Internet Day',
         'Tech / industry day. Content angle: where the internet is heading for Kerala businesses. Priority: Normal.',
         'observance', 'yearly', '2026-10-29', NULL
  UNION ALL SELECT 'National Unity Day',
         'Rashtriya Ekta Diwas — birth anniversary of Sardar Vallabhbhai Patel. Content angle: brand-neutral greeting post. Priority: Normal.',
         'observance', 'yearly', '2026-10-31', NULL

  -- November 2026
  UNION ALL SELECT 'World Usability Day',
         'Tech / industry day. Content angle: UX tips, a before/after redesign case study. Priority: Normal.',
         'observance', 'none', '2026-11-12', NULL
  UNION ALL SELECT 'Cyber Monday',
         '[High] Global e-commerce day after Black Friday (2026). Content angle: website and app deals, SEO-plus-development combos. Priority: High.',
         'observance', 'none', '2026-11-30', NULL

  -- December 2026
  UNION ALL SELECT 'UAE National Day',
         'Festival / cultural — UAE National Day. Content angle: greeting for GCC audience. Priority: Normal.',
         'observance', 'yearly', '2026-12-02', NULL
  UNION ALL SELECT 'Computer Science Education Week',
         'Awareness / education week (CSEdWeek). Content angle: tech career tips, intern or fresher spotlight. Priority: Normal.',
         'observance', 'none', '2026-12-06', '2026-12-12'
  UNION ALL SELECT 'Qatar National Day',
         'Festival / cultural — Qatar National Day. Content angle: GCC greeting. Priority: Normal.',
         'observance', 'yearly', '2026-12-18', NULL

  -- January 2027
  UNION ALL SELECT 'National Startup Day',
         'Tech / industry — India National Startup Day. Content angle: startup MVP development offers. Priority: Normal.',
         'observance', 'yearly', '2027-01-16', NULL
  UNION ALL SELECT 'Data Privacy Day',
         '[High] Tech / industry — Data Privacy Day. Content angle: privacy-by-design, DPDP Act compliance for apps. Priority: High.',
         'observance', 'yearly', '2027-01-28', NULL

  -- February 2027
  UNION ALL SELECT 'Union Budget',
         'Commerce — Union Budget of India (2027). Content angle: what the budget means for IT and MSMEs. Priority: Normal.',
         'observance', 'none', '2027-02-01', NULL
  UNION ALL SELECT 'Kuwait National Day',
         'Festival / cultural — Kuwait National Day. Content angle: GCC greeting. Priority: Normal.',
         'observance', 'yearly', '2027-02-25', NULL

  -- March 2027
  UNION ALL SELECT 'Pi Day',
         'Tech / industry — π Day (3.14). Content angle: fun developer content. Priority: Normal.',
         'observance', 'yearly', '2027-03-14', NULL

  -- April 2027
  UNION ALL SELECT 'World IP Day',
         'Tech / industry — World Intellectual Property Day. Content angle: source code ownership, IP in client contracts. Priority: Normal.',
         'observance', 'yearly', '2027-04-26', NULL

  -- May 2027
  UNION ALL SELECT 'World Password Day',
         'Tech / industry — World Password Day (first Thursday of May). Content angle: security awareness. Priority: Normal.',
         'observance', 'none', '2027-05-06', NULL
  UNION ALL SELECT 'Global Accessibility Awareness Day',
         '[High] Tech / industry — GAAD (third Thursday of May). Content angle: accessibility audit offer. Priority: High.',
         'observance', 'none', '2027-05-20', NULL

  -- June 2027
  UNION ALL SELECT 'Vayana Dinam',
         'Kerala Reading Day (Vayana Dinam). Content angle: developer or learning content. Priority: Normal.',
         'observance', 'yearly', '2027-06-19', NULL

  -- July 2027
  UNION ALL SELECT 'System Administrator Appreciation Day',
         'Tech / industry — SysAdmin Day (last Friday of July). Content angle: Dev and ops team appreciation. Priority: Normal.',
         'observance', 'none', '2027-07-30', NULL

  -- August 2027
  UNION ALL SELECT 'World Wide Web Day',
         'Tech / industry — World Wide Web Day (with Friendship Day week). Content angle: client appreciation. Priority: Normal.',
         'observance', 'yearly', '2027-08-01', NULL
  UNION ALL SELECT 'Janmashtami',
         'Tentative date (Hindu calendar) — birth of Lord Krishna. Content angle: greeting post. Priority: Normal.',
         'observance', 'none', '2027-08-25', NULL

  -- September 2027
  UNION ALL SELECT 'Onam 2027',
         '[High] Tentative (Malayalam calendar) — Atham to Thiruvonam. Content angle: biggest Kerala campaign — Onam offers, team celebration, GCC Malayali audience. Priority: High.',
         'observance', 'none', '2027-09-03', '2027-09-12'
  UNION ALL SELECT 'Thiruvonam 2027',
         '[High] Tentative (Malayalam calendar) — Thiruvonam peak day. Content angle: Onam offers, team sadya, GCC Malayali audience. Priority: High.',
         'holiday', 'none', '2027-09-12', NULL
  UNION ALL SELECT 'Ganesh Chaturthi 2027',
         'Tentative date (Hindu calendar) — Lord Ganesha festival. Content angle: greeting post. Priority: Normal.',
         'observance', 'none', '2027-09-04', NULL
  UNION ALL SELECT 'Teachers'' Day',
         'Birth anniversary of Dr. Sarvepalli Radhakrishnan. Content angle: edtech and mentor appreciation. Priority: Normal.',
         'observance', 'yearly', '2027-09-05', NULL
  UNION ALL SELECT 'Programmers'' Day',
         '[High] Day 256 of the year (13 Sep in non-leap years). Content angle: developer humour, team features. Priority: High.',
         'observance', 'none', '2027-09-13', NULL
  UNION ALL SELECT 'Engineers'' Day',
         'Birth anniversary of Sir M. Visvesvaraya. Content angle: engineering and innovation post. Priority: Normal.',
         'observance', 'yearly', '2027-09-15', NULL
  UNION ALL SELECT 'Saudi National Day',
         'Festival / cultural — Saudi National Day. Content angle: GCC greeting. Priority: Normal.',
         'observance', 'yearly', '2027-09-23', NULL
  UNION ALL SELECT 'World Tourism Day',
         'Awareness / education — World Tourism Day. Content angle: tourism and travel app showcase. Priority: Normal.',
         'observance', 'yearly', '2027-09-27', NULL

  -- October 2027
  UNION ALL SELECT 'Cybersecurity Awareness Month',
         'All-month awareness campaign. Content angle: security series. Priority: Normal.',
         'observance', 'none', '2027-10-01', '2027-10-31'
  UNION ALL SELECT 'Ayudha Pooja / Vijayadashami',
         '[High] Tentative (Hindu calendar) — Ayudha Pooja and Vijayadashami / Vidyarambham (2027). Content angle: Ayudha Pooja greeting, ''tools of the trade'' team post, Vidyarambham greeting. Priority: High.',
         'observance', 'none', '2027-10-19', '2027-10-20'
  UNION ALL SELECT 'Diwali 2027',
         'Tentative date — Festival of Lights (2027), just outside the Oct planning window; plan creatives ahead. Content angle: greeting post, festive offer. Priority: Normal.',
         'observance', 'none', '2027-10-29', NULL
) AS v
WHERE @seed_user IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM bug_dates_events e
    WHERE LOWER(e.title) = LOWER(v.title)
      AND (e.start_date = v.start_date OR e.recurrence_type = 'yearly')
  );
