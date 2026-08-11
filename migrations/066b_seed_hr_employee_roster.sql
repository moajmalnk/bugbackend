-- =============================================================================
-- BugRicer Seed 066b — HR employee roster (re-runnable)
-- =============================================================================
-- Prerequisites: run 066_user_hr_employment.sql first.
--
-- HOW TO USE
-- 1. Replace each REPLACE_ME_* email below with the real BugRicer login email.
-- 2. Manager emails (@mgr_ajmal_nk, @mgr_fahis, @mgr_lubaba) must resolve to
--    existing users (used for reports_to_user_id).
-- 3. Re-run safely — UPDATEs are keyed by email.
--
-- Cipher reminder: CODO-{join MMYY}-{dob MMYY} with K1 L2 M3 N4 O5 P6 Q7 R8 S9 T0
-- =============================================================================

SET @db := DATABASE();

-- ---------------------------------------------------------------------------
-- Manager lookups (fill emails)
-- ---------------------------------------------------------------------------
SET @mgr_ajmal_nk_email := 'REPLACE_ME_ajmal_nk@codo.example';
SET @mgr_fahis_email    := 'REPLACE_ME_fahis@codo.example';
SET @mgr_lubaba_email   := 'REPLACE_ME_lubaba@codo.example';

SET @mgr_ajmal_nk := (SELECT id FROM users WHERE email = @mgr_ajmal_nk_email LIMIT 1);
SET @mgr_fahis    := (SELECT id FROM users WHERE email = @mgr_fahis_email LIMIT 1);
SET @mgr_lubaba   := (SELECT id FROM users WHERE email = @mgr_lubaba_email LIMIT 1);

-- Helper: upsert onboarding demographics
-- (executed per employee via INSERT … ON DUPLICATE KEY is not available without unique on user_id alone —
--  table has UNIQUE user_id from migration 059)

-- ---------------------------------------------------------------------------
-- Procedure-style pattern: UPDATE users + upsert demographics per email
-- ---------------------------------------------------------------------------

-- Mohammed Ajmal NK | CODO-TPLN-KLTK | DOB 2001-12-30 | Join 2024-06-05 | Founder
SET @e := 'REPLACE_ME_mohammed_ajmal_nk@codo.example';
UPDATE users SET
  joining_date = '2024-06-05',
  employee_code = 'CODO-TPLN-KLTK',
  job_title = 'Founder, Python Developer',
  job_level = 'Founder',
  department = NULL,
  reports_to_user_id = NULL,
  contract_type = 'full_time',
  offer_letter_issued = 1,
  probation_end_date = NULL,
  employment_status = 'active',
  account_active = 1
WHERE email = @e;
INSERT INTO user_onboarding_details (user_id, date_of_birth, gender, marital_status)
SELECT id, '2001-12-30', 'male', 'single' FROM users WHERE email = @e
ON DUPLICATE KEY UPDATE
  date_of_birth = VALUES(date_of_birth),
  gender = VALUES(gender),
  marital_status = VALUES(marital_status);

-- Mohammed Ajmal P | CODO-TPLN-TSKT | DOB 2001-09-19 | Join 2024-06-05
SET @e := 'REPLACE_ME_mohammed_ajmal_p@codo.example';
UPDATE users SET
  joining_date = '2024-06-05',
  employee_code = 'CODO-TPLN-TSKT',
  job_title = 'Project Coordinator, Tester',
  job_level = 'Senior',
  department = 'CODO Agency - Development',
  reports_to_user_id = @mgr_ajmal_nk,
  contract_type = 'full_time',
  offer_letter_issued = 1,
  probation_end_date = NULL,
  employment_status = 'active',
  account_active = 1
WHERE email = @e;
INSERT INTO user_onboarding_details (user_id, date_of_birth, gender, marital_status)
SELECT id, '2001-09-19', 'male', 'single' FROM users WHERE email = @e
ON DUPLICATE KEY UPDATE
  date_of_birth = VALUES(date_of_birth), gender = VALUES(gender), marital_status = VALUES(marital_status);

