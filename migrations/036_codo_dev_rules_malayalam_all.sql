-- Add Malayalam to all developer CODO rules (dev_rule_1…25).
-- Complements 035; safe to re-run. Does not touch tester/project rules or acknowledgements.

UPDATE `codo_common_rules`
SET `description` = 'Reset components cleanly on form submission and modal unmount (useEffect cleanup). Old inputs must never bleed into next entries.\n\nMalayalam: ഫോം സബ്മിറ്റ് ചെയ്താലോ മോഡൽ ക്ലോസ് ചെയ്താലോ ഫീൽഡുകൾ പൂർണ്ണമായും ക്ലിയർ ചെയ്യണം.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_1';

UPDATE `codo_common_rules`
SET `description` = 'Execute inline validation feedback dynamically before form submission.\n\nMalayalam: യൂസർ സബ്മിറ്റ് ചെയ്യുന്നതിന് മുൻപ് തന്നെ ഇൻലൈൻ എറർ ഫീഡ്ബാക്ക് കാണിക്കണം.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_2';

UPDATE `codo_common_rules`
SET `description` = 'Intercept backdrop clicks or page navigation if form is dirty with an Unsaved Changes warning.\n\nMalayalam: ഫോം പൂരിപ്പിക്കുന്നതിനിടയിൽ മാറിയാൽ Unsaved Changes വാണിംഗ് കാണിക്കണം.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_3';

UPDATE `codo_common_rules`
SET `description` = 'Never rely on native browser cache for resetting inputs; explicitly wipe local state arrays upon cancel/submit.\n\nMalayalam: ബ്രൗസർ കാഷ് ഉപയോഗിച്ച് ഇൻപുട്ട് ക്ലിയർ ചെയ്യരുത്; ക്യാൻസൽ/സബ്മിറ്റ് ചെയ്യുമ്പോൾ ലോക്കൽ സ്റ്റേറ്റ് അറേകൾ വ്യക്തമായി മായ്ക്കണം.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_4';

UPDATE `codo_common_rules`
SET `description` = 'Hard-clamp phone numbers and national IDs (maxLength=10 or 15). Prevent typing infinite numbers.\n\nMalayalam: ഫോൺ നമ്പറുകൾ 10 ഡിജിറ്റിൽ കൂടുതൽ ടൈപ്പ് ചെയ്യാൻ അനുവദിക്കരുത്.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_5';

UPDATE `codo_common_rules`
SET `description` = 'Escape and validate inputs on both Frontend and Backend to block SQLi and XSS (script injection).\n\nMalayalam: ഫ്രണ്ട്എൻഡിലും ബാക്കെൻഡിലും ഇൻപുട്ടുകൾ എസ്കേപ്പ് ചെയ്ത് വാലിഡേറ്റ് ചെയ്ത് SQLi/XSS (സ്ക്രിപ്റ്റ് ഇൻജക്ഷൻ) തടയണം.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_6';

UPDATE `codo_common_rules`
SET `description` = 'Provide frontend validation masks matching backend database column constraints.\n\nMalayalam: ബാക്കെൻഡ് ഡാറ്റാബേസ് കോളം പരിധികളുമായി പൊരുത്തപ്പെടുന്ന ഫ്രണ്ട്എൻഡ് വാലിഡേഷൻ മാസ്കുകൾ നൽകണം.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_7';

UPDATE `codo_common_rules`
SET `description` = 'Disable action buttons instantly on click and display a loading spinner.\n\nMalayalam: ക്ലിക്ക് ചെയ്ത ഉടൻ ബട്ടൺ ഡിസേബിൾ ആയി സ്പിന്നർ കാണിക്കണം.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_8';

UPDATE `codo_common_rules`
SET `description` = 'Never run destructive API calls directly. Require a Small (400px) confirmation modal.\n\nMalayalam: Delete അമർത്തുമ്പോൾ 400px കൺഫർമേഷൻ മോഡൽ വഴി അനുമതി വാങ്ങിയിരിക്കണം.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_9';

