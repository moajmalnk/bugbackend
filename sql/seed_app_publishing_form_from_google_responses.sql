-- seed_app_publishing_form_from_google_responses.sql
-- Maps Google Form responses into projects.app_publishing_form (per project).
-- Keystore passwords / signing secrets intentionally OMITTED.
-- Preview matches first, then run UPDATEs.

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'projects'
    AND COLUMN_NAME = 'app_publishing_form'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE projects ADD COLUMN app_publishing_form LONGTEXT NULL',
  'SELECT \'column already exists\'');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Preview which rows will match:
SELECT id, name FROM projects
WHERE name LIKE '%EduPay%' OR name LIKE '%BZOLE%' OR name LIKE '%Bzole%'
   OR name LIKE '%Nurse%X%Pro%' OR name LIKE '%BugRicer%' OR name LIKE '%Kotta%Deal%'
   OR name LIKE '%NextGen%' OR name LIKE '%Zeal%' OR name LIKE '%Mas%Holiday%' OR name LIKE '%MAS%Holiday%'
ORDER BY name;

-- BZOLE EduPay
UPDATE projects
SET app_publishing_form = '{"status": "submitted", "updated_at": "2026-11-18T06:28:17.000Z", "submitted_at": "2026-11-18T06:28:17.000Z", "company_name": "BZOLE", "contact_person": "Mabrook", "phone_number": "7736483502", "app_name": "BZOLE EduPay", "app_subtitle": "All-in-one school ERP for marks, instant fee payments, and notices.", "app_description": "BZOLE School is a comprehensive, real-time administrative application designed to connect school management, teachers, parents, and students in one seamless digital workspace.\\n\\nKey Features:\\n• Real-time Fee Tracker: View pending dues, receipt history, and pay school fees instantly.\\n• Multi-Sibling Workspace: Switch between multiple children’s profiles using a single parent account.\\n• Instant Attendance & Logs: Track daily classroom attendance and receive instant absence notifications.\\n• Digital ID & Hall Tickets: Access scannable QR digital student ID cards and download exam hall tickets.\\n• Academic Updates: Stay informed with real-time push notifications for homework, exam schedules, and report cards.\\n• School Transport Mapping: View assigned transport routes and fee structures effortlessly.\\n\\nStreamline your school''s daily operations and stay connected on autopilot with BZOLE School.", "existing_website_domain": "", "app_release_type": "new", "publish_platforms": "both", "privacy_policy_url": "", "data_deletion_url": "", "support_url": "", "android_has_play_account": "yes", "android_console_email": "macadzinfo@gmail.com", "android_package_name": "", "android_category": "Finance", "android_pricing": "paid", "android_contains_ads": "no", "android_target_audience": ["adults"], "android_store_description": "BZOLE School is a comprehensive, real-time administrative application designed to connect school management, teachers, parents, and students in one seamless digital workspace.\\n\\nKey Features:\\n• Real-time Fee Tracker: View pending dues, receipt history, and pay school fees instantly.\\n• Multi-Sibling Workspace: Switch between multiple children’s profiles using a single parent account.\\n• Instant Attendance & Logs: Track daily classroom attendance and receive instant absence notifications.\\n• Digital ID & Hall Tickets: Access scannable QR digital student ID cards and download exam hall tickets.\\n• Academic Updates: Stay informed with real-time push notifications for homework, exam schedules, and report cards.\\n• School Transport Mapping: View assigned transport routes and fee structures effortlessly.\\n\\nStreamline your school''s daily operations and stay connected on autopilot with BZOLE School.", "android_keywords": "", "ios_has_developer_account": "yes", "ios_apple_id_email": "macadzinfo@gmail.com", "ios_team_name": "mac", "ios_team_id": "", "ios_bundle_id": "", "ios_primary_language": "", "ios_category": "", "ios_age_rating": "", "promotional_video_url": "", "notes": "Contact alias: Mabrook. Android package was \\"BZOLE\\" (not reverse-domain) — confirm before store submit. iOS label: BZOLE EDUPAY. Keystore secrets not imported."}'
WHERE (name = 'BZOLE EduPay' OR name = 'BZOLE' OR name LIKE '%EduPay%' OR name LIKE '%BZOLE%Edu%');