-- Shihal | CODO-TPLN-TPTN | DOB 2004-06-01 | Join 2024-06-19 | Inactive
SET @e := 'REPLACE_ME_shihal@codo.example';
UPDATE users SET
  joining_date = '2024-06-19',
  employee_code = 'CODO-TPLN-TPTN',
  job_title = 'Graphic Designer',
  job_level = 'Head',
  department = 'CODO Agency - Creative',
  reports_to_user_id = NULL,
  contract_type = 'full_time',
  offer_letter_issued = 1,
  probation_end_date = NULL,
  employment_status = 'inactive',
  account_active = 0
WHERE email = @e;
INSERT INTO user_onboarding_details (user_id, date_of_birth, gender, marital_status)
SELECT id, '2004-06-01', 'male', 'single' FROM users WHERE email = @e
ON DUPLICATE KEY UPDATE
  date_of_birth = VALUES(date_of_birth), gender = VALUES(gender), marital_status = VALUES(marital_status);

-- Titty Joseph | CODO-KKLN-KLTK | DOB 2001-12-06 | Join 2024-11-11 | Remote
SET @e := 'REPLACE_ME_titty_joseph@codo.example';
UPDATE users SET
  joining_date = '2024-11-11',
  employee_code = 'CODO-KKLN-KLTK',
  job_title = 'WordPress Developer',
  job_level = 'Freelancer',
  department = 'CODO Agency - Development',
  reports_to_user_id = @mgr_ajmal_nk,
  contract_type = 'remote',
  offer_letter_issued = 1,
  probation_end_date = NULL,
  employment_status = 'active',
  account_active = 1
WHERE email = @e;
INSERT INTO user_onboarding_details (user_id, date_of_birth, gender, marital_status)
SELECT id, '2001-12-06', 'female', 'single' FROM users WHERE email = @e
ON DUPLICATE KEY UPDATE
  date_of_birth = VALUES(date_of_birth), gender = VALUES(gender), marital_status = VALUES(marital_status);

-- Abdul Raoof | CODO-TKLO-TNTL | DOB 2002-04-21 | Join 2025-01-20
SET @e := 'REPLACE_ME_abdul_raoof@codo.example';
UPDATE users SET
  joining_date = '2025-01-20',
  employee_code = 'CODO-TKLO-TNTL',
  job_title = 'Frontend Developer',
  job_level = 'Senior',
  department = 'CODO Agency - Development',
  reports_to_user_id = @mgr_fahis,
  contract_type = 'full_time',
  offer_letter_issued = 1,
  probation_end_date = NULL,
  employment_status = 'active',
  account_active = 1
WHERE email = @e;
INSERT INTO user_onboarding_details (user_id, date_of_birth, gender, marital_status)
SELECT id, '2002-04-21', 'male', 'single' FROM users WHERE email = @e
ON DUPLICATE KEY UPDATE
  date_of_birth = VALUES(date_of_birth), gender = VALUES(gender), marital_status = VALUES(marital_status);

-- Aboobacker Fahise | CODO-KLLN-KLSR | DOB 1998-12-26 | Join 2024-12-04
SET @e := 'REPLACE_ME_fahis@codo.example';
UPDATE users SET
  joining_date = '2024-12-04',
  employee_code = 'CODO-KLLN-KLSR',
  job_title = 'Application Developer',
  job_level = 'Senior',
  department = 'CODO Agency - Development',
  reports_to_user_id = @mgr_lubaba,
  contract_type = 'full_time',
  offer_letter_issued = 1,
  probation_end_date = NULL,
  employment_status = 'active',
  account_active = 1
WHERE email = @e;
INSERT INTO user_onboarding_details (user_id, date_of_birth, gender, marital_status)
SELECT id, '1998-12-26', 'male', 'single' FROM users WHERE email = @e
ON DUPLICATE KEY UPDATE
  date_of_birth = VALUES(date_of_birth), gender = VALUES(gender), marital_status = VALUES(marital_status);

-- Ayisha Selin E | CODO-TOLN-TQTM | DOB 2003-07-14 | Join 2024-05-09 | Inactive Remote
SET @e := 'REPLACE_ME_ayisha_selin@codo.example';
UPDATE users SET
  joining_date = '2024-05-09',
  employee_code = 'CODO-TOLN-TQTM',
  job_title = 'PHP Developer Intern',
  job_level = 'Intern',
  department = 'CODO Agency - Development',
  reports_to_user_id = NULL,
  contract_type = 'remote',
  offer_letter_issued = 1,
  probation_end_date = NULL,
  employment_status = 'inactive',
  account_active = 0
