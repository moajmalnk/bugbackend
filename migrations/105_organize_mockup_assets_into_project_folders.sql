-- =============================================================================
-- 105_organize_mockup_assets_into_project_folders.sql
--
-- Goal: place Mockup App / Mockup Web assets into:
--   CODO AGENCY → Mockup → {Project} → App Mockups | Website Mockups
-- Prefer the App/Website Mockups subfolder when it exists; otherwise the
-- project folder itself.
--
-- Safety rules:
--   1) Only UPDATE rows whose folder_id would change
--   2) Never invent folders — project folder must already exist under Mockup
--   3) Map key is (title, material_type) so "Zeeque Magazine 1" (Web) and
--      "Zeeque magazine 1" (App) do not collide on case-insensitive collations
--   4) Ambiguous titles are NOT mapped (keep current path):
--        - Albedo mockup app 1–4
--        - Evoka mockup app 1–4
--        - MOCKUP Ajency Albedo …
--
-- How to run: execute full script in phpMyAdmin (transactional).
-- =============================================================================

START TRANSACTION;

DROP TEMPORARY TABLE IF EXISTS tmp_mockup_title_map;
DROP TEMPORARY TABLE IF EXISTS tmp_mockup_folder_alias;
DROP TEMPORARY TABLE IF EXISTS tmp_mockup_resolved;