-- Bzole ERP
UPDATE projects
SET app_publishing_form = '{"status": "submitted", "updated_at": "2026-11-18T06:28:17.000Z", "submitted_at": "2026-11-18T06:28:17.000Z", "company_name": "Bzole", "contact_person": "Mabrook abdulla", "phone_number": "9744009048", "app_name": "Bzole ERP", "app_subtitle": "Agency management", "app_description": "", "existing_website_domain": "", "app_release_type": "new", "publish_platforms": "both", "privacy_policy_url": "", "data_deletion_url": "", "support_url": "", "android_has_play_account": "yes", "android_console_email": "macadzinfo@gmail.com", "android_package_name": "", "android_category": "Business", "android_pricing": "", "android_contains_ads": "no", "android_target_audience": ["adults"], "android_store_description": "", "android_keywords": "", "ios_has_developer_account": "yes", "ios_apple_id_email": "macadzinfo@gmail.com", "ios_team_name": "bzole", "ios_team_id": "", "ios_bundle_id": "", "ios_primary_language": "English", "ios_category": "Business", "ios_age_rating": "17+", "promotional_video_url": "", "notes": "Contact email: macadzinfo@gmail.com. Package field had crm.bzole.com (domain) — confirm real package/bundle. Keystore secrets not imported."}'
WHERE (name = 'Bzole ERP' OR name = 'Bzole  ERP' OR name LIKE '%Bzole%ERP%' OR name LIKE '%BZOLE%ERP%');

-- Nurse X Pro
UPDATE projects
SET app_publishing_form = '{"status": "submitted", "updated_at": "2026-11-18T06:28:17.000Z", "submitted_at": "2026-11-18T06:28:17.000Z", "company_name": "Nurse X Pro Pvt.Ltd", "contact_person": "Musthafa", "phone_number": "+919745084786", "app_name": "Nurse X Pro", "app_subtitle": "", "app_description": "", "existing_website_domain": "https://www.nursexpro.com", "app_release_type": "new", "publish_platforms": "both", "privacy_policy_url": "", "data_deletion_url": "", "support_url": "", "android_has_play_account": "no", "android_console_email": "", "android_package_name": "", "android_category": "", "android_pricing": "free", "android_contains_ads": "no", "android_target_audience": ["adults"], "android_store_description": "", "android_keywords": "", "ios_has_developer_account": "yes", "ios_apple_id_email": "", "ios_team_name": "", "ios_team_id": "", "ios_bundle_id": "", "ios_primary_language": "English", "ios_category": "Education", "ios_age_rating": "17+", "promotional_video_url": "", "notes": "Contact email: info@nursexpro.com. Package/bundle not in source form."}'
WHERE (name = 'Nurse X Pro' OR name LIKE '%Nurse%X%Pro%');

-- BugRicer
UPDATE projects
SET app_publishing_form = '{"status": "submitted", "updated_at": "2026-11-18T06:28:17.000Z", "submitted_at": "2026-11-18T06:28:17.000Z", "company_name": "BugRicer", "contact_person": "Mohammed Ajmla", "phone_number": "8848676627", "app_name": "BugRicer", "app_subtitle": "Advanced Bug Tracking & Project Management Platform", "app_description": "Streamline your development workflow with powerful bug tracking, seamless team collaboration, and comprehensive project management tools all in one place.", "existing_website_domain": "https://bugs.bugricer.com", "app_release_type": "new", "publish_platforms": "both", "privacy_policy_url": "https://bugs.bugricer.com/privacy-policy", "data_deletion_url": "", "support_url": "https://bugs.bugricer.com/", "android_has_play_account": "yes", "android_console_email": "apps.codomail@gmail.com", "android_package_name": "com.bugricer.app", "android_category": "Productivity", "android_pricing": "free", "android_contains_ads": "no", "android_target_audience": ["children", "teens", "adults"], "android_store_description": "Streamline your development workflow with powerful bug tracking, seamless team collaboration, and comprehensive project management tools all in one place.", "android_keywords": "bugricer, bug, bug tracker, bugs, project management", "ios_has_developer_account": "no", "ios_apple_id_email": "", "ios_team_name": "", "ios_team_id": "", "ios_bundle_id": "com.bugricer.app", "ios_primary_language": "English", "ios_category": "Productivity", "ios_age_rating": "4+", "promotional_video_url": "", "notes": "Contact email: moajmalnk@gmail.com. Org: CODO AI Innovations (IN/Kerala). Terms: https://bugs.bugricer.com/terms-of-use. Keystore secrets not imported."}'
WHERE (name = 'BugRicer' OR name LIKE '%BugRicer%');