WHERE email = @e;
INSERT INTO user_onboarding_details (user_id, date_of_birth, gender, marital_status)
SELECT id, '2003-07-14', 'female', 'single' FROM users WHERE email = @e
ON DUPLICATE KEY UPDATE
  date_of_birth = VALUES(date_of_birth), gender = VALUES(gender), marital_status = VALUES(marital_status);

-- Ayshath Lubaba K A | CODO-KTLN-TMSO | DOB 1995-03-05 | Join 2024-10-23
SET @e := 'REPLACE_ME_lubaba@codo.example';
UPDATE users SET
  joining_date = '2024-10-23',
  employee_code = 'CODO-KTLN-TMSO',
  job_title = 'Fullstack Developer',
  job_level = 'Senior',
  department = 'CODO Agency - Development',
  reports_to_user_id = @mgr_ajmal_nk,
  contract_type = 'full_time',
  offer_letter_issued = 1,
  probation_end_date = NULL,
  employment_status = 'active',
  account_active = 1
WHERE email = @e;
INSERT INTO user_onboarding_details (user_id, date_of_birth, gender, marital_status)
SELECT id, '1995-03-05', 'female', 'married' FROM users WHERE email = @e
ON DUPLICATE KEY UPDATE
  date_of_birth = VALUES(date_of_birth), gender = VALUES(gender), marital_status = VALUES(marital_status);

-- Fathima Farha | CODO-TMLO-TRTL | DOB 2002-08-30 | Join 2025-03-11 | Inactive Remote
SET @e := 'REPLACE_ME_fathima_farha@codo.example';
UPDATE users SET
  joining_date = '2025-03-11',
  employee_code = 'CODO-TMLO-TRTL',
  job_title = 'Web Tutor & Developer',
  job_level = 'Freelancer',
  department = 'CODO Academy',
  reports_to_user_id = @mgr_ajmal_nk,
  contract_type = 'remote',
  offer_letter_issued = 1,
  probation_end_date = NULL,
  employment_status = 'inactive',
  account_active = 0
WHERE email = @e;
INSERT INTO user_onboarding_details (user_id, date_of_birth, gender, marital_status)
SELECT id, '2002-08-30', 'female', 'married' FROM users WHERE email = @e
ON DUPLICATE KEY UPDATE
  date_of_birth = VALUES(date_of_birth), gender = VALUES(gender), marital_status = VALUES(marital_status);

-- Ashida | CODO-TMLO-TNTR | DOB 2008-04-29 | Join 2025-03-25 | Inactive Remote Contract
SET @e := 'REPLACE_ME_ashida@codo.example';
UPDATE users SET
  joining_date = '2025-03-25',
  employee_code = 'CODO-TMLO-TNTR',
  job_title = 'Academic Counselor',
  job_level = 'Contract',
  department = 'CODO Academy',
  reports_to_user_id = @mgr_ajmal_nk,
  contract_type = 'remote',
  offer_letter_issued = 1,
  probation_end_date = NULL,
  employment_status = 'inactive',
  account_active = 0
WHERE email = @e;
INSERT INTO user_onboarding_details (user_id, date_of_birth, gender, marital_status)
SELECT id, '2008-04-29', 'female', 'single' FROM users WHERE email = @e
ON DUPLICATE KEY UPDATE
  date_of_birth = VALUES(date_of_birth), gender = VALUES(gender), marital_status = VALUES(marital_status);

-- Fathima Nidha Nk | CODO-TMLO-TNTP | DOB 2006-04-12 | Join 2025-03-25
-- Note: sheet "Marital Status" was "Indian" — stored as other
SET @e := 'REPLACE_ME_fathima_nidha@codo.example';
UPDATE users SET
  joining_date = '2025-03-25',
  employee_code = 'CODO-TMLO-TNTP',
  job_title = 'Academic Counselor',
  job_level = 'Contract',
  department = 'CODO Academy',
  reports_to_user_id = @mgr_ajmal_nk,
  contract_type = 'remote',
  offer_letter_issued = 1,
  probation_end_date = NULL,
  employment_status = 'inactive',
  account_active = 0
