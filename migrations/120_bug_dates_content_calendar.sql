-- BugDates content calendar: Oct 2026 → Sep 2027 observances, festivals and national holidays.
-- Fixed-date days are `yearly`; lunar / movable festivals are one-off rows for their 2026–27 date.
-- Moon-sighting / panchang dependent dates are marked "Tentative" in the description — confirm
-- against the official holiday list and edit the event in BugDates if the date shifts.
-- National holidays use category `holiday` with is_office_closed = 0: office closure drives
-- attendance + checkout bypass, so an admin turns it on per event deliberately.
-- Safe to re-run: skips any title that already exists for the same date (or as a yearly event).

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
  -- ───────────── October ─────────────
  SELECT 'International Coffee Day' AS title,
         'Global celebration of coffee and the farmers behind it. Content angle: team coffee-break moments, "fuelled by coffee" build culture.' AS description,
         'observance' AS category, 'yearly' AS recurrence_type, '2026-10-01' AS start_date
  UNION ALL SELECT 'Gandhi Jayanti',
         'National holiday — birth anniversary of Mahatma Gandhi (1869). Content angle: truth, simplicity and non-violence; respectful tribute post, no promotional tone.',
         'holiday', 'yearly', '2026-10-02'
  UNION ALL SELECT 'World Animal Day',
         'Raises the status of animals and welfare standards worldwide. Content angle: office pets, kindness to animals, adoption awareness.',
         'observance', 'yearly', '2026-10-04'
  UNION ALL SELECT 'World Teachers'' Day',
         'UNESCO day honouring teachers worldwide. Content angle: mentors in tech, thank-you notes to the people who taught us to code.',
         'observance', 'yearly', '2026-10-05'
  UNION ALL SELECT 'Indian Air Force Day',
         'Anniversary of the Indian Air Force (est. 1932). Content angle: salute to the IAF — precision, discipline and teamwork.',
         'observance', 'yearly', '2026-10-08'
  UNION ALL SELECT 'World Mental Health Day',
         'WHO awareness day for mental health. Content angle: healthy work habits, breaks, asking for help; supportive, non-salesy tone.',
         'observance', 'yearly', '2026-10-10'
  UNION ALL SELECT 'International Day of the Girl Child',
         'UN day for girls'' rights and empowerment. Content angle: women in tech, education and equal opportunity.',
         'observance', 'yearly', '2026-10-11'
  UNION ALL SELECT 'Navrathri',
         'Sharad Navaratri begins — nine nights of the goddess Durga (Oct 11–19, 2026). Content angle: festive greetings, colours of the nine days.',
         'observance', 'none', '2026-10-11'
  UNION ALL SELECT 'World Students'' Day',
         'Birth anniversary of Dr. A.P.J. Abdul Kalam, celebrated as World Students'' Day. Content angle: Kalam quotes, curiosity and lifelong learning.',
         'observance', 'yearly', '2026-10-15'
  UNION ALL SELECT 'World Food Day',
         'FAO day for food security and zero hunger. Content angle: team lunch culture, reducing food waste.',
         'observance', 'yearly', '2026-10-16'
  UNION ALL SELECT 'Dussehra / Vijayadashami',
         'Victory of good over evil; Vidyarambham in Kerala — children begin learning. Content angle: new beginnings, "start something new" messaging.',
         'observance', 'none', '2026-10-20'
  UNION ALL SELECT 'United Nations Day',
         'Anniversary of the UN Charter (1945). Content angle: global collaboration, working across borders.',
         'observance', 'yearly', '2026-10-24'
  UNION ALL SELECT 'Halloween',
         'Costume and spooky-season celebration. Content angle: "scariest bug we ever fixed", playful dev humour.',
         'observance', 'yearly', '2026-10-31'

  -- ───────────── November ─────────────
  UNION ALL SELECT 'Kerala Piravi',
         'Kerala Formation Day (1956). Content angle: Kerala pride, Malayalam greetings, God''s Own Country visuals.',
         'observance', 'yearly', '2026-11-01'
  UNION ALL SELECT 'Diwali',
         'Festival of Lights (Lakshmi Puja, 2026). Content angle: lights, sweets and prosperity greetings; festive offer creatives if applicable.',
         'observance', 'none', '2026-11-08'
  UNION ALL SELECT 'National Education Day',
         'Birth anniversary of Maulana Abul Kalam Azad, India''s first Education Minister. Content angle: learning culture and upskilling.',
         'observance', 'yearly', '2026-11-11'
  UNION ALL SELECT 'Children''s Day',
         'Birth anniversary of Jawaharlal Nehru; also World Diabetes Day. Content angle: childhood dreams of the team, the inner child in every builder.',
         'observance', 'yearly', '2026-11-14'
  UNION ALL SELECT 'International Men''s Day',
         'Celebrates men''s positive contributions and wellbeing. Content angle: men''s health awareness, role models.',
         'observance', 'yearly', '2026-11-19'
  UNION ALL SELECT 'Guru Nanak Jayanti',
         'Gurpurab — birth anniversary of Guru Nanak Dev Ji (Kartik Purnima, 2026). Content angle: equality, service and humility greetings.',
         'observance', 'none', '2026-11-24'
  UNION ALL SELECT 'Constitution Day',
         'Samvidhan Divas — adoption of the Constitution of India (1949). Content angle: rights, duties and the Preamble.',
         'observance', 'yearly', '2026-11-26'
  UNION ALL SELECT 'Black Friday',
         'Global shopping day after US Thanksgiving (2026). Content angle: client offers and campaign launches; plan creatives a week ahead.',
         'observance', 'none', '2026-11-27'

  -- ───────────── December ─────────────
  UNION ALL SELECT 'World AIDS Day',
         'Global HIV/AIDS awareness day. Content angle: red ribbon awareness, stigma-free messaging.',
         'observance', 'yearly', '2026-12-01'
  UNION ALL SELECT 'International Day of Persons with Disabilities',
         'UN day for inclusion of persons with disabilities. Content angle: accessible design — every user matters.',
         'observance', 'yearly', '2026-12-03'
  UNION ALL SELECT 'Indian Navy Day',
         'Commemorates Operation Trident (1971). Content angle: salute to the Indian Navy.',
         'observance', 'yearly', '2026-12-04'
  UNION ALL SELECT 'Armed Forces Flag Day',
         'Honours India''s armed forces and veterans. Content angle: gratitude and support for soldiers'' families.',
         'observance', 'yearly', '2026-12-07'
  UNION ALL SELECT 'Human Rights Day',
         'Adoption of the Universal Declaration of Human Rights (1948). Content angle: dignity and equality.',
         'observance', 'yearly', '2026-12-10'
  UNION ALL SELECT 'National Energy Conservation Day',
         'Awareness of energy efficiency in India. Content angle: green computing, switching off idle devices.',
         'observance', 'yearly', '2026-12-14'
  UNION ALL SELECT 'National Mathematics Day',
         'Birth anniversary of Srinivasa Ramanujan. Content angle: the maths behind code and algorithms.',
         'observance', 'yearly', '2026-12-22'
  UNION ALL SELECT 'Kisan Diwas',
         'National Farmers'' Day — birth anniversary of Chaudhary Charan Singh. Content angle: gratitude to farmers.',
         'observance', 'yearly', '2026-12-23'
  UNION ALL SELECT 'Christmas',
         'Celebration of the birth of Jesus Christ. Content angle: warm season''s greetings, year-end gratitude to clients and team.',
         'observance', 'yearly', '2026-12-25'
  UNION ALL SELECT 'New Year''s Eve',
         'Last day of the year. Content angle: year-in-review, shipped highlights, thank-you reel.',
         'observance', 'yearly', '2026-12-31'

  -- ───────────── January ─────────────
  UNION ALL SELECT 'New Year''s Day',
         'Start of the Gregorian new year. Content angle: goals, fresh starts and New Year greetings.',
         'observance', 'yearly', '2027-01-01'
  UNION ALL SELECT 'Pravasi Bharatiya Divas',
         'Honours the overseas Indian community (Gandhi''s return from South Africa, 1915). Content angle: NRI clients and global Malayali diaspora.',
         'observance', 'yearly', '2027-01-09'
  UNION ALL SELECT 'National Youth Day',
         'Birth anniversary of Swami Vivekananda. Content angle: young talent, interns and youth energy in the team.',
         'observance', 'yearly', '2027-01-12'
  UNION ALL SELECT 'Makar Sankranti / Pongal',
         'Harvest festival as the sun enters Capricorn (2027). Observed Jan 14 or 15 by region. Content angle: harvest, gratitude and kites.',
         'observance', 'none', '2027-01-15'
  UNION ALL SELECT 'Indian Army Day',
         'Anniversary of the first Indian Commander-in-Chief (1949). Content angle: salute to the Indian Army.',
         'observance', 'yearly', '2027-01-15'
  UNION ALL SELECT 'National Girl Child Day',
         'Awareness of the rights and education of the girl child in India. Content angle: STEM opportunities for girls.',
         'observance', 'yearly', '2027-01-24'
  UNION ALL SELECT 'National Voters'' Day',
         'Founding of the Election Commission of India; also National Tourism Day. Content angle: every vote counts.',
         'observance', 'yearly', '2027-01-25'
  UNION ALL SELECT 'Republic Day',
         'National holiday — the Constitution of India came into effect (1950). Content angle: tricolour tribute, pride in India.',
         'holiday', 'yearly', '2027-01-26'
  UNION ALL SELECT 'Martyrs'' Day',
         'Shaheed Diwas — death anniversary of Mahatma Gandhi. Content angle: respectful tribute only.',
         'observance', 'yearly', '2027-01-30'

  -- ───────────── February ─────────────
  UNION ALL SELECT 'World Cancer Day',
         'Global cancer awareness day. Content angle: early detection and support messaging.',
         'observance', 'yearly', '2027-02-04'
  UNION ALL SELECT 'Ramadan begins',
         'Tentative date — start of the holy month of Ramadan 1448 AH; depends on moon sighting. Content angle: Ramadan Kareem greetings.',
         'observance', 'none', '2027-02-08'
  UNION ALL SELECT 'Safer Internet Day',
         'Global day for a safer, better internet (2027). Content angle: security tips, passwords, phishing awareness — strong fit for a software team.',
         'observance', 'none', '2027-02-09'
  UNION ALL SELECT 'World Radio Day',
         'UNESCO day celebrating radio. Content angle: podcasts and voice content.',
         'observance', 'yearly', '2027-02-13'
  UNION ALL SELECT 'Valentine''s Day',
         'Celebration of love and affection. Content angle: "apps we love", client love, playful creatives.',
         'observance', 'yearly', '2027-02-14'
  UNION ALL SELECT 'International Mother Language Day',
         'UNESCO day for linguistic diversity. Content angle: Malayalam pride, multilingual products.',
         'observance', 'yearly', '2027-02-21'
  UNION ALL SELECT 'National Science Day',
         'Discovery of the Raman Effect by Sir C.V. Raman (1928). Content angle: science and innovation behind products.',
         'observance', 'yearly', '2027-02-28'

  -- ───────────── March ─────────────
  UNION ALL SELECT 'Maha Shivaratri',
         'Tentative date — great night of Lord Shiva (2027); confirm with the panchang. Content angle: devotional greetings.',
         'observance', 'none', '2027-03-06'
  UNION ALL SELECT 'International Women''s Day',
         'Global day for women''s achievements and equality. Content angle: spotlight the women of the team.',
         'observance', 'yearly', '2027-03-08'
  UNION ALL SELECT 'Eid al-Fitr',
         'Tentative date — end of Ramadan (1 Shawwal 1448 AH); depends on moon sighting and may fall a day earlier in Kerala. Content angle: Eid Mubarak greetings.',
         'observance', 'none', '2027-03-10'
  UNION ALL SELECT 'World Consumer Rights Day',
         'Awareness of consumer rights. Content angle: transparent pricing and user trust.',
         'observance', 'yearly', '2027-03-15'
  UNION ALL SELECT 'International Day of Happiness',
         'UN day for happiness and wellbeing; also World Sparrow Day. Content angle: what makes the team happy at work.',
         'observance', 'yearly', '2027-03-20'
  UNION ALL SELECT 'World Water Day',
         'UN day for freshwater awareness. Content angle: save water, sustainability.',
         'observance', 'yearly', '2027-03-22'
  UNION ALL SELECT 'Holi',
         'Tentative date — festival of colours (2027); Holika Dahan the evening before. Content angle: colourful greetings and team photos.',
         'observance', 'none', '2027-03-22'
  UNION ALL SELECT 'Good Friday',
         'Commemorates the crucifixion of Jesus Christ (2027). Content angle: solemn, respectful message only — no celebration tone.',
         'observance', 'none', '2027-03-26'
  UNION ALL SELECT 'Easter',
         'Celebration of the resurrection of Jesus Christ (2027). Content angle: hope and renewal greetings.',
         'observance', 'none', '2027-03-28'
  UNION ALL SELECT 'World Backup Day',
         'Reminder to back up your data. Content angle: "back up before April Fools''" — perfect tech-brand post.',
         'observance', 'yearly', '2027-03-31'

  -- ───────────── April ─────────────
  UNION ALL SELECT 'April Fools'' Day',
         'Day of pranks and humour. Content angle: harmless product joke, clearly labelled.',
         'observance', 'yearly', '2027-04-01'
  UNION ALL SELECT 'World Autism Awareness Day',
         'UN autism awareness day. Content angle: inclusive, accessible design.',
         'observance', 'yearly', '2027-04-02'
  UNION ALL SELECT 'World Health Day',
         'WHO founding anniversary. Content angle: desk ergonomics, screen breaks, health at work.',
         'observance', 'yearly', '2027-04-07'
  UNION ALL SELECT 'Ambedkar Jayanti',
         'Birth anniversary of Dr. B.R. Ambedkar, architect of the Constitution. Content angle: equality and justice tribute.',
         'observance', 'yearly', '2027-04-14'
  UNION ALL SELECT 'Vishu',
         'Tentative date — Kerala new year (Medam 1); confirm with the Kerala calendar. Content angle: Vishukkani, Vishu Kaineettam greetings.',
         'observance', 'none', '2027-04-15'
  UNION ALL SELECT 'Earth Day',
         'Global day for environmental protection. Content angle: sustainability and green tech.',
         'observance', 'yearly', '2027-04-22'
  UNION ALL SELECT 'World Book Day',
         'UNESCO World Book and Copyright Day. Content angle: team book recommendations.',
         'observance', 'yearly', '2027-04-23'

  -- ───────────── May ─────────────
  UNION ALL SELECT 'May Day / Labour Day',
         'International Workers'' Day. Content angle: celebrate the people who build our products.',
         'observance', 'yearly', '2027-05-01'
  UNION ALL SELECT 'World Press Freedom Day',
         'UN day for press freedom. Content angle: honest communication and transparency.',
         'observance', 'yearly', '2027-05-03'
  UNION ALL SELECT 'Mother''s Day',
         'Second Sunday of May (2027). Content angle: tributes to mothers; team stories.',
         'observance', 'none', '2027-05-09'
  UNION ALL SELECT 'National Technology Day',
         'Commemorates the Pokhran-II tests (1998). Content angle: Indian tech achievements and our own stack — key day for a software company.',
         'observance', 'yearly', '2027-05-11'
  UNION ALL SELECT 'International Day of Families',
         'UN day celebrating families. Content angle: work-life balance, team families.',
         'observance', 'yearly', '2027-05-15'
  UNION ALL SELECT 'Eid al-Adha (Bakrid)',
         'Tentative date — festival of sacrifice (10 Dhul Hijjah 1448 AH); depends on moon sighting. Content angle: Eid Mubarak greetings.',
         'observance', 'none', '2027-05-16'
  UNION ALL SELECT 'World Telecommunication & Information Society Day',
         'ITU founding anniversary. Content angle: connectivity, digital inclusion.',
         'observance', 'yearly', '2027-05-17'
  UNION ALL SELECT 'World No Tobacco Day',
         'WHO anti-tobacco awareness day. Content angle: healthy habits.',
         'observance', 'yearly', '2027-05-31'

  -- ───────────── June ─────────────
  UNION ALL SELECT 'World Environment Day',
         'UN environment awareness day. Content angle: green office, plant a tree.',
         'observance', 'yearly', '2027-06-05'
  UNION ALL SELECT 'Islamic New Year',
         'Tentative date — 1 Muharram 1449 AH; depends on moon sighting. Content angle: Hijri new year greetings.',
         'observance', 'none', '2027-06-06'
  UNION ALL SELECT 'Father''s Day',
         'Third Sunday of June (2027). Content angle: tributes to fathers; team stories.',
         'observance', 'none', '2027-06-20'
  UNION ALL SELECT 'International Yoga Day',
         'UN International Day of Yoga; also World Music Day. Content angle: desk yoga and stretches for developers.',
         'observance', 'yearly', '2027-06-21'
  UNION ALL SELECT 'Social Media Day',
         'Celebrates the impact of social media. Content angle: behind-the-scenes, best-performing posts — core day for the creative team.',
         'observance', 'yearly', '2027-06-30'

  -- ───────────── July ─────────────
  UNION ALL SELECT 'National Doctors'' Day',
         'Birth anniversary of Dr. B.C. Roy; also Chartered Accountants'' Day. Content angle: thanks to doctors.',
         'observance', 'yearly', '2027-07-01'
  UNION ALL SELECT 'World Population Day',
         'UN population awareness day. Content angle: scale — building for millions of users.',
         'observance', 'yearly', '2027-07-11'
  UNION ALL SELECT 'World Youth Skills Day',
         'UN day for youth skills and employment. Content angle: internships, learning paths.',
         'observance', 'yearly', '2027-07-15'
  UNION ALL SELECT 'World Emoji Day',
         'Celebrates emoji. Content angle: team emoji poll, playful engagement post.',
         'observance', 'yearly', '2027-07-17'
  UNION ALL SELECT 'Kargil Vijay Diwas',
         'Victory in the Kargil War (1999). Content angle: tribute to soldiers.',
         'observance', 'yearly', '2027-07-26'
  UNION ALL SELECT 'International Tiger Day',
         'Global tiger conservation day. Content angle: wildlife conservation.',
         'observance', 'yearly', '2027-07-29'

  -- ───────────── August ─────────────
  UNION ALL SELECT 'Friendship Day',
         'First Sunday of August in India (2027). Content angle: work friends, team bonding.',
         'observance', 'none', '2027-08-01'
  UNION ALL SELECT 'International Youth Day',
         'UN day for youth. Content angle: young builders on the team.',
         'observance', 'yearly', '2027-08-12'
  UNION ALL SELECT 'Independence Day',
         'National holiday — India''s independence (1947). Content angle: tricolour tribute, freedom to build.',
         'holiday', 'yearly', '2027-08-15'
  UNION ALL SELECT 'World Photography Day',
         'Celebrates photography. Content angle: best team and office shots.',
         'observance', 'yearly', '2027-08-19'
  UNION ALL SELECT 'National Sports Day',
         'Birth anniversary of hockey legend Major Dhyan Chand. Content angle: team sports and fitness.',
         'observance', 'yearly', '2027-08-29'

  -- ───────────── September (adds to the 095 seed) ─────────────
  UNION ALL SELECT 'Hindi Diwas',
         'Adoption of Hindi as an official language (1949). Content angle: language diversity of India.',
         'observance', 'yearly', '2026-09-14'
  UNION ALL SELECT 'World Heart Day',
         'Global cardiovascular health day. Content angle: heart-healthy habits for desk workers.',
         'observance', 'yearly', '2026-09-29'
) AS v
WHERE @seed_user IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM bug_dates_events e
    WHERE LOWER(e.title) = LOWER(v.title)
      AND (e.start_date = v.start_date OR e.recurrence_type = 'yearly')
  );
