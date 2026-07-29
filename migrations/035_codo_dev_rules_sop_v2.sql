-- Sync developer CODO rules (dev_rule_1…25) to Master SOP v2.0 Requirement text.
-- Safe to re-run. Does not touch tester/project rules or acknowledgements.

UPDATE `codo_common_rules`
SET `title` = 'Hard State Reset',
    `subtitle` = 'Rule 1',
    `description` = 'Reset components cleanly on form submission and modal unmount (useEffect cleanup). Old inputs must never bleed into next entries.\n\nMalayalam: ഫോം സബ്മിറ്റ് ചെയ്താലോ മോഡൽ ക്ലോസ് ചെയ്താലോ ഫീൽഡുകൾ പൂർണ്ണമായും ക്ലിയർ ചെയ്യണം.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_1';

UPDATE `codo_common_rules`
SET `title` = 'Real-Time Input Validation',
    `subtitle` = 'Rule 2',
    `description` = 'Execute inline validation feedback dynamically before form submission.\n\nMalayalam: യൂസർ സബ്മിറ്റ് ചെയ്യുന്നതിന് മുൻപ് തന്നെ ഇൻലൈൻ എറർ ഫീഡ്ബാക്ക് കാണിക്കണം.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_2';

UPDATE `codo_common_rules`
SET `title` = 'Persistent Input Protection',
    `subtitle` = 'Rule 3',
    `description` = 'Intercept backdrop clicks or page navigation if form is dirty with an Unsaved Changes warning.\n\nMalayalam: ഫോം പൂരിപ്പിക്കുന്നതിനിടയിൽ മാറിയാൽ Unsaved Changes വാണിംഗ് കാണിക്കണം.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_3';

UPDATE `codo_common_rules`
SET `title` = 'Data-Clear Verification',
    `subtitle` = 'Rule 4',
    `description` = 'Never rely on native browser cache for resetting inputs; explicitly wipe local state arrays upon cancel/submit.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_4';

UPDATE `codo_common_rules`
SET `title` = 'Numeric Character Constraints',
    `subtitle` = 'Rule 5',
    `description` = 'Hard-clamp phone numbers and national IDs (maxLength=10 or 15). Prevent typing infinite numbers.\n\nMalayalam: ഫോൺ നമ്പറുകൾ 10 ഡിജിറ്റിൽ കൂടുതൽ ടൈപ്പ് ചെയ്യാൻ അനുവദിക്കരുത്.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_5';

UPDATE `codo_common_rules`
SET `title` = 'Sanitization Defenses',
    `subtitle` = 'Rule 6',
    `description` = 'Escape and validate inputs on both Frontend and Backend to block SQLi and XSS (script injection).'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_6';

UPDATE `codo_common_rules`
SET `title` = 'Length Guardrails',
    `subtitle` = 'Rule 7',
    `description` = 'Provide frontend validation masks matching backend database column constraints.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_7';

UPDATE `codo_common_rules`
SET `title` = 'Anti-Double Click Lockout',
    `subtitle` = 'Rule 8',
    `description` = 'Disable action buttons instantly on click and display a loading spinner.\n\nMalayalam: ക്ലിക്ക് ചെയ്ത ഉടൻ ബട്ടൺ ഡിസേബിൾ ആയി സ്പിന്നർ കാണിക്കണം.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_8';

UPDATE `codo_common_rules`
SET `title` = 'Mandatory Deletion Gating',
    `subtitle` = 'Rule 9',
    `description` = 'Never run destructive API calls directly. Require a Small (400px) confirmation modal.\n\nMalayalam: Delete അമർത്തുമ്പോൾ 400px കൺഫർമേഷൻ മോഡൽ വഴി അനുമതി വാങ്ങിയിരിക്കണം.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_9';

UPDATE `codo_common_rules`
SET `title` = 'Submit Button Lock',
    `subtitle` = 'Rule 10',
    `description` = 'Disable the submit button until all required field validations evaluate to true.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_10';