WHERE email = @e;
INSERT INTO user_onboarding_details (user_id, date_of_birth, gender, marital_status)
SELECT id, '2006-04-12', 'female', 'other' FROM users WHERE email = @e
ON DUPLICATE KEY UPDATE
  date_of_birth = VALUES(date_of_birth), gender = VALUES(gender), marital_status = VALUES(marital_status);

-- Mohammed Nabeel M | CODO-KKLO-TPTM | DOB 2003-06-02 | Join 2025-11-19 | Inactive
SET @e := 'REPLACE_ME_mohammed_nabeel@codo.example';
UPDATE users SET
  joining_date = '2025-11-19',
  employee_code = 'CODO-KKLO-TPTM',
  job_title = 'Full Stack Developer',
  job_level = 'Junior',
  department = 'CODO Agency - Development',
  reports_to_user_id = @mgr_fahis,
  contract_type = 'full_time',
  offer_letter_issued = 1,
  probation_end_date = NULL,
  employment_status = 'inactive',
  account_active = 0
WHERE email = @e;
INSERT INTO user_onboarding_details (user_id, date_of_birth, gender, marital_status)
SELECT id, '2003-06-02', 'male', 'single' FROM users WHERE email = @e
ON DUPLICATE KEY UPDATE
  date_of_birth = VALUES(date_of_birth), gender = VALUES(gender), marital_status = VALUES(marital_status);

-- Shibin P | CODO-TSLO-TOTM | DOB 2003-05-24 | Join 2025-09-01
SET @e := 'REPLACE_ME_shibin_p@codo.example';
UPDATE users SET
  joining_date = '2025-09-01',
  employee_code = 'CODO-TSLO-TOTM',
  job_title = 'Full Stack Developer',
  job_level = 'Junior',
  department = 'CODO Agency - Development',
  reports_to_user_id = @mgr_fahis,
  contract_type = 'full_time',
  offer_letter_issued = 1,
  probation_end_date = NULL,
  employment_status = 'active',
  account_active = 1
WHERE email = @e;
INSERT INTO user_onboarding_details (user_id, date_of_birth, gender, marital_status)
SELECT id, '2003-05-24', 'male', 'single' FROM users WHERE email = @e
ON DUPLICATE KEY UPDATE
  date_of_birth = VALUES(date_of_birth), gender = VALUES(gender), marital_status = VALUES(marital_status);

-- Muhammed Irshad K | CODO-KKLO-TMTK | DOB 2001-03-21 | Join 2025-11-19
SET @e := 'REPLACE_ME_muhammed_irshad@codo.example';
UPDATE users SET
  joining_date = '2025-11-19',
  employee_code = 'CODO-KKLO-TMTK',
  job_title = 'Full Stack Developer',
  job_level = 'Junior',
  department = 'CODO Agency - Development',
  reports_to_user_id = @mgr_fahis,
  contract_type = 'full_time',
  offer_letter_issued = 1,
  probation_end_date = NULL,
  employment_status = 'active',
  account_active = 1
WHERE email = @e;
INSERT INTO user_onboarding_details (user_id, date_of_birth, gender, marital_status)
SELECT id, '2001-03-21', 'male', 'single' FROM users WHERE email = @e
ON DUPLICATE KEY UPDATE
  date_of_birth = VALUES(date_of_birth), gender = VALUES(gender), marital_status = VALUES(marital_status);

-- Jubairiya | CODO-KLLO-TRSK | DOB 1991-08-27 | Join 2025-12-01
SET @e := 'REPLACE_ME_jubairiya@codo.example';
UPDATE users SET
  joining_date = '2025-12-01',
  employee_code = 'CODO-KLLO-TRSK',
  job_title = 'Digital Marketing Executive',
  job_level = 'Senior',
  department = 'CODO Agency - Marketing',
  reports_to_user_id = @mgr_ajmal_nk,
  contract_type = 'full_time',
  offer_letter_issued = 1,
  probation_end_date = NULL,
  employment_status = 'active',
  account_active = 1
WHERE email = @e;
INSERT INTO user_onboarding_details (user_id, date_of_birth, gender, marital_status)
SELECT id, '1991-08-27', 'female', 'married' FROM users WHERE email = @e
ON DUPLICATE KEY UPDATE
  date_of_birth = VALUES(date_of_birth), gender = VALUES(gender), marital_status = VALUES(marital_status);