-- Kotta Deals
UPDATE projects
SET app_publishing_form = '{"status": "submitted", "updated_at": "2026-11-18T06:28:17.000Z", "submitted_at": "2026-11-18T06:28:17.000Z", "company_name": "Kotta Deals", "contact_person": "Mohammed Ajmal", "phone_number": "8848676627", "app_name": "Kotta Deals", "app_subtitle": "Never Pay Full Price Again", "app_description": "The web''s best discounts, exclusive coupons, and hidden price drops—updated in real-time.", "existing_website_domain": "https://kottadeals.com/", "app_release_type": "new", "publish_platforms": "both", "privacy_policy_url": "", "data_deletion_url": "", "support_url": "https://kottadeals.com/", "android_has_play_account": "no", "android_console_email": "CODO Apps", "android_package_name": "com.kotta.deals", "android_category": "Business", "android_pricing": "free", "android_contains_ads": "yes", "android_target_audience": ["teens", "adults"], "android_store_description": "The web''s best discounts, exclusive coupons, and hidden price drops—updated in real-time.", "android_keywords": "kotta, deals, offers, cards, coupens, discounts", "ios_has_developer_account": "yes", "ios_apple_id_email": "mack.mpm@gmail.com", "ios_team_name": "", "ios_team_id": "", "ios_bundle_id": "co.kotta.deals", "ios_primary_language": "English", "ios_category": "Business", "ios_age_rating": "12+", "promotional_video_url": "", "notes": "Contact email: moajmalnk@gmail.com. Org: Kotta Deals (IN/Kerala). Keystore secrets not imported."}'
WHERE (name = 'Kotta Deals' OR name LIKE '%Kotta%Deal%');

-- NextGen
UPDATE projects
SET app_publishing_form = '{"status": "submitted", "updated_at": "2026-11-18T06:28:17.000Z", "submitted_at": "2026-11-18T06:28:17.000Z", "company_name": "NextGen", "contact_person": "Mohammed Ajmal", "phone_number": "8848676627", "app_name": "NextGen", "app_subtitle": "Instantly discover hidden SEO flaws, security vulnerabilities, and performance bottlenecks.", "app_description": "Instantly discover hidden SEO flaws, security vulnerabilities, and performance bottlenecks.", "existing_website_domain": "https://next.codoai.in/", "app_release_type": "new", "publish_platforms": "both", "privacy_policy_url": "https://next.codoai.in/privacy", "data_deletion_url": "https://next.codoai.in/data-deletion", "support_url": "https://next.codoai.in/support", "android_has_play_account": "yes", "android_console_email": "ai.codomail@gmail.com", "android_package_name": "com.codo.nextgen", "android_category": "Business", "android_pricing": "free", "android_contains_ads": "no", "android_target_audience": ["children", "teens", "adults"], "android_store_description": "", "android_keywords": "Seo, Web, Security, Insights, performance", "ios_has_developer_account": "yes", "ios_apple_id_email": "mack.mpm@gmail.com", "ios_team_name": "", "ios_team_id": "", "ios_bundle_id": "", "ios_primary_language": "English", "ios_category": "Business", "ios_age_rating": "9+", "promotional_video_url": "", "notes": "Org: Development Company. Copyright © 2026 NextGen. Keystore secrets not imported."}'
WHERE (name = 'NextGen' OR name LIKE '%NextGen%');

