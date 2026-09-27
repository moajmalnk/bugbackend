-- Cross-browser API & data consistency (Common CODO developer rules 44–50).
-- Safe to re-run (INSERT IGNORE). Backfills project_compliance_checks for existing projects.
-- Also backfills rules 33, 35, 36, 37, 38, 40, 43 for projects created after migration 054
-- whose PHP seeder still stopped at rule 32.

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_44', 'Cross-Browser API Consistency', 'Rule 44',
 'The same authenticated request must produce the same logical API result across supported browsers when request parameters, user identity, permissions, database state, and server state are identical. Before fixing a browser-specific data difference, compare URL, method, query, body, session, headers, status, response, timing, frontend cache, and the database result.\n\nMalayalam: ഒരേ യൂസർ, ഒരേ പാരാമീറ്ററുകൾ, ഒരേ സെർവർ സ്റ്റേറ്റ് ആണെങ്കിൽ എല്ലാ ബ്രൗസറുകളിലും ഒരേ API ഫലം ലഭിക്കണം. ബ്രൗസർ പ്രശ്നമാണെന്ന് കരുതുന്നതിന് മുമ്പ് URL, മെത്തേഡ്, ക്വറി, ബോഡി, സെഷൻ, ഹെഡറുകൾ, റെസ്പോൺസ്, ഫ്രണ്ട്എൻഡ് കാഷ്, ഡാറ്റാബേസ് ഫലം താരതമ്യം ചെയ്യണം.',
 44, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_45', 'No Manual Hard Refresh Dependency', 'Rule 45',
 'Production functionality must not depend on Ctrl+Shift+R or manually clearing browser cache or cookies. If a hard refresh is required to see correct data, treat it as a defect in the caching or state lifecycle.\n\nMalayalam: ശരിയായ ഡാറ്റ കാണാൻ യൂസർ Ctrl+Shift+R അമർത്തുകയോ ബ്രൗസർ കാഷ്/കുക്കികൾ മായ്ക്കുകയോ ചെയ്യേണ്ടി വരരുത്. ഹാർഡ് റിഫ്രഷ് വേണമെങ്കിൽ അത് ഒരു ഡിഫെക്ട് ആണ്.',
 45, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_46', 'Explicit API Cache Policy', 'Rule 46',
 'Every API endpoint must declare an intentional caching policy (Cache-Control, ETag, Last-Modified, Vary). Sensitive authenticated responses must not be stored in shared or public caches. Do not append random timestamps such as ?t=Date.now() as a permanent cache-bust.\n\nMalayalam: ഓരോ API എൻഡ്‌പോയിന്റിനും വ്യക്തമായ Cache-Control, ETag, Last-Modified, Vary നയം ഉണ്ടായിരിക്കണം. സ്വകാര്യ പ്രതികരണങ്ങൾ പങ്കിട്ട കാഷിൽ സൂക്ഷിക്കരുത്. ?t=Date.now() സ്ഥിരം പരിഹാരമാക്കരുത്.',
 46, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_47', 'Cache Invalidation After Mutations', 'Rule 47',
 'After a successful POST, PUT, PATCH, or DELETE, invalidate or update every frontend query and state that depends on the changed resource, then refetch so the UI shows the latest data.\n\nMalayalam: POST, PUT, PATCH, DELETE വിജയിച്ച ശേഷം ആ ഡാറ്റയെ ആശ്രയിക്കുന്ന ഫ്രണ്ട്എൻഡ് ക്വറികളും സ്റ്റേറ്റും ഇൻവാലിഡേറ്റ് ചെയ്ത് വീണ്ടും ഫെച്ച് ചെയ്യണം. പഴയ ലിസ്റ്റ് സ്ക്രീനിൽ നിൽക്കരുത്.',
 47, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_48', 'Frontend Query Cache Ownership', 'Rule 48',
 'Every frontend data cache must have an explicit owner, stale time, and cache time. Invalidate related queries after mutations, cancel obsolete requests, and do not keep the same server data in multiple uncontrolled stores.\n\nMalayalam: ഓരോ ഫ്രണ്ട്എൻഡ് ഡാറ്റ കാഷിനും വ്യക്തമായ ഉടമ, stale time, cache time ഉണ്ടായിരിക്കണം. മ്യൂട്ടേഷന് ശേഷം ബന്ധപ്പെട്ട ക്വറികൾ ഇൻവാലിഡേറ്റ് ചെയ്യുക. ഒരേ സെർവർ ഡാറ്റ പല അനിയന്ത്രിത സ്റ്റോറുകളിൽ സൂക്ഷിക്കരുത്.',
 48, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_49', 'Service Worker Cache Safety', 'Rule 49',
 'Version the service worker and static assets, define cache strategies explicitly, never serve stale dynamic or private API data, and invalidate old caches on activation so updates apply without users clearing site data.\n\nMalayalam: സർവീസ് വർക്കറും സ്റ്റാറ്റിക് അസറ്റുകളും വേർഷൻ ചെയ്യുക. ഡൈനാമിക്/സ്വകാര്യ API ഡാറ്റ പഴയ കാഷായി നൽകരുത്. ആക്ടിവേഷനിൽ പഴയ കാഷ് മായ്ക്കുക; യൂസർ സൈറ്റ് ഡാറ്റ മായ്ക്കേണ്ടി വരരുത്.',
 49, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_50', 'Request Identity & Credentials', 'Rule 50',
 'Authenticated API requests must use one consistent strategy for cookies, credentials, Authorization headers, CSRF tokens, SameSite, Secure/HTTPS, and CORS.\n\nMalayalam: ഓതന്റിക്കേഷൻ വേണ്ട എല്ലാ API അഭ്യർത്ഥനകളും കുക്കി, ക്രെഡൻഷ്യൽ, Authorization, CSRF, SameSite, Secure, CORS എന്നിവയിൽ ഒരേ തന്ത്രം ഉപയോഗിക്കണം.',
 50, 1);

-- ── Backfill compliance checks for existing projects ───────────────────────
INSERT IGNORE INTO `project_compliance_checks` (`project_id`, `phase`, `rule_key`, `verified`)
SELECT pc.`project_id`, 'developer', k.`rule_key`, 0
FROM `project_compliance` pc
CROSS JOIN (
  SELECT 'dev_rule_33' AS `rule_key` UNION ALL
  SELECT 'dev_rule_35' UNION ALL
  SELECT 'dev_rule_36' UNION ALL
  SELECT 'dev_rule_37' UNION ALL
  SELECT 'dev_rule_38' UNION ALL
  SELECT 'dev_rule_40' UNION ALL
  SELECT 'dev_rule_43' UNION ALL
  SELECT 'dev_rule_44' UNION ALL
  SELECT 'dev_rule_45' UNION ALL
  SELECT 'dev_rule_46' UNION ALL
  SELECT 'dev_rule_47' UNION ALL
  SELECT 'dev_rule_48' UNION ALL
  SELECT 'dev_rule_49' UNION ALL
  SELECT 'dev_rule_50'
) k;
