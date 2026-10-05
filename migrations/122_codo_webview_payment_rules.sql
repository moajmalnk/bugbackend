-- CODO DEV-68 + QA-35: Embedded WebView payment gateway & UPI app-switch rules
-- Idempotent: INSERT IGNORE + open-project compliance backfill

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_68', 'Embedded WebView Payment Gateway & Intent Scheme Interception', 'Rule 68',
 'In Flutter/native WebViews that host payment gateways, select the User-Agent dynamically by platform (Android → Mobile Chrome; iOS → Safari). Explicitly intercept non-HTTP(S) schemes such as upi://, intent://, and gpay://: deny in-WebView navigation and delegate to the external app with LaunchMode.externalApplication so UPI/app grids populate and deep-link returns succeed.\n\nMalayalam: പേയ്മെന്റ് ഗേറ്റ്‌വേ ഹോസ്റ്റ് ചെയ്യുന്ന Flutter/native WebView-കളിൽ User-Agent പ്ലാറ്റ്‌ഫോം അനുസരിച്ച് ഡൈനാമിക് ആയി തിരഞ്ഞെടുക്കുക (Android → Mobile Chrome; iOS → Safari). upi://, intent://, gpay:// പോലുള്ള non-HTTP(S) സ്കീമുകൾ WebView-യിൽ ALLOW ചെയ്യരുത്; LaunchMode.externalApplication വഴി ബാഹ്യ ആപ്പിലേക്ക് ഡെലിഗേറ്റ് ചെയ്യുക.',
 68, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('tester', 'qa_in_app_payment_upi', 'In-App Payment Gateway & UPI App Switch Drill', 'QA Stress 35',
 'On physical Android and iOS devices, open an in-app payment gateway WebView and verify: UPI/payment app grids are populated on both platforms, custom-scheme intents (upi://, intent://, gpay://) trigger an external app handshake, and return deep-link callbacks restore session integrity without forcing re-login or losing payment context. Reject emulator-only verification or a blank UPI grid.\n\nMalayalam: യഥാർത്ഥ Android/iOS ഡിവൈസുകളിൽ in-app പേയ്മെന്റ് WebView തുറന്ന് പരിശോധിക്കുക: രണ്ട് പ്ലാറ്റ്‌ഫോമിലും UPI ആപ്പ് ഗ്രിഡ് നിറഞ്ഞിരിക്കണം, intent ട്രിഗറുകൾ ബാഹ്യ ആപ്പ് ഹാൻഡ്‌ഷേക്ക് ചെയ്യണം, റിട്ടേൺ കോൾബാക്ക് സെഷൻ നഷ്ടപ്പെടാതെ നിലനിർത്തണം. എമുലേറ്റർ മാത്രം ടെസ്റ്റ് ചെയ്താലോ ശൂന്യ UPI ഗ്രിഡ് വന്നാലോ റിജക്ട് ചെയ്യുക.',
 35, 1);

-- ── Backfill compliance checks for open projects ────────────────────────────
INSERT IGNORE INTO `project_compliance_checks` (`project_id`, `phase`, `rule_key`, `verified`)
SELECT pc.`project_id`, 'developer', k.`rule_key`, 0
FROM `project_compliance` pc
JOIN `projects` p ON p.`id` = pc.`project_id`
CROSS JOIN (
  SELECT 'dev_rule_68' AS `rule_key`
) k
WHERE p.`status` NOT IN ('completed', 'release_ready', 'archived')
  AND pc.`pipeline_stage` <> 'admin_ready';

INSERT IGNORE INTO `project_compliance_checks` (`project_id`, `phase`, `rule_key`, `verified`)
SELECT pc.`project_id`, 'tester', k.`rule_key`, 0
FROM `project_compliance` pc
JOIN `projects` p ON p.`id` = pc.`project_id`
CROSS JOIN (
  SELECT 'qa_in_app_payment_upi' AS `rule_key`
) k
WHERE p.`status` NOT IN ('completed', 'release_ready', 'archived')
  AND pc.`pipeline_stage` <> 'admin_ready';