UPDATE `codo_common_rules`
SET `description` = 'Disable the submit button until all required field validations evaluate to true.\n\nMalayalam: ആവശ്യമായ എല്ലാ ഫീൽഡ് വാലിഡേഷനുകളും ശരിയാകുന്നതുവരെ സബ്മിറ്റ് ബട്ടൺ ഡിസേബിൾ ആയിരിക്കണം.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_10';

UPDATE `codo_common_rules`
SET `description` = 'All UI containers, buttons, and cards MUST use rounded-xl (12px) or rounded-2xl (16px). Sharp edges are forbidden.\n\nMalayalam: എല്ലാ UI കണ്ടെയ്നറുകൾ, ബട്ടണുകൾ, കാർഡുകൾ rounded-xl (12px) അല്ലെങ്കിൽ rounded-2xl (16px) ഉപയോഗിക്കണം. മൂർച്ചയുള്ള അറ്റങ്ങൾ അനുവദനീയമല്ല.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_11';

UPDATE `codo_common_rules`
SET `description` = 'Layouts must conform to a 12-column grid system with explicit gap-4 or gap-6 spacing.\n\nMalayalam: ലേഔട്ടുകൾ 12-കോളം ഗ്രിഡ് സിസ്റ്റം പാലിക്കുകയും gap-4 അല്ലെങ്കിൽ gap-6 സ്പേസിംഗ് വ്യക്തമായി ഉപയോഗിക്കുകയും വേണം.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_12';

UPDATE `codo_common_rules`
SET `description` = 'Never declare dynamic spacing utilities (mb-X, pb-X) inside .map() array loops. Use parent grid/flex gap properties.\n\nMalayalam: .map() അറേ ലൂപ്പുകൾക്കുള്ളിൽ mb-X, pb-X പോലുള്ള ഡൈനാമിക് സ്പേസിംഗ് യൂട്ടിലിറ്റികൾ ഉപയോഗിക്കരുത്; പാരന്റ് grid/flex gap ഉപയോഗിക്കുക.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_13';

UPDATE `codo_common_rules`
SET `description` = 'Never apply global overflow: hidden on the root body. Implement CODO''s custom slim scrollbar styles.\n\nMalayalam: റൂട്ട് body-യിൽ ആഗോളമായി overflow: hidden പ്രയോഗിക്കരുത്. CODO-യുടെ സ്ലിം സ്ക്രോൾബാർ സ്റ്റൈലുകൾ നടപ്പിലാക്കുക.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_14';

UPDATE `codo_common_rules`
SET `description` = 'Test every background/text utility to ensure seamless contrast scaling across Dark Mode and Light Mode.\n\nMalayalam: ഡാർക്ക് മോഡും ലൈറ്റ് മോഡും തമ്മിൽ മാറുമ്പോൾ എല്ലാ ബാക്ക്ഗ്രൗണ്ട്/ടെക്സ്റ്റ് യൂട്ടിലിറ്റികളുടെയും കോൺട്രാസ്റ്റ് പരിശോധിക്കുക.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_15';

UPDATE `codo_common_rules`
SET `description` = 'Multi-language inputs handling Arabic must explicitly set dir="rtl" and preserve caret/number alignment.\n\nMalayalam: അറബിക് പോലുള്ള മൾട്ടി-ലാംഗ്വേജ് ഇൻപുട്ടുകളിൽ dir="rtl" സജ്ജമാക്കി കാരറ്റ്/നമ്പർ അലൈൻമെന്റ് നിലനിർത്തണം.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_16';

UPDATE `codo_common_rules`
SET `description` = 'Date and time pickers must handle null/undefined states cleanly and map display formatting separately from ISO payloads.\n\nMalayalam: തീയതി/സമയ പിക്കറുകൾ null/undefined സ്റ്റേറ്റുകൾ ശരിയായി കൈകാര്യം ചെയ്യുകയും ISO പേലോഡിൽ നിന്ന് ഡിസ്പ്ലേ ഫോർമാറ്റിംഗ് വേർതിരിക്കുകയും വേണം.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_17';