-- Jidhin T | CODO-KKLO-KLTM | DOB 2003-12-06 | Join 2025-11-19
SET @e := 'REPLACE_ME_jidhin_t@codo.example';
UPDATE users SET
  joining_date = '2025-11-19',
  employee_code = 'CODO-KKLO-KLTM',
  job_title = 'Full Stack Developer',
  job_level = 'Junior',
  department = 'CODO Agency - Development',
  reports_to_user_id = @mgr_fahis,
  contract_type = 'full_time',
  offer_letter_issued = 1,
  probation_end_date = NULL,
  employment_status = 'active',
  account_active = 1
WHERE email = @e;
INSERT INTO user_onboarding_details (user_id, date_of_birth, gender, marital_status)
SELECT id, '2003-12-06', 'male', 'single' FROM users WHERE email = @e
ON DUPLICATE KEY UPDATE
  date_of_birth = VALUES(date_of_birth), gender = VALUES(gender), marital_status = VALUES(marital_status);

-- Sinan Paravath | CODO-TKLP-TNTM | DOB 2003-04-04 | Join 2026-01-16
SET @e := 'REPLACE_ME_sinan_paravath@codo.example';
UPDATE users SET
  joining_date = '2026-01-16',
  employee_code = 'CODO-TKLP-TNTM',
  job_title = 'Full-Stack Python Developer',
  job_level = 'Intern',
  department = 'CODO Agency - Development',
  reports_to_user_id = @mgr_fahis,
  contract_type = 'full_time',
  offer_letter_issued = 1,
  probation_end_date = NULL,
  employment_status = 'active',
  account_active = 1
WHERE email = @e;
INSERT INTO user_onboarding_details (user_id, date_of_birth, gender, marital_status)
SELECT id, '2003-04-04', 'male', 'single' FROM users WHERE email = @e
ON DUPLICATE KEY UPDATE
  date_of_birth = VALUES(date_of_birth), gender = VALUES(gender), marital_status = VALUES(marital_status);

-- Sahala Nasrin | CODO-KLLO-TQSM | DOB 1993-07-24 | Join 2025-12-29
SET @e := 'REPLACE_ME_sahala_nasrin@codo.example';
UPDATE users SET
  joining_date = '2025-12-29',
  employee_code = 'CODO-KLLO-TQSM',
  job_title = 'Full Stack Developer',
  job_level = 'Intern',
  department = 'CODO Agency - Development',
  reports_to_user_id = @mgr_fahis,
  contract_type = 'full_time',
  offer_letter_issued = 1,
  probation_end_date = NULL,
  employment_status = 'active',
  account_active = 1
WHERE email = @e;
INSERT INTO user_onboarding_details (user_id, date_of_birth, gender, marital_status)
SELECT id, '1993-07-24', 'female', 'married' FROM users WHERE email = @e
ON DUPLICATE KEY UPDATE
  date_of_birth = VALUES(date_of_birth), gender = VALUES(gender), marital_status = VALUES(marital_status);

-- Sareefa TP | CODO-KLLO-TSTM | DOB 2003-09-11 | Join 2025-12-22
SET @e := 'REPLACE_ME_sareefa_tp@codo.example';
UPDATE users SET
  joining_date = '2025-12-22',
  employee_code = 'CODO-KLLO-TSTM',
  job_title = 'Full Stack Developer',
  job_level = 'Intern',
  department = 'CODO Agency - Development',
  reports_to_user_id = @mgr_fahis,
  contract_type = 'full_time',
  offer_letter_issued = 1,
  probation_end_date = NULL,
  employment_status = 'active',
  account_active = 1
WHERE email = @e;
INSERT INTO user_onboarding_details (user_id, date_of_birth, gender, marital_status)
SELECT id, '2003-09-11', 'female', 'married' FROM users WHERE email = @e
ON DUPLICATE KEY UPDATE
  date_of_birth = VALUES(date_of_birth), gender = VALUES(gender), marital_status = VALUES(marital_status);

-- Abdul Basith | CODO-TKLP-KKTM | DOB 2003-11-12 | Join 2026-01-29
SET @e := 'REPLACE_ME_abdul_basith@codo.example';
UPDATE users SET
  joining_date = '2026-01-29',
  employee_code = 'CODO-TKLP-KKTM',
  job_title = 'Frontend Developer',
  job_level = 'Junior',
  department = 'CODO Agency - Development',
  reports_to_user_id = @mgr_fahis,
  contract_type = 'full_time',
  offer_letter_issued = 1,
  probation_end_date = NULL,
  employment_status = 'active',
  account_active = 1