-- Zeal career
UPDATE projects
SET app_publishing_form = '{"status": "submitted", "updated_at": "2026-11-18T06:28:17.000Z", "submitted_at": "2026-11-18T06:28:17.000Z", "company_name": "Zeal career", "contact_person": "Tp muhammed", "phone_number": "9744009048", "app_name": "Zeal career", "app_subtitle": "", "app_description": "", "existing_website_domain": "", "app_release_type": "new", "publish_platforms": "both", "privacy_policy_url": "", "data_deletion_url": "", "support_url": "", "android_has_play_account": "yes", "android_console_email": "Macadzinfo@gmail.com", "android_package_name": "", "android_category": "Education", "android_pricing": "paid", "android_contains_ads": "no", "android_target_audience": ["children", "teens", "adults"], "android_store_description": "", "android_keywords": "", "ios_has_developer_account": "yes", "ios_apple_id_email": "Macadzinfo@gmail.com", "ios_team_name": "Mac adz", "ios_team_id": "", "ios_bundle_id": "", "ios_primary_language": "English", "ios_category": "Education", "ios_age_rating": "4+", "promotional_video_url": "", "notes": "Package/bundle not in source form. Keystore secrets not imported."}'
WHERE (name = 'Zeal career' OR name = 'Zeal Career' OR name LIKE '%Zeal%career%' OR name LIKE '%Zeal%Career%');

-- Mas Holidays
UPDATE projects
SET app_publishing_form = '{"status": "submitted", "updated_at": "2026-11-18T06:28:17.000Z", "submitted_at": "2026-11-18T06:28:17.000Z", "company_name": "MAS", "contact_person": "Mabrook", "phone_number": "9744009048", "app_name": "Mas Holidays", "app_subtitle": "", "app_description": "", "existing_website_domain": "https://mas-holidays.com", "app_release_type": "new", "publish_platforms": "both", "privacy_policy_url": "", "data_deletion_url": "", "support_url": "", "android_has_play_account": "yes", "android_console_email": "macadzinfo@gmail.com", "android_package_name": "", "android_category": "Travel", "android_pricing": "free", "android_contains_ads": "no", "android_target_audience": ["teens", "adults"], "android_store_description": "a", "android_keywords": "a", "ios_has_developer_account": "yes", "ios_apple_id_email": "mack.mpm@gmail.com", "ios_team_name": "mac adz", "ios_team_id": "1", "ios_bundle_id": "com.mas.holidays", "ios_primary_language": "English", "ios_category": "Travel", "ios_age_rating": "4+", "promotional_video_url": "", "notes": "Platforms blank in source — defaulted to both. Placeholder description/keywords (\\"a\\") kept; clean before store listing. Keystore secrets not imported."}'
WHERE (name = 'Mas Holidays' OR name = 'MasHolidays App' OR name = 'MAS Holidays' OR name LIKE '%Mas%Holiday%' OR name LIKE '%MAS%Holiday%');

-- Verify:
SELECT id, name,
       JSON_UNQUOTE(JSON_EXTRACT(app_publishing_form, '$.app_name')) AS app_name,
       JSON_UNQUOTE(JSON_EXTRACT(app_publishing_form, '$.status')) AS status
FROM projects
WHERE app_publishing_form IS NOT NULL
  AND (
    name LIKE '%EduPay%' OR name LIKE '%BZOLE%' OR name LIKE '%Bzole%'
    OR name LIKE '%Nurse%' OR name LIKE '%BugRicer%' OR name LIKE '%Kotta%'
    OR name LIKE '%NextGen%' OR name LIKE '%Zeal%' OR name LIKE '%Holiday%'
  )
ORDER BY name;