CREATE TEMPORARY TABLE tmp_mockup_title_map (
  asset_title VARCHAR(255) NOT NULL,
  material_type VARCHAR(32) NOT NULL,
  project_key VARCHAR(100) NOT NULL,
  preferred_subfolder VARCHAR(100) NOT NULL,
  PRIMARY KEY (asset_title, material_type)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TEMPORARY TABLE tmp_mockup_folder_alias (
  project_key VARCHAR(100) NOT NULL,
  folder_name VARCHAR(100) NOT NULL,
  PRIMARY KEY (project_key, folder_name)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

INSERT INTO tmp_mockup_title_map (asset_title, material_type, project_key, preferred_subfolder) VALUES
  ('2,o app 1', 'Mockup App', '2.0', 'App Mockups'),
  ('2,o app 2', 'Mockup App', '2.0', 'App Mockups'),
  ('2,o app 3', 'Mockup App', '2.0', 'App Mockups'),
  ('2,o app 4', 'Mockup App', '2.0', 'App Mockups'),
  ('2.0 1', 'Mockup Web', '2.0', 'Website Mockups'),
  ('2.0 2', 'Mockup Web', '2.0', 'Website Mockups'),
  ('2.0 3', 'Mockup Web', '2.0', 'Website Mockups'),
  ('2.0 4', 'Mockup Web', '2.0', 'Website Mockups'),
  ('Albedo Calc 1 Mockup', 'Mockup Web', 'Albedo Calc', 'Website Mockups'),
  ('Albedo Calc 2 Mockup', 'Mockup Web', 'Albedo Calc', 'Website Mockups'),
  ('Albedo Calc 3 Mockup', 'Mockup Web', 'Albedo Calc', 'Website Mockups'),
  ('Albedo Calc 4 Mockup', 'Mockup Web', 'Albedo Calc', 'Website Mockups'),
  ('Albedo Educator Mockup 2', 'Mockup Web', 'Albedo Educator', 'Website Mockups'),
  ('Albedo Operation 1 st Mockup', 'Mockup Web', 'Albedo Operations', 'Website Mockups'),
  ('albedo operation 2 Mockup', 'Mockup Web', 'Albedo Operations', 'Website Mockups'),
  ('Albedo operation 3 Mockup', 'Mockup Web', 'Albedo Operations', 'Website Mockups'),
  ('Albedo operation 4 Mockup', 'Mockup Web', 'Albedo Operations', 'Website Mockups'),
  ('Albedo Support 1 Mockup', 'Mockup Web', 'Albedo Support', 'Website Mockups'),
  ('Albedo Support 2 Mockup', 'Mockup Web', 'Albedo Support', 'Website Mockups'),
  ('Albedo Support 3 Mockup', 'Mockup Web', 'Albedo Support', 'Website Mockups'),
  ('Albedo Support 4 Mockup', 'Mockup Web', 'Albedo Support', 'Website Mockups'),
  ('Binjabreen 1', 'Mockup App', 'Bin Jabreen', 'App Mockups'),
  ('Binjabreen 2', 'Mockup App', 'Bin Jabreen', 'App Mockups'),
  ('Binjabreen 3', 'Mockup App', 'Bin Jabreen', 'App Mockups'),
  ('Binjabreen 4', 'Mockup App', 'Bin Jabreen', 'App Mockups'),
  ('Bin Jabreen 1', 'Mockup Web', 'Bin Jabreen', 'Website Mockups'),
  ('Bin Jabreen 2', 'Mockup Web', 'Bin Jabreen', 'Website Mockups'),
  ('Bin Jabreen 3', 'Mockup Web', 'Bin Jabreen', 'Website Mockups'),
  ('Bin Jabreen 4', 'Mockup Web', 'Bin Jabreen', 'Website Mockups'),
  ('Cbms app 1', 'Mockup App', 'CBMS', 'App Mockups'),
  ('Cbms app 2', 'Mockup App', 'CBMS', 'App Mockups'),
  ('Cbms app 3', 'Mockup App', 'CBMS', 'App Mockups'),
  ('Cbms app 4', 'Mockup App', 'CBMS', 'App Mockups'),
  ('CBMS MARiage fund 1', 'Mockup Web', 'CBMS MARiage fund', 'Website Mockups'),
  ('CBMS MARiage fund 2', 'Mockup Web', 'CBMS MARiage fund', 'Website Mockups'),
  ('CBMS MARiage fund 3', 'Mockup Web', 'CBMS MARiage fund', 'Website Mockups'),
  ('CBMS MARiage fund 4', 'Mockup Web', 'CBMS MARiage fund', 'Website Mockups'),
  ('Codo academy app 1', 'Mockup App', 'Codo academy', 'App Mockups'),
  ('Codo academy app 2', 'Mockup App', 'Codo academy', 'App Mockups'),
  ('Codo academy app 3', 'Mockup App', 'Codo academy', 'App Mockups'),
  ('Codo academy app 4', 'Mockup App', 'Codo academy', 'App Mockups'),
  ('Codo academy 1', 'Mockup Web', 'Codo academy', 'Website Mockups'),
  ('Codo academy 2', 'Mockup Web', 'Codo academy', 'Website Mockups'),
  ('Codo academy 3', 'Mockup Web', 'Codo academy', 'Website Mockups'),
  ('Codo academy 4', 'Mockup Web', 'Codo academy', 'Website Mockups'),
  ('Codo academy 5', 'Mockup Web', 'Codo academy', 'Website Mockups'),
  ('Codo ai app 1', 'Mockup App', 'Codo ai', 'App Mockups'),
  ('Codo ai app 2', 'Mockup App', 'Codo ai', 'App Mockups'),
  ('Codo ai app 3', 'Mockup App', 'Codo ai', 'App Mockups'),
  ('Codo ai app 4', 'Mockup App', 'Codo ai', 'App Mockups'),
  ('Codo ai 1', 'Mockup Web', 'Codo ai', 'Website Mockups'),
  ('Codo ai 2', 'Mockup Web', 'Codo ai', 'Website Mockups'),
  ('Codo ai 3', 'Mockup Web', 'Codo ai', 'Website Mockups'),
  ('Codo ai 4', 'Mockup Web', 'Codo ai', 'Website Mockups'),
  ('Codo ai 5', 'Mockup Web', 'Codo ai', 'Website Mockups'),
  ('Darna app 1', 'Mockup App', 'Darna', 'App Mockups'),
  ('Darna app 2', 'Mockup App', 'Darna', 'App Mockups'),
  ('Darna app 3', 'Mockup App', 'Darna', 'App Mockups'),
  ('Darna app 4', 'Mockup App', 'Darna', 'App Mockups'),
  ('Darna 1', 'Mockup Web', 'Darna', 'Website Mockups'),
  ('Darna 2', 'Mockup Web', 'Darna', 'Website Mockups'),
  ('Darna 3', 'Mockup Web', 'Darna', 'Website Mockups'),
  ('Darna 4', 'Mockup Web', 'Darna', 'Website Mockups'),
  ('Dsign app 1', 'Mockup App', 'Dsign', 'App Mockups'),
  ('Dsign app 2', 'Mockup App', 'Dsign', 'App Mockups'),
  ('Dsign app 3', 'Mockup App', 'Dsign', 'App Mockups'),
  ('Dsign app 4', 'Mockup App', 'Dsign', 'App Mockups'),
  ('dsign 1', 'Mockup Web', 'Dsign', 'Website Mockups'),
  ('dsign 2', 'Mockup Web', 'Dsign', 'Website Mockups'),
  ('dsign 3', 'Mockup Web', 'Dsign', 'Website Mockups'),
  ('dsign 4', 'Mockup Web', 'Dsign', 'Website Mockups'),
  ('Europe Calling app 1', 'Mockup App', 'Europe calling', 'App Mockups'),
  ('Europe Calling app 2', 'Mockup App', 'Europe calling', 'App Mockups'),
  ('Europe Calling app 3', 'Mockup App', 'Europe calling', 'App Mockups'),
  ('Europe Calling app 4', 'Mockup App', 'Europe calling', 'App Mockups'),
  ('Europe calling 1', 'Mockup Web', 'Europe calling', 'Website Mockups'),
  ('Europe calling 2', 'Mockup Web', 'Europe calling', 'Website Mockups'),
  ('Europe calling 3', 'Mockup Web', 'Europe calling', 'Website Mockups'),
  ('Europe calling 4', 'Mockup Web', 'Europe calling', 'Website Mockups'),
  ('Evoka communication 1 Mockup', 'Mockup Web', 'Evoka communication', 'Website Mockups'),
  ('Evoka communication 2 Mockup', 'Mockup Web', 'Evoka communication', 'Website Mockups'),
  ('Evoka communication 3 Mockup', 'Mockup Web', 'Evoka communication', 'Website Mockups'),
  ('Evoka communication 4 Mockup', 'Mockup Web', 'Evoka communication', 'Website Mockups'),
  ('Evoka Schoole 1 Mockup', 'Mockup Web', 'Evoka Schoole', 'Website Mockups'),
  ('Evoka Schoole 2 Mockup', 'Mockup Web', 'Evoka Schoole', 'Website Mockups'),
  ('Evoka Schoole 3 Mockup', 'Mockup Web', 'Evoka Schoole', 'Website Mockups'),
  ('Evoka Schoole 4 Mockup', 'Mockup Web', 'Evoka Schoole', 'Website Mockups'),
  ('Fezzo app 1', 'Mockup App', 'Fezzo', 'App Mockups'),
  ('Fezzo app 2', 'Mockup App', 'Fezzo', 'App Mockups'),
  ('Fezzo app 3', 'Mockup App', 'Fezzo', 'App Mockups'),
  ('Fezzo app 4', 'Mockup App', 'Fezzo', 'App Mockups'),
  ('Fezzo 1', 'Mockup Web', 'Fezzo', 'Website Mockups'),
  ('Fezzo 2', 'Mockup Web', 'Fezzo', 'Website Mockups'),
  ('Fezzo 3', 'Mockup Web', 'Fezzo', 'Website Mockups'),
  ('Fezzo 4', 'Mockup Web', 'Fezzo', 'Website Mockups'),
  ('Futurex app 1', 'Mockup App', 'Futurex', 'App Mockups'),
  ('Futurex app 2', 'Mockup App', 'Futurex', 'App Mockups'),
  ('Futurex app 3', 'Mockup App', 'Futurex', 'App Mockups'),
  ('Futurex app 4', 'Mockup App', 'Futurex', 'App Mockups'),
  ('Futurex 1', 'Mockup Web', 'Futurex', 'Website Mockups'),
  ('Futurex 2', 'Mockup Web', 'Futurex', 'Website Mockups'),
  ('Futurex 3', 'Mockup Web', 'Futurex', 'Website Mockups'),
  ('Futurex 4', 'Mockup Web', 'Futurex', 'Website Mockups'),
  ('Futurex 5', 'Mockup Web', 'Futurex', 'Website Mockups'),
  ('G7 holdings 1', 'Mockup App', 'G7 holdings', 'App Mockups'),
  ('G7 holdings 2', 'Mockup App', 'G7 holdings', 'App Mockups'),
  ('G7 holdings 3', 'Mockup App', 'G7 holdings', 'App Mockups'),
  ('G7 holdings 4', 'Mockup App', 'G7 holdings', 'App Mockups'),
  ('Japaneese app 1', 'Mockup App', 'Japaneese', 'App Mockups'),
  ('Japaneese app 2', 'Mockup App', 'Japaneese', 'App Mockups'),
  ('Japaneese app 3', 'Mockup App', 'Japaneese', 'App Mockups'),
  ('Japaneese app 4', 'Mockup App', 'Japaneese', 'App Mockups'),
  ('Japaneese 1', 'Mockup Web', 'Japaneese', 'Website Mockups'),
  ('Japaneese 2', 'Mockup Web', 'Japaneese', 'Website Mockups'),
  ('Japaneese 3', 'Mockup Web', 'Japaneese', 'Website Mockups'),
  ('Japaneese 4', 'Mockup Web', 'Japaneese', 'Website Mockups'),
  ('Just go taxi app 1', 'Mockup App', 'Just go taxi', 'App Mockups'),
  ('Just go taxi app 2', 'Mockup App', 'Just go taxi', 'App Mockups'),
  ('Just go taxi app 3', 'Mockup App', 'Just go taxi', 'App Mockups'),
  ('Just go taxi app 4', 'Mockup App', 'Just go taxi', 'App Mockups'),
  ('Just go taxi 1', 'Mockup Web', 'Just go taxi', 'Website Mockups'),
  ('Just go taxi 2', 'Mockup Web', 'Just go taxi', 'Website Mockups'),
  ('Just go taxi 3', 'Mockup Web', 'Just go taxi', 'Website Mockups'),
  ('Just go taxi 4', 'Mockup Web', 'Just go taxi', 'Website Mockups'),
  ('Klashra 1', 'Mockup App', 'Klashra', 'App Mockups'),
  ('Klashra 2', 'Mockup App', 'Klashra', 'App Mockups'),
  ('Klashra 3', 'Mockup App', 'Klashra', 'App Mockups'),
  ('Klashra 4', 'Mockup App', 'Klashra', 'App Mockups'),
  ('Kug academy app 1', 'Mockup App', 'Kug academy', 'App Mockups'),
  ('Kug academy app 2', 'Mockup App', 'Kug academy', 'App Mockups'),
  ('Kug academy app 3', 'Mockup App', 'Kug academy', 'App Mockups'),
  ('Kug academy app 4', 'Mockup App', 'Kug academy', 'App Mockups'),
  ('KUG Frondent Website 1 Mockup', 'Mockup Web', 'KUG Frondent Website', 'Website Mockups'),
  ('KUG Frondent Website 2 Mockup', 'Mockup Web', 'KUG Frondent Website', 'Website Mockups'),
  ('KUG Frondent Website 3 Mockup', 'Mockup Web', 'KUG Frondent Website', 'Website Mockups'),
  ('KUG Frondent Website 4 Mockup', 'Mockup Web', 'KUG Frondent Website', 'Website Mockups'),
  ('Kug result posrtal app 1', 'Mockup App', 'KUG Resukt Publication Portal', 'App Mockups'),
  ('Kug result posrtal app 2', 'Mockup App', 'KUG Resukt Publication Portal', 'App Mockups'),
  ('Kug result posrtal app 3', 'Mockup App', 'KUG Resukt Publication Portal', 'App Mockups'),
  ('KUG Resukt Publication Portal 1', 'Mockup Web', 'KUG Resukt Publication Portal', 'Website Mockups'),
  ('KUG Resukt Publication Portal 2', 'Mockup Web', 'KUG Resukt Publication Portal', 'Website Mockups'),
  ('KUG Resukt Publication Portal 3', 'Mockup Web', 'KUG Resukt Publication Portal', 'Website Mockups'),
  ('KUG Resukt Publication Portal 4', 'Mockup Web', 'KUG Resukt Publication Portal', 'Website Mockups'),
  ('Little talk app 1', 'Mockup App', 'Little Talks', 'App Mockups'),
  ('Little talk app 2', 'Mockup App', 'Little Talks', 'App Mockups'),
  ('Little talk app 3', 'Mockup App', 'Little Talks', 'App Mockups'),
  ('Little talk app 4', 'Mockup App', 'Little Talks', 'App Mockups'),
  ('Little Talks 1', 'Mockup Web', 'Little Talks', 'Website Mockups'),
  ('Little Talks 2', 'Mockup Web', 'Little Talks', 'Website Mockups'),
  ('Little Talks 3', 'Mockup Web', 'Little Talks', 'Website Mockups'),
  ('Little Talks 4', 'Mockup Web', 'Little Talks', 'Website Mockups'),
  ('Macadz app 1', 'Mockup App', 'Macadz', 'App Mockups'),
  ('Macadz app 2', 'Mockup App', 'Macadz', 'App Mockups'),
  ('Macadz app 3', 'Mockup App', 'Macadz', 'App Mockups'),
  ('Macadz app 4', 'Mockup App', 'Macadz', 'App Mockups'),
  ('Macadz 1', 'Mockup Web', 'Macadz', 'Website Mockups'),
  ('Macadz 2', 'Mockup Web', 'Macadz', 'Website Mockups'),
  ('Macadz 3', 'Mockup Web', 'Macadz', 'Website Mockups'),
  ('Macadz 4', 'Mockup Web', 'Macadz', 'Website Mockups'),
  ('Medosuite app 1', 'Mockup App', 'Medosuite', 'App Mockups'),
  ('Medosuite app 2', 'Mockup App', 'Medosuite', 'App Mockups'),
  ('Medosuite app 3', 'Mockup App', 'Medosuite', 'App Mockups'),
  ('Medosuite app 4', 'Mockup App', 'Medosuite', 'App Mockups'),
  ('MEdosuit 1', 'Mockup Web', 'Medosuite', 'Website Mockups'),
  ('MEdosuit 2', 'Mockup Web', 'Medosuite', 'Website Mockups'),
  ('MEdosuit 3', 'Mockup Web', 'Medosuite', 'Website Mockups'),
  ('MEdosuit 4', 'Mockup Web', 'Medosuite', 'Website Mockups'),
  ('Multy safety app 1', 'Mockup App', 'Multy safety', 'App Mockups'),
  ('Multy safety app 2', 'Mockup App', 'Multy safety', 'App Mockups'),
  ('Multy safety app 3', 'Mockup App', 'Multy safety', 'App Mockups'),
  ('Multy safety app 4', 'Mockup App', 'Multy safety', 'App Mockups'),
  ('Multy safety 1', 'Mockup Web', 'Multy safety', 'Website Mockups'),
  ('Multy safety 2', 'Mockup Web', 'Multy safety', 'Website Mockups'),
  ('Multy safety 3', 'Mockup Web', 'Multy safety', 'Website Mockups'),
  ('Multy safety 4', 'Mockup Web', 'Multy safety', 'Website Mockups'),
  ('Nexaar app 1', 'Mockup App', 'Nexaar', 'App Mockups'),
  ('Nexaar app 2', 'Mockup App', 'Nexaar', 'App Mockups'),
  ('Nexaar app 3', 'Mockup App', 'Nexaar', 'App Mockups'),
  ('Nexaar app 4', 'Mockup App', 'Nexaar', 'App Mockups'),
  ('Nexaar 1', 'Mockup Web', 'Nexaar', 'Website Mockups'),
  ('Nexaar 2', 'Mockup Web', 'Nexaar', 'Website Mockups'),
  ('Nexaar 3', 'Mockup Web', 'Nexaar', 'Website Mockups'),
  ('Nexaar 4', 'Mockup Web', 'Nexaar', 'Website Mockups'),
  ('Next Gen app 1', 'Mockup App', 'Next gen', 'App Mockups'),
  ('Next Gen app 2', 'Mockup App', 'Next gen', 'App Mockups'),
  ('Next Gen app 3', 'Mockup App', 'Next gen', 'App Mockups'),
  ('Next Gen app 4', 'Mockup App', 'Next gen', 'App Mockups'),
  ('Next gen 1', 'Mockup Web', 'Next gen', 'Website Mockups'),
  ('Next gen 2', 'Mockup Web', 'Next gen', 'Website Mockups'),
  ('Next gen 3', 'Mockup Web', 'Next gen', 'Website Mockups'),
  ('Next gen 4', 'Mockup Web', 'Next gen', 'Website Mockups'),
  ('Nurse X Pro 1', 'Mockup App', 'Nurse X Pro', 'App Mockups'),
  ('Nurse X Pro 2', 'Mockup App', 'Nurse X Pro', 'App Mockups'),
  ('Nurse X Pro 3', 'Mockup App', 'Nurse X Pro', 'App Mockups'),
  ('Nurse X Pro 4', 'Mockup App', 'Nurse X Pro', 'App Mockups'),
  ('Nurse Xpro 1', 'Mockup Web', 'Nurse X Pro', 'Website Mockups'),
  ('Nurse Xpro 2', 'Mockup Web', 'Nurse X Pro', 'Website Mockups'),
  ('Nurse Xpro 3', 'Mockup Web', 'Nurse X Pro', 'Website Mockups'),
  ('Nurse Xpro 4', 'Mockup Web', 'Nurse X Pro', 'Website Mockups'),
  ('Pvk app 1', 'Mockup App', 'PVK', 'App Mockups'),
  ('Pvk app 2', 'Mockup App', 'PVK', 'App Mockups'),
  ('Pvk app 3', 'Mockup App', 'PVK', 'App Mockups'),
  ('Pvk app 4', 'Mockup App', 'PVK', 'App Mockups'),
  ('PVK 1', 'Mockup Web', 'PVK', 'Website Mockups'),
  ('PVK 2', 'Mockup Web', 'PVK', 'Website Mockups'),
  ('PVK 3', 'Mockup Web', 'PVK', 'Website Mockups'),
  ('PVK 4', 'Mockup Web', 'PVK', 'Website Mockups'),
  ('Qmentr 1', 'Mockup App', 'Qmentr', 'App Mockups'),
  ('Qmentr 2', 'Mockup App', 'Qmentr', 'App Mockups'),
  ('Qmentr 3', 'Mockup App', 'Qmentr', 'App Mockups'),
  ('Qmentr 4', 'Mockup App', 'Qmentr', 'App Mockups'),
  ('Sindal app 1', 'Mockup App', 'Sindal', 'App Mockups'),
  ('Sindal app 2', 'Mockup App', 'Sindal', 'App Mockups'),
  ('Sindal app 3', 'Mockup App', 'Sindal', 'App Mockups'),
  ('Sindal app 4', 'Mockup App', 'Sindal', 'App Mockups'),
  ('Sindal1', 'Mockup Web', 'Sindal', 'Website Mockups'),
  ('Sindal2', 'Mockup Web', 'Sindal', 'Website Mockups'),
  ('Sindal3', 'Mockup Web', 'Sindal', 'Website Mockups'),
  ('Sindal4', 'Mockup Web', 'Sindal', 'Website Mockups'),
  ('Skillmount 1', 'Mockup App', 'Skillmount', 'App Mockups'),
  ('Skillmount 2', 'Mockup App', 'Skillmount', 'App Mockups'),
  ('Skillmount 3', 'Mockup App', 'Skillmount', 'App Mockups'),
  ('Skillmount 4', 'Mockup App', 'Skillmount', 'App Mockups'),
  ('Skill mount 1', 'Mockup Web', 'Skillmount', 'Website Mockups'),
  ('Skill mount 2', 'Mockup Web', 'Skillmount', 'Website Mockups'),
  ('Skill mount 3', 'Mockup Web', 'Skillmount', 'Website Mockups'),
  ('Skill mount 4', 'Mockup Web', 'Skillmount', 'Website Mockups'),
  ('Vsaf app 1', 'Mockup App', 'VSAF', 'App Mockups'),
  ('Vsaf app 2', 'Mockup App', 'VSAF', 'App Mockups'),
  ('Vsaf app 3', 'Mockup App', 'VSAF', 'App Mockups'),
  ('Vsaf app 4', 'Mockup App', 'VSAF', 'App Mockups'),
  ('VSAF 1', 'Mockup Web', 'VSAF', 'Website Mockups'),
  ('VSAF 2', 'Mockup Web', 'VSAF', 'Website Mockups'),
  ('VSAF 3', 'Mockup Web', 'VSAF', 'Website Mockups'),
  ('VSAF 4', 'Mockup Web', 'VSAF', 'Website Mockups'),
  ('Thazque islamic study 1', 'Mockup App', 'Zeeque islamic Study', 'App Mockups'),
  ('Thazque islamic study 2', 'Mockup App', 'Zeeque islamic Study', 'App Mockups'),
  ('Thazque islamic study 3', 'Mockup App', 'Zeeque islamic Study', 'App Mockups'),
  ('Thazque islamic study 4', 'Mockup App', 'Zeeque islamic Study', 'App Mockups'),
  ('Tasqu Islamic Study 1 Mockup', 'Mockup Web', 'Zeeque islamic Study', 'Website Mockups'),
  ('Tasqu Islamic Study 2 Mockup', 'Mockup Web', 'Zeeque islamic Study', 'Website Mockups'),
  ('Tasqu Islamic Study 3 Mockup', 'Mockup Web', 'Zeeque islamic Study', 'Website Mockups'),
  ('Tasqu Islamic Study 4 Mockup', 'Mockup Web', 'Zeeque islamic Study', 'Website Mockups'),
  ('Zeeque magazine 1', 'Mockup App', 'Zeeque Magazine', 'App Mockups'),
  ('Zeeque magazine 2', 'Mockup App', 'Zeeque Magazine', 'App Mockups'),
  ('Zeeque magazine 3', 'Mockup App', 'Zeeque Magazine', 'App Mockups'),
  ('Zeeque magazine 4', 'Mockup App', 'Zeeque Magazine', 'App Mockups'),
  ('Zeeque Magazine 1', 'Mockup Web', 'Zeeque Magazine', 'Website Mockups'),
  ('Zeeque Magazine 2', 'Mockup Web', 'Zeeque Magazine', 'Website Mockups'),
  ('Zeeque Magazine 3', 'Mockup Web', 'Zeeque Magazine', 'Website Mockups'),
  ('Zeeque Magazine 4', 'Mockup Web', 'Zeeque Magazine', 'Website Mockups'),
  ('Zeeque Plus LMS Mockup 1', 'Mockup Web', 'Zeeque Plus LMS', 'Website Mockups'),
  ('Zeeque Plus LMS Mockup 2', 'Mockup Web', 'Zeeque Plus LMS', 'Website Mockups'),
  ('Zeeque Plus LMS Mockup 3', 'Mockup Web', 'Zeeque Plus LMS', 'Website Mockups'),
  ('Zeeque plus website 1', 'Mockup App', 'Zeeque Plus Website', 'App Mockups'),
  ('Zeeque plus website 2', 'Mockup App', 'Zeeque Plus Website', 'App Mockups'),
  ('Zeeque plus website 3', 'Mockup App', 'Zeeque Plus Website', 'App Mockups'),
  ('Zeeque plus website 4', 'Mockup App', 'Zeeque Plus Website', 'App Mockups'),
  ('Zeeque Plus Website 1', 'Mockup Web', 'Zeeque Plus Website', 'Website Mockups'),
  ('Zeeque Plus Website 2', 'Mockup Web', 'Zeeque Plus Website', 'Website Mockups'),
  ('Zeeque Plus Website 3', 'Mockup Web', 'Zeeque Plus Website', 'Website Mockups'),
  ('Zeeque Plus Website 4', 'Mockup Web', 'Zeeque Plus Website', 'Website Mockups'),
  ('Zeeque pre school 1', 'Mockup App', 'Zeeque Pre School Website', 'App Mockups'),
  ('Zeeque pre school 2', 'Mockup App', 'Zeeque Pre School Website', 'App Mockups'),
  ('Zeeque pre school 3', 'Mockup App', 'Zeeque Pre School Website', 'App Mockups'),
  ('Zeeque pre school 4', 'Mockup App', 'Zeeque Pre School Website', 'App Mockups'),
  ('Zeeque pre school app 1', 'Mockup App', 'Zeeque Pre School Website', 'App Mockups'),
  ('Zeeque pre school app 2', 'Mockup App', 'Zeeque Pre School Website', 'App Mockups'),
  ('Zeeque pre school app 3', 'Mockup App', 'Zeeque Pre School Website', 'App Mockups'),
  ('Zeeque pre school app 4', 'Mockup App', 'Zeeque Pre School Website', 'App Mockups'),
  ('Zeequ PreeSchool Website 1', 'Mockup Web', 'Zeeque Pre School Website', 'Website Mockups'),
  ('Zeequ PreeSchool Website 2', 'Mockup Web', 'Zeeque Pre School Website', 'Website Mockups'),
  ('Zeequ PreeSchool Website 3', 'Mockup Web', 'Zeeque Pre School Website', 'Website Mockups'),
  ('Zeequ PreeSchool Website 4', 'Mockup Web', 'Zeeque Pre School Website', 'Website Mockups');

INSERT INTO tmp_mockup_folder_alias (project_key, folder_name) VALUES
  ('2.0', '2.0'),
  ('Albedo Calc', 'Albedo Calc'),
  ('Albedo Educator', 'Albedo Educator'),
  ('Albedo Operations', 'Albedo Operations'),
  ('Albedo Operations', 'Albedo Operation'),
  ('Albedo Support', 'Albedo Support'),
  ('Bin Jabreen', 'Bin Jabreen'),
  ('Bin Jabreen', 'Binjabreen'),
  ('CBMS', 'CBMS'),
  ('CBMS MARiage fund', 'CBMS MARiage fund'),
  ('CBMS MARiage fund', 'CBMS Marriage fund'),
  ('Codo academy', 'Codo academy'),
  ('Codo ai', 'Codo ai'),
  ('Darna', 'Darna'),
  ('Dsign', 'Dsign'),
  ('Europe calling', 'Europe calling'),
  ('Evoka Schoole', 'Evoka Schoole'),
  ('Evoka Schoole', 'Evoka School'),
  ('Evoka communication', 'Evoka communication'),
  ('Fezzo', 'Fezzo'),
  ('Futurex', 'Futurex'),
  ('G7 holdings', 'G7 holdings'),
  ('Japaneese', 'Japaneese'),
  ('Just go taxi', 'Just go taxi'),
  ('KUG Frondent Website', 'KUG Frondent Website'),
  ('KUG Frondent Website', 'KUG Frontend Website'),
  ('KUG Resukt Publication Portal', 'KUG Resukt Publication Portal'),
  ('KUG Resukt Publication Portal', 'KUG Result Publication Portal'),
  ('Klashra', 'Klashra'),
  ('Kug academy', 'Kug academy'),
  ('Little Talks', 'Little Talks'),
  ('Macadz', 'Macadz'),
  ('Medosuite', 'Medosuite'),
  ('Medosuite', 'MEdosuit'),
  ('Multy safety', 'Multy safety'),
  ('Nexaar', 'Nexaar'),
  ('Next gen', 'Next gen'),
  ('Nurse X Pro', 'Nurse X Pro'),
  ('Nurse X Pro', 'Nurse Xpro'),
  ('PVK', 'PVK'),
  ('Qmentr', 'Qmentr'),
  ('Sindal', 'Sindal'),
  ('Skillmount', 'Skillmount'),
  ('Skillmount', 'Skill mount'),
  ('VSAF', 'VSAF'),
  ('Zeeque Magazine', 'Zeeque Magazine'),
  ('Zeeque Plus LMS', 'Zeeque Plus LMS'),
  ('Zeeque Plus Website', 'Zeeque Plus Website'),
  ('Zeeque Pre School Website', 'Zeeque Pre School Website'),
  ('Zeeque Pre School Website', 'Zeeque Preschool Website'),
  ('Zeeque Pre School Website', 'Zeequ PreeSchool Website'),
  ('Zeeque islamic Study', 'Zeeque islamic Study'),
  ('Zeeque islamic Study', 'Tasqu Islamic Study');

CREATE TEMPORARY TABLE tmp_mockup_resolved AS
SELECT
  tm.asset_title,
  tm.material_type,
  tm.project_key,
  tm.preferred_subfolder,
  pf.project_folder_id,
  sub.id AS subfolder_id,
  COALESCE(sub.id, pf.project_folder_id) AS target_folder_id
FROM tmp_mockup_title_map tm
INNER JOIN (
  SELECT
    fa.project_key,
    MIN(f.id) AS project_folder_id
  FROM tmp_mockup_folder_alias fa
  INNER JOIN creative_folders agency
    ON agency.parent_id IS NULL
   AND LOWER(TRIM(agency.name)) = 'codo agency'
  INNER JOIN creative_folders mockup
    ON mockup.parent_id = agency.id
   AND LOWER(TRIM(mockup.name)) = 'mockup'
  INNER JOIN creative_folders f
    ON f.parent_id = mockup.id
   AND LOWER(TRIM(f.name)) = LOWER(TRIM(fa.folder_name))
  GROUP BY fa.project_key
) pf ON pf.project_key = tm.project_key
LEFT JOIN creative_folders sub
  ON sub.parent_id = pf.project_folder_id
 AND LOWER(TRIM(sub.name)) = LOWER(TRIM(tm.preferred_subfolder));

-- ---------- PREVIEW ----------
SELECT
  a.id AS asset_id,
  a.title,
  a.material_type,
  a.folder_id AS current_folder_id,
  cur.name AS current_folder_name,
  r.project_key,
  r.preferred_subfolder,
  r.target_folder_id,
  tgt.name AS target_folder_name,
  CASE
    WHEN a.folder_id <=> r.target_folder_id THEN 'KEEP'
    ELSE 'MOVE'
  END AS action
FROM creative_assets a
INNER JOIN tmp_mockup_resolved r
  ON LOWER(TRIM(a.title)) = LOWER(TRIM(r.asset_title))
 AND a.material_type = r.material_type
LEFT JOIN creative_folders cur ON cur.id = a.folder_id
LEFT JOIN creative_folders tgt ON tgt.id = r.target_folder_id
WHERE a.material_type IN ('Mockup App', 'Mockup Web')
ORDER BY action DESC, r.project_key, a.title;

-- ---------- APPLY ----------
UPDATE creative_assets a
INNER JOIN tmp_mockup_resolved r
  ON LOWER(TRIM(a.title)) = LOWER(TRIM(r.asset_title))
 AND a.material_type = r.material_type
SET a.folder_id = r.target_folder_id
WHERE a.material_type IN ('Mockup App', 'Mockup Web')
  AND r.target_folder_id IS NOT NULL
  AND (a.folder_id IS NULL OR a.folder_id <> r.target_folder_id);

SELECT ROW_COUNT() AS assets_moved;

DROP TEMPORARY TABLE IF EXISTS tmp_mockup_resolved;
DROP TEMPORARY TABLE IF EXISTS tmp_mockup_folder_alias;
DROP TEMPORARY TABLE IF EXISTS tmp_mockup_title_map;

COMMIT;