UPDATE `codo_common_rules`
SET `description` = 'All database query layers must include explicit ordering (ORDER BY created_at DESC). Random listing order is unacceptable.\n\nMalayalam: എല്ലാ ഡാറ്റാബേസ് ക്വറി ലെയറുകളിലും വ്യക്തമായ ഓർഡറിംഗ് (ORDER BY created_at DESC) ഉണ്ടായിരിക്കണം. ക്രമരഹിത ലിസ്റ്റിംഗ് അനുവദനീയമല്ല.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_18';

UPDATE `codo_common_rules`
SET `description` = 'Never render a blank screen or plain text spinner during data fetch. Use layout-matching Skeleton Shimmers.\n\nMalayalam: ഡാറ്റ ഫെച്ച് ചെയ്യുമ്പോൾ ശൂന്യ സ്ക്രീൻ അല്ലെങ്കിൽ സാധാരണ സ്പിന്നർ കാണിക്കരുത്; ലേഔട്ടുമായി പൊരുത്തപ്പെടുന്ന Skeleton Shimmer ഉപയോഗിക്കുക.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_19';

UPDATE `codo_common_rules`
SET `description` = 'Maintain main-thread execution under 1.5s via WebP images, lazy loading, asset compression, and active PWA service workers.\n\nMalayalam: WebP ഇമേജുകൾ, ലേസി ലോഡിംഗ്, അസറ്റ് കംപ്രഷൻ, PWA സർവീസ് വർക്കറുകൾ വഴി മെയിൻ-ത്രെഡ് എക്സിക്യൂഷൻ 1.5 സെക്കൻഡിനുള്ളിൽ നിലനിർത്തുക.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_20';

UPDATE `codo_common_rules`
SET `description` = 'Any database column used in WHERE, JOIN, ORDER BY, or GROUP BY must be explicitly indexed.\n\nMalayalam: WHERE, JOIN, ORDER BY, അല്ലെങ്കിൽ GROUP BY-യിൽ ഉപയോഗിക്കുന്ന ഏത് ഡാറ്റാബേസ് കോളവും വ്യക്തമായി ഇൻഡക്സ് ചെയ്തിരിക്കണം.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_21';

UPDATE `codo_common_rules`
SET `description` = 'Tables expecting more than 100 entries must implement server-side Pagination or Infinite Scroll bounds.\n\nMalayalam: 100-ൽ അധികം എൻട്രികൾ പ്രതീക്ഷിക്കുന്ന ടേബിളുകളിൽ സർവർ-സൈഡ് പേജിനേഷൻ അല്ലെങ്കിൽ Infinite Scroll നടപ്പിലാക്കണം.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_22';

UPDATE `codo_common_rules`
SET `description` = 'Strip all console.log(), print(), or dd() debug statements before pushing or planning output.\n\nMalayalam: പുഷ് ചെയ്യുന്നതിനോ പ്ലാൻ ഔട്ട്പുട്ടിനോ മുമ്പ് console.log(), print(), dd() ഡീബഗ് സ്റ്റേറ്റ്മെന്റുകൾ നീക്കം ചെയ്യണം.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_23';

UPDATE `codo_common_rules`
SET `description` = 'All API keys and secrets must reside strictly in .env and be excluded via .gitignore.\n\nMalayalam: എല്ലാ API കീകളും സീക്രട്ടുകളും .env-ൽ മാത്രം സൂക്ഷിക്കുകയും .gitignore വഴി ഒഴിവാക്കുകയും വേണം.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_24';

UPDATE `codo_common_rules`
SET `description` = 'Write explicit JSDoc/PHPDoc explaining the Why behind complex helper functions and business logic.\n\nMalayalam: സങ്കീർണ്ണ ഹെൽപ്പർ ഫംഗ്ഷനുകളുടെയും ബിസിനസ് ലോജിക്കിന്റെയും Why വിശദീകരിക്കുന്ന JSDoc/PHPDoc എഴുതണം.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_25';
