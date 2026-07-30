-- Extended Common CODO: developer rules 26–32 + 6 new QA stress rules.
-- Safe to re-run (INSERT IGNORE). Backfills project_compliance_checks for existing projects.

-- ── Developer rules 26–32 ──────────────────────────────────────────────────
INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_26', 'SPA Router History Sync', 'Rule 26',
 'Modals, side drawers, and deep tabs must push state to router history. Clicking browser "Back" must close overlays sequentially instead of exiting the dashboard view.\n\nMalayalam: ബ്രൗസറിന്റെ "Back" ബട്ടൺ അടിക്കുമ്പോൾ ആപ്പ് ക്ലോസ് ആവാതെ മുൻപത്തെ മോഡലോ ടാബോ ഓർഡറിൽ ക്ലോസ് ആവണം.',
 26, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_27', 'Strict Test-Data Clearance', 'Rule 27',
 'Never leave dummy records (e.g., "test", "asdf") in production DB. Mock tests must run inside seeded development environments only.\n\nMalayalam: പ്രൊഡക്ഷൻ ഡാറ്റാബേസിൽ "test", "asdf" തുടങ്ങിയ അനാവശ്യ എൻട്രികൾ ഒരിക്കലും ഇടരുത്.',
 27, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_28', 'Layout Alignment Containment', 'Rule 28',
 'Use flex-wrap and responsive grid clamps. Dynamic content length must never break container dimensions or cause page overflow shifts.\n\nMalayalam: ഡാറ്റ കൂടുമ്പോൾ ഡിസൈൻ അലൈൻമെന്റ് തെറ്റാനോ വെറുതെ സ്പേസ് വരാനോ പാടില്ല.',
 28, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_29', 'Dynamic Status Feedback Toast', 'Rule 29',
 'Optimistic UI updates must revert instantly with a Toast error if the backend API fails to persist state changes.\n\nMalayalam: സ്റ്റാറ്റസ് ചേഞ്ച് ചെയ്യുമ്പോൾ ബാക്ക്എൻഡിൽ മാറിയില്ലെങ്കിൽ സ്ക്രീനിൽ ഉടൻ എറർ ടോസ്റ്റ് കാണിക്കണം.',
 29, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_30', 'RTL Typography Safeguards', 'Rule 30',
 'Localized Arabic strings must maintain explicit dir="rtl" containers and CSS logical properties (margin-inline-start).\n\nMalayalam: അറബിക് ഫീൽഡുകൾ റൈറ്റ്-ടു-ലെഫ്റ്റ് (dir="rtl") ആയി കൃത്യമായ ഫോർമാറ്റിൽ ആയിരിക്കണം.',
 30, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_31', 'Native Scrollbar Preservation', 'Rule 31',
 'Never use global overflow: hidden on container scroll wrappers without fallback custom slim scrollbars.\n\nMalayalam: ലിസ്റ്റ് വലിയതായാൽ സ്ക്രോൾബാർ അപ്രത്യക്ഷമാവരുത്; Codo സ്ലിം സ്ക്രോൾബാർ കാണിച്ചിരിക്കണം.',
 31, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_32', 'Immutable Array Sorting', 'Rule 32',
 'Sorting operations on datasets must create explicit copy instances ([...data].sort()) or perform sorting at database query level.\n\nMalayalam: ഡാറ്റാബേസിൽ നിന്നോ അറേയിൽ നിന്നോ ഓർഡർ കാണിക്കുമ്പോൾ ഡാറ്റ തെറ്റിയ മുൻഗണനയിൽ വരാൻ പാടില്ല.',
 32, 1);