WHERE email = @e;
INSERT INTO user_onboarding_details (user_id, date_of_birth, gender, marital_status)
SELECT id, '2003-11-12', 'male', 'single' FROM users WHERE email = @e
ON DUPLICATE KEY UPDATE
  date_of_birth = VALUES(date_of_birth), gender = VALUES(gender), marital_status = VALUES(marital_status);

-- Muneeba M | CODO-TLLP-TRTK | DOB 2001-08-01 | Join 2026-02-02
SET @e := 'REPLACE_ME_muneeba_m@codo.example';
UPDATE users SET
  joining_date = '2026-02-02',
  employee_code = 'CODO-TLLP-TRTK',
  job_title = 'Frontend Developer',
  job_level = 'Junior',
  department = 'CODO Agency - Development',
  reports_to_user_id = @mgr_fahis,
  contract_type = 'full_time',
  offer_letter_issued = 1,
  probation_end_date = NULL,
  employment_status = 'active',
  account_active = 1
WHERE email = @e;
INSERT INTO user_onboarding_details (user_id, date_of_birth, gender, marital_status)
SELECT id, '2001-08-01', 'female', 'married' FROM users WHERE email = @e
ON DUPLICATE KEY UPDATE
  date_of_birth = VALUES(date_of_birth), gender = VALUES(gender), marital_status = VALUES(marital_status);

-- Mohammad Hashim | CODO-TLLP-TSTL | DOB 2002-08-09 | Join 2026-02-25
SET @e := 'REPLACE_ME_mohammad_hashim@codo.example';
UPDATE users SET
  joining_date = '2026-02-25',
  employee_code = 'CODO-TLLP-TSTL',
  job_title = 'Python Full-stack Developer',
  job_level = 'Junior',
  department = 'CODO Agency - Development',
  reports_to_user_id = @mgr_fahis,
  contract_type = 'full_time',
  offer_letter_issued = 1,
  probation_end_date = NULL,
  employment_status = 'active',
  account_active = 1
WHERE email = @e;
INSERT INTO user_onboarding_details (user_id, date_of_birth, gender, marital_status)
SELECT id, '2002-08-09', 'male', 'single' FROM users WHERE email = @e
ON DUPLICATE KEY UPDATE
  date_of_birth = VALUES(date_of_birth), gender = VALUES(gender), marital_status = VALUES(marital_status);

-- Salman | CODO-TKLP-TLTM | DOB 2003-02-25 | Join 2026-01 (sheet incomplete — using 2026-01-16 from ID month)
-- Employee ID TKLP = 0126 → Jan 2026; TLTM = 2503 → Feb? Wait: T=0 L=2 T=0 M=3 → 0203 = Feb 2003 month/year of DOB
-- Sheet DOB 25 Feb 2003 → MMYY 0203 → TLTM ✓; Join from TKLP = 0126 Jan 2026 — day unknown, use 2026-01-16 placeholder or leave join if blank
-- Sheet left Join Date blank — set joining from cipher month to first of month
SET @e := 'REPLACE_ME_salman@codo.example';
UPDATE users SET
  joining_date = '2026-01-01',
  employee_code = 'CODO-TKLP-TLTM',
  job_title = 'Accounts Executive',
  job_level = 'Junior',
  department = 'CODO AI INNOVATIONS',
  reports_to_user_id = @mgr_ajmal_nk,
  contract_type = NULL,
  offer_letter_issued = 0,
  probation_end_date = NULL,
  employment_status = 'active',
  account_active = 1
WHERE email = @e;
INSERT INTO user_onboarding_details (user_id, date_of_birth, gender, marital_status)
SELECT id, '2003-02-25', 'male', 'single' FROM users WHERE email = @e
ON DUPLICATE KEY UPDATE
  date_of_birth = VALUES(date_of_birth), gender = VALUES(gender), marital_status = VALUES(marital_status);

-- Verification helper (optional):
-- SELECT username, email, employee_code, joining_date, job_title, employment_status FROM users WHERE employee_code IS NOT NULL ORDER BY joining_date;