UPDATE `codo_common_rules`
SET `title` = 'The CODO Corner',
    `subtitle` = 'Rule 11',
    `description` = 'All UI containers, buttons, and cards MUST use rounded-xl (12px) or rounded-2xl (16px). Sharp edges are forbidden.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_11';

UPDATE `codo_common_rules`
SET `title` = '12-Column Grid Alignment',
    `subtitle` = 'Rule 12',
    `description` = 'Layouts must conform to a 12-column grid system with explicit gap-4 or gap-6 spacing.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_12';

UPDATE `codo_common_rules`
SET `title` = 'Whitespace Isolation',
    `subtitle` = 'Rule 13',
    `description` = 'Never declare dynamic spacing utilities (mb-X, pb-X) inside .map() array loops. Use parent grid/flex gap properties.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_13';

UPDATE `codo_common_rules`
SET `title` = 'Viewport Scroll Defenses',
    `subtitle` = 'Rule 14',
    `description` = 'Never apply global overflow: hidden on the root body. Implement CODO''s custom slim scrollbar styles.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_14';

UPDATE `codo_common_rules`
SET `title` = 'Theme Integrity',
    `subtitle` = 'Rule 15',
    `description` = 'Test every background/text utility to ensure seamless contrast scaling across Dark Mode and Light Mode.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_15';

UPDATE `codo_common_rules`
SET `title` = 'Bidirectional Text Safety',
    `subtitle` = 'Rule 16',
    `description` = 'Multi-language inputs handling Arabic must explicitly set dir="rtl" and preserve caret/number alignment.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_16';

UPDATE `codo_common_rules`
SET `title` = 'Custom Picker Normalization',
    `subtitle` = 'Rule 17',
    `description` = 'Date and time pickers must handle null/undefined states cleanly and map display formatting separately from ISO payloads.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_17';

UPDATE `codo_common_rules`
SET `title` = 'Strict Data Sorting',
    `subtitle` = 'Rule 18',
    `description` = 'All database query layers must include explicit ordering (ORDER BY created_at DESC). Random listing order is unacceptable.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_18';

UPDATE `codo_common_rules`
SET `title` = 'Skeleton Shimmer Loaders',
    `subtitle` = 'Rule 19',
    `description` = 'Never render a blank screen or plain text spinner during data fetch. Use layout-matching Skeleton Shimmers.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_19';

UPDATE `codo_common_rules`
SET `title` = '1.5-Second Threshold',
    `subtitle` = 'Rule 20',
    `description` = 'Maintain main-thread execution under 1.5s via WebP images, lazy loading, asset compression, and active PWA service workers.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_20';

UPDATE `codo_common_rules`
SET `title` = 'Database Indexing',
    `subtitle` = 'Rule 21',
    `description` = 'Any database column used in WHERE, JOIN, ORDER BY, or GROUP BY must be explicitly indexed.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_21';

UPDATE `codo_common_rules`
SET `title` = 'High-Volume Scale',
    `subtitle` = 'Rule 22',
    `description` = 'Tables expecting more than 100 entries must implement server-side Pagination or Infinite Scroll bounds.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_22';

UPDATE `codo_common_rules`
SET `title` = 'Console Scrubbing',
    `subtitle` = 'Rule 23',
    `description` = 'Strip all console.log(), print(), or dd() debug statements before pushing or planning output.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_23';

UPDATE `codo_common_rules`
SET `title` = 'Secret Variable Isolation',
    `subtitle` = 'Rule 24',
    `description` = 'All API keys and secrets must reside strictly in .env and be excluded via .gitignore.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_24';

UPDATE `codo_common_rules`
SET `title` = 'Documentation Mandate',
    `subtitle` = 'Rule 25',
    `description` = 'Write explicit JSDoc/PHPDoc explaining the Why behind complex helper functions and business logic.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_25';