-- ── New QA stress rules 8–13 ───────────────────────────────────────────────
INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('tester', 'qa_console_zero', 'Console Zero-Tolerance', 'QA Stress 8',
 'Keep Browser DevTools open (F12) during verification. Reject the build if ANY red error appears in the console.\n\nMalayalam: ടെസ്റ്റ് ചെയ്യുമ്പോൾ DevTools (F12) തുറന്ന് വയ്ക്കുക. കൺസോളിൽ റെഡ് എറർ വന്നാൽ ബിൽഡ് റിജക്ട് ചെയ്യണം.',
 8, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('tester', 'qa_high_volume', 'High-Volume Scale Audit', 'QA Stress 9',
 'Load views with 100+ records. Reject if pagination is missing or the UI stutters under load.\n\nMalayalam: 100+ റെക്കോർഡുകളുള്ള വ്യൂകൾ ലോഡ് ചെയ്യുക. പേജിനേഷൻ ഇല്ലെങ്കിലോ UI സ്റ്റട്ടർ ആയാലോ റിജക്ട് ചെയ്യുക.',
 9, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('tester', 'qa_script_injection', 'Script Injection Test', 'QA Stress 10',
 'Input <script>alert(''xss'')</script> into form fields. Reject if the string executes or breaks layout rendering.\n\nMalayalam: ഫോം ഫീൽഡുകളിൽ സ്ക്രിപ്റ്റ് ഇൻജക്ഷൻ സ്ട്രിംഗ് നൽകുക. എക്സിക്യൂട്ട് ആയാലോ ലേഔട്ട് തകർന്നാലോ റിജക്ട് ചെയ്യുക.',
 10, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('tester', 'qa_modal_scope', 'Modal Overlay Scope', 'QA Stress 11',
 'Verify Small (400px) modals for deletes, Medium (600px) for standard forms, and Large (950px+) for complex data.\n\nMalayalam: Delete-ന് Small (400px), സാധാരണ ഫോമുകൾക്ക് Medium (600px), കോംപ്ലക്സ് ഡാറ്റയ്ക്ക് Large (950px+) മോഡലുകൾ ഉറപ്പാക്കുക.',
 11, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('tester', 'qa_rtl_stress', 'RTL Language Stress Test', 'QA Stress 12',
 'Enter Arabic strings mixed with numbers. Reject if carets misalign or numbers reverse order.\n\nMalayalam: അറബിക് ടെക്സ്റ്റും നമ്പറുകളും കലർത്തി ടെസ്റ്റ് ചെയ്യുക. കാരറ്റ്/നമ്പർ അലൈൻമെന്റ് തെറ്റിയാൽ റിജക്ട് ചെയ്യുക.',
 12, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('tester', 'qa_browser_back', 'Browser Back Button Drill', 'QA Stress 13',
 'Open layered overlays and press browser Back. Reject if the app exits the view instead of closing the top modal.\n\nMalayalam: ലെയർഡ് ഓവർലേകൾ തുറന്ന് browser Back അമർത്തുക. ടോപ്പ് മോഡൽ അടയ്ക്കാതെ പേജ് വിട്ടാൽ റിജക്ട് ചെയ്യുക.',
 13, 1);

-- Align existing QA wording with PROMPT clarity (keys unchanged)
UPDATE `codo_common_rules`
SET `description` = 'Stress-test button structures via continuous high-speed double and triple clicks. Ensure execution locks prevent duplicate API records. Reject if duplicate calls fire or spinner is missing.\n\nMalayalam: ബട്ടണുകളിൽ തുടർച്ചയായ ഉയർന്ന വേഗത്തിലുള്ള ഡബിൾ/ട്രിപ്പിൾ ക്ലിക്കുകൾ ചെയ്ത് സ്ട്രെസ്-ടെസ്റ്റ് ചെയ്യുക. ഡ്യൂപ്ലിക്കേറ്റ് API റെക്കോർഡുകൾ തടയുന്ന ലോക്കുകൾ ഉറപ്പാക്കുക.'
WHERE `phase` = 'tester' AND `rule_key` = 'qa_click_attack';

UPDATE `codo_common_rules`
SET `description` = 'Test layouts on Safari / iOS WebKit. Reject if flexbox elements, custom scrollbars, or shadows break.\n\nMalayalam: Safari പരിതസ്ഥിതികളിലുടനീളം ക്രോസ്-പ്ലാറ്റ്ഫോം ലേഔട്ട് റെൻഡറിംഗ് പരിശോധിക്കുക. കാർഡ് റേഡിയസ്, ഷാഡോ ബൗണ്ടറികൾ തകരാതെ സ്കെയിൽ ചെയ്യുന്നുണ്ടെന്ന് ഉറപ്പാക്കുക.'
WHERE `phase` = 'tester' AND `rule_key` = 'qa_apple_sandbox';

-- ── Backfill compliance checks for existing projects ───────────────────────
INSERT IGNORE INTO `project_compliance_checks` (`project_id`, `phase`, `rule_key`, `verified`)
SELECT pc.`project_id`, 'developer', k.`rule_key`, 0
FROM `project_compliance` pc
CROSS JOIN (
  SELECT 'dev_rule_26' AS `rule_key` UNION ALL
  SELECT 'dev_rule_27' UNION ALL
  SELECT 'dev_rule_28' UNION ALL
  SELECT 'dev_rule_29' UNION ALL
  SELECT 'dev_rule_30' UNION ALL
  SELECT 'dev_rule_31' UNION ALL
  SELECT 'dev_rule_32'
) k;

INSERT IGNORE INTO `project_compliance_checks` (`project_id`, `phase`, `rule_key`, `verified`)
SELECT pc.`project_id`, 'tester', k.`rule_key`, 0
FROM `project_compliance` pc
CROSS JOIN (
  SELECT 'qa_console_zero' AS `rule_key` UNION ALL
  SELECT 'qa_high_volume' UNION ALL
  SELECT 'qa_script_injection' UNION ALL
  SELECT 'qa_modal_scope' UNION ALL
  SELECT 'qa_rtl_stress' UNION ALL
  SELECT 'qa_browser_back'
) k;
