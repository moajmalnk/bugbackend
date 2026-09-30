-- CODO production engineering & testing rules (developer Rules 51–67, QA Stress 14–34).
-- Extends 8 developer rules and 4 QA rules in place. Safe to re-run (INSERT IGNORE + guarded UPDATEs).
-- Guarded UPDATEs only replace descriptions that still match the previous seed text, so admin edits are kept.
-- Backfill adds unverified checks to open projects only (not completed / release_ready / archived / admin_ready).

-- ── New developer rules ─────────────────────────────────────────────────────
INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_51', 'API Contract & Backward Compatibility', 'Rule 51',
 'Frontend and backend must agree on request fields, response fields, data types, nullable fields, status codes, validation and authentication errors, pagination, sorting, filtering and the error response structure. Breaking changes to APIs, database structures, authentication, shared components or common response formats must be versioned or coordinated with every deployed client and integration.\n\nMalayalam: റിക്വസ്റ്റ്/റെസ്പോൺസ് ഫീൽഡുകൾ, ഡാറ്റ ടൈപ്പുകൾ, null ഫീൽഡുകൾ, സ്റ്റാറ്റസ് കോഡുകൾ, എറർ ഘടന, പേജിനേഷൻ, സോർട്ടിംഗ്, ഫിൽട്ടറിംഗ് എന്നിവയിൽ ഫ്രണ്ട്എൻഡും ബാക്കെൻഡും യോജിക്കണം. ബ്രേക്കിംഗ് മാറ്റങ്ങൾ വേർഷൻ ചെയ്യുകയോ നിലവിലുള്ള ക്ലയന്റുകളുമായി ഏകോപിപ്പിക്കുകയോ ചെയ്യണം.',
 51, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_52', 'Backend as Source of Truth', 'Rule 52',
 'Critical business data (payments, balances, student records, permissions, attendance, packages, wallet data, financial totals) must have one clearly defined source of truth on the backend. Frontend state, localStorage, sessionStorage and browser cache are never authoritative, and independent copies of the same business state are not allowed unless synchronization is explicitly implemented.\n\nMalayalam: പേയ്മെന്റ്, ബാലൻസ്, സ്റ്റുഡന്റ് റെക്കോർഡ്, പെർമിഷൻ, അറ്റൻഡൻസ്, വാലറ്റ്, ഫിനാൻഷ്യൽ ടോട്ടൽ തുടങ്ങിയ പ്രധാന ഡാറ്റയുടെ ഏക ഉറവിടം ബാക്കെൻഡ് ആയിരിക്കണം. ഫ്രണ്ട്എൻഡ് സ്റ്റേറ്റ്, localStorage, sessionStorage, ബ്രൗസർ കാഷ് എന്നിവ ആധികാരികമായി കണക്കാക്കരുത്.',
 52, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_53', 'Complete API Request Lifecycle', 'Rule 53',
 'Every asynchronous API request must explicitly handle loading, success, empty result, validation error (422), authentication error (401), authorization error (403), timeout, network failure, server error (5xx) and cancellation. No request may leave the UI in an undefined state.\n\nMalayalam: ഓരോ API അഭ്യർത്ഥനയും loading, success, empty, validation error, 401, 403, timeout, network failure, server error, cancellation എന്നിവ വ്യക്തമായി കൈകാര്യം ചെയ്യണം. ഒരു അഭ്യർത്ഥനയും UI-യെ അനിശ്ചിത അവസ്ഥയിൽ വിടരുത്.',
 53, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_54', 'Stale Request & Navigation Safety', 'Rule 54',
 'When several requests for the same resource can run at once, an older response must never overwrite a newer one. Use AbortController, request IDs, query keys or equivalent lifecycle control, so that when a user searches A, then AB, then ABC, a late A or AB response cannot replace the ABC result. Back, Forward, refresh, route changes, modal close and tab switching during a request must not corrupt state or trigger unintended mutations.\n\nMalayalam: ഒരേ റിസോഴ്സിന് പല അഭ്യർത്ഥനകൾ ഒരുമിച്ച് നടക്കുമ്പോൾ പഴയ റെസ്പോൺസ് പുതിയതിനെ മാറ്റിയെഴുതരുത് (A → AB → ABC). AbortController, request ID, query key എന്നിവ ഉപയോഗിക്കുക. അഭ്യർത്ഥനയ്ക്കിടയിൽ Back, Forward, refresh, route change, modal close എന്നിവ സ്റ്റേറ്റ് കേടാക്കരുത്.',
 54, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_55', 'Never Display Fake Business Data', 'Rule 55',
 'Never show guessed, stale or placeholder business values (such as 0, a previous total or dummy figures) as if they were current. The UI must clearly distinguish loading, real data, empty, error and stale data.\n\nMalayalam: ഊഹിച്ചതോ പഴയതോ പ്ലേസ്ഹോൾഡർ ആയതോ ആയ ബിസിനസ് വാല്യൂകൾ നിലവിലെ ഡാറ്റ എന്ന പോലെ കാണിക്കരുത്. loading, real data, empty, error, stale എന്നിവ വ്യക്തമായി വേർതിരിക്കണം.',
 55, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_56', 'Database Transaction Integrity', 'Rule 56',
 'When a business operation modifies multiple related records, wrap it in a database transaction. A failed operation must roll back completely and never leave partially updated business data.\n\nMalayalam: ഒരു ബിസിനസ് ഓപ്പറേഷൻ പല ബന്ധപ്പെട്ട റെക്കോർഡുകൾ മാറ്റുമ്പോൾ ട്രാൻസാക്ഷൻ ഉപയോഗിക്കണം. പരാജയപ്പെട്ടാൽ പൂർണ്ണമായി റോൾബാക്ക് ചെയ്യണം; പകുതി അപ്ഡേറ്റ് ആയ ഡാറ്റ ബാക്കിയാകരുത്.',
 56, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_57', 'Concurrency Safety', 'Rule 57',
 'Critical operations (payments, admissions, package updates, wallet operations, attendance, counters, financial transactions, stock-like values) must stay correct when multiple requests run simultaneously. Use row locks, optimistic versioning or atomic updates instead of read-modify-write in application code.\n\nMalayalam: പേയ്മെന്റ്, അഡ്മിഷൻ, വാലറ്റ്, അറ്റൻഡൻസ്, കൗണ്ടർ, ഫിനാൻഷ്യൽ ട്രാൻസാക്ഷൻ തുടങ്ങിയവ ഒരേ സമയം പല അഭ്യർത്ഥനകൾ വന്നാലും ശരിയായിരിക്കണം. row lock, optimistic versioning, atomic update എന്നിവ ഉപയോഗിക്കുക.',
 57, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_58', 'Idempotent Critical APIs', 'Rule 58',
 'Retrying an important mutation must never create duplicate business records. Enforce idempotency on the backend with idempotency keys, unique constraints or equivalent checks; the frontend lockout in Rule 8 is not enough on its own.\n\nMalayalam: പ്രധാന മ്യൂട്ടേഷൻ വീണ്ടും ശ്രമിച്ചാലും ഡ്യൂപ്ലിക്കേറ്റ് റെക്കോർഡ് ഉണ്ടാകരുത്. ബാക്കെൻഡിൽ idempotency key, unique constraint എന്നിവ ഉപയോഗിക്കുക; Rule 8-ലെ ഫ്രണ്ട്എൻഡ് ലോക്ക് മാത്രം മതിയാവില്ല.',
 58, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_59', 'N+1 Query Prevention', 'Rule 59',
 'Never execute one database query per returned record when the same data can be fetched with joins, eager loading, batching (WHERE id IN (...)) or aggregation. Loading 100 students must not trigger 100 extra package queries.\n\nMalayalam: ഓരോ റെക്കോർഡിനും വേറെ ഡാറ്റാബേസ് ക്വറി നടത്തരുത്. JOIN, eager loading, batching, aggregation എന്നിവ ഉപയോഗിക്കുക. 100 സ്റ്റുഡന്റ്സിനെ ലോഡ് ചെയ്യുമ്പോൾ 100 അധിക ക്വറികൾ പാടില്ല.',
 59, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_60', 'Backend Authorization', 'Rule 60',
 'Frontend permission hiding is not security. Every protected backend endpoint must independently verify the caller''s identity, role and access to the specific resource. Never trust hidden buttons, frontend roles, route visibility or client-side checks.\n\nMalayalam: ഫ്രണ്ട്എൻഡിൽ ബട്ടൺ മറയ്ക്കുന്നത് സെക്യൂരിറ്റി അല്ല. ഓരോ സംരക്ഷിത ബാക്കെൻഡ് എൻഡ്‌പോയിന്റും യൂസറുടെ റോളും റിസോഴ്സ് ആക്സസും സ്വതന്ത്രമായി പരിശോധിക്കണം.',
 60, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_61', 'Environment Isolation', 'Rule 61',
 'Development, staging and production must use separate databases, credentials, API endpoints, storage, secrets and third-party keys. Production must never accidentally point to development or test resources, and non-production must never write to production data.\n\nMalayalam: ഡെവലപ്മെന്റ്, സ്റ്റേജിംഗ്, പ്രൊഡക്ഷൻ എന്നിവയ്ക്ക് വേറിട്ട ഡാറ്റാബേസ്, ക്രെഡൻഷ്യൽ, API എൻഡ്‌പോയിന്റ്, സ്റ്റോറേജ്, സീക്രട്ട്, തേർഡ്-പാർട്ടി കീകൾ വേണം. പ്രൊഡക്ഷൻ ഒരിക്കലും ടെസ്റ്റ് റിസോഴ്സുകൾ ഉപയോഗിക്കരുത്.',
 61, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_62', 'Safe Database Migrations', 'Rule 62',
 'Schema changes must ship as reviewed, re-runnable migrations. Never deploy a migration that can silently delete production data, invalidate existing records, break the currently deployed application version or cause irreversible corruption. Prefer additive changes, back up before destructive steps, and plan a rollback.\n\nMalayalam: ഡാറ്റാബേസ് മാറ്റങ്ങൾ സുരക്ഷിതമായ മൈഗ്രേഷനുകളിലൂടെ മാത്രം. പ്രൊഡക്ഷൻ ഡാറ്റ ഡിലീറ്റ് ചെയ്യുന്നതോ നിലവിലെ റെക്കോർഡുകൾ അസാധുവാക്കുന്നതോ പഴയ ആപ്പ് വേർഷൻ തകർക്കുന്നതോ ആയ മൈഗ്രേഷൻ ഡിപ്ലോയ് ചെയ്യരുത്. ബാക്കപ്പും റോൾബാക്ക് പ്ലാനും വേണം.',
 62, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_63', 'Production Observability', 'Rule 63',
 'Important production operations must emit useful structured logs and monitoring signals (operation, user ID, request ID, outcome, duration). Logs must never contain passwords, access tokens, API secrets or sensitive personal data.\n\nMalayalam: പ്രധാന പ്രൊഡക്ഷൻ ഓപ്പറേഷനുകൾക്ക് ഉപകാരപ്രദമായ structured ലോഗിംഗും മോണിറ്ററിംഗും വേണം. ലോഗുകളിൽ പാസ്‌വേഡ്, ആക്സസ് ടോക്കൺ, API സീക്രട്ട്, സെൻസിറ്റീവ് വ്യക്തിഗത ഡാറ്റ ഒരിക്കലും ഉണ്ടാകരുത്.',
 63, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_64', 'AI-Generated Code Verification', 'Rule 64',
 'Code generated with Cursor, Codex, Claude, ChatGPT or any other AI tool is not automatically trusted. It must pass the same code review, testing, security, performance and maintainability standards as hand-written code. Never merge code merely because the AI produced it successfully.\n\nMalayalam: Cursor, Codex, Claude, ChatGPT തുടങ്ങിയ AI ടൂളുകൾ എഴുതിയ കോഡ് സ്വയമേവ വിശ്വസിക്കരുത്. മനുഷ്യൻ എഴുതിയ കോഡിന്റെ അതേ റിവ്യൂ, ടെസ്റ്റിംഗ്, സെക്യൂരിറ്റി, പെർഫോമൻസ് നിലവാരം പാലിക്കണം.',
 64, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_65', 'Dependency Discipline', 'Rule 65',
 'Before adding a package, check whether the project already has equivalent functionality, whether the package is actively maintained, whether it is compatible with the current stack, and whether it has known security issues. Avoid unnecessary dependencies.\n\nMalayalam: പുതിയ പാക്കേജ് ചേർക്കുന്നതിന് മുമ്പ് പ്രോജക്റ്റിൽ അതേ ഫംഗ്ഷണാലിറ്റി ഉണ്ടോ, പാക്കേജ് സജീവമായി മെയിന്റെയിൻ ചെയ്യുന്നുണ്ടോ, കോംപാറ്റിബിൾ ആണോ, സെക്യൂരിറ്റി പ്രശ്നങ്ങൾ ഉണ്ടോ എന്ന് പരിശോധിക്കുക.',
 65, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_66', 'Root Cause Over Workarounds', 'Rule 66',
 'Do not fix bugs by blindly adding setTimeout, page reloads, forced refreshes, duplicate API calls, arbitrary retries, cache clearing or artificial delays. Reproduce production bugs in a controlled environment where practical, record the environment, browser, device, user role, API request, response, database state and reproduction steps, then fix the actual cause. If data is only correct after Ctrl+Shift+R, investigate browser cache, service worker, asset versions, API cache, frontend state, deployment and CDN (see Rules 44 and 45).\n\nMalayalam: setTimeout, പേജ് റീലോഡ്, ഫോഴ്സ്ഡ് റിഫ്രഷ്, ഡ്യൂപ്ലിക്കേറ്റ് API കോൾ, അനാവശ്യ റീട്രൈ, കാഷ് ക്ലിയറിംഗ് എന്നിവ വഴി ബഗ് മറയ്ക്കരുത്. ബഗ് റീപ്രൊഡ്യൂസ് ചെയ്ത്, എൻവയോൺമെന്റ്, ബ്രൗസർ, റോൾ, റിക്വസ്റ്റ്, റെസ്പോൺസ്, ഡാറ്റാബേസ് സ്റ്റേറ്റ് രേഖപ്പെടുത്തി യഥാർത്ഥ കാരണം പരിഹരിക്കുക.',
 66, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_67', 'Release Readiness', 'Rule 67',
 'A feature is not complete because it works on the developer''s machine. Before release it must be fully implemented, code reviewed, tested, security checked, performance checked, browser checked where applicable, verified as a production build, deployed, and smoke-tested on its critical flow in production.\n\nMalayalam: ഡെവലപ്പറുടെ മെഷീനിൽ പ്രവർത്തിച്ചതുകൊണ്ട് ഫീച്ചർ പൂർത്തിയായി എന്നല്ല. റിലീസിന് മുമ്പ് കോഡ് റിവ്യൂ, ടെസ്റ്റ്, സെക്യൂരിറ്റി, പെർഫോമൻസ്, ബ്രൗസർ പരിശോധന, പ്രൊഡക്ഷൻ ബിൽഡ്, ഡിപ്ലോയ്മെന്റ്, പ്രൊഡക്ഷൻ സ്മോക്ക് ടെസ്റ്റ് എന്നിവ പൂർത്തിയാകണം.',
 67, 1);

-- ── New tester / QA rules ───────────────────────────────────────────────────
INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('tester', 'qa_loading_lifecycle', 'Loading Lifecycle Drill', 'QA Stress 14',
 'Drive every asynchronous screen through LOADING → SUCCESS, EMPTY, ERROR, TIMEOUT and CANCELLED. Reject if loading can remain indefinitely, a skeleton stays after data loads, skeleton and data render together, or the wrong skeleton remains after navigation.\n\nMalayalam: ഓരോ async സ്ക്രീനും LOADING → SUCCESS, EMPTY, ERROR, TIMEOUT, CANCELLED എന്നിവയിലൂടെ ടെസ്റ്റ് ചെയ്യുക. ലോഡിംഗ് അനന്തമായി തുടർന്നാലോ ഡാറ്റ വന്ന ശേഷവും സ്കെലിറ്റൺ നിന്നാലോ റിജക്ട് ചെയ്യുക.',
 14, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('tester', 'qa_data_reconciliation', 'UI / API / Database Reconciliation', 'QA Stress 15',
 'For every critical operation trace USER ACTION → API → BACKEND → DATABASE → API RESPONSE → FRONTEND STATE → DISPLAY and confirm UI value = API value = database value, then refresh the page and confirm the same result remains. Any unexplained difference is a defect.\n\nMalayalam: പ്രധാന ഓപ്പറേഷനുകൾക്ക് UI വാല്യൂ = API വാല്യൂ = ഡാറ്റാബേസ് വാല്യൂ എന്ന് ഉറപ്പാക്കുക; പേജ് റിഫ്രഷ് ചെയ്താലും അതേ ഫലം നിലനിൽക്കണം. വിശദീകരിക്കാനാകാത്ത വ്യത്യാസം ഡിഫെക്ട് ആണ്.',
 15, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('tester', 'qa_mutation_sync', 'Mutation Synchronization Audit', 'QA Stress 16',
 'After create, edit, delete and status changes, verify the list, detail view, counts, totals, dashboard, filters, pagination and related screens all show the new state. Change data in one route or session and confirm other screens do not keep showing stale data.\n\nMalayalam: create/edit/delete ചെയ്ത ശേഷം ലിസ്റ്റ്, ഡീറ്റെയിൽ, കൗണ്ട്, ടോട്ടൽ, ഡാഷ്ബോർഡ്, ഫിൽട്ടർ, പേജിനേഷൻ, ബന്ധപ്പെട്ട സ്ക്രീനുകൾ എല്ലാം പുതിയ സ്റ്റേറ്റ് കാണിക്കണം. മറ്റൊരു സ്ക്രീനിൽ പഴയ ഡാറ്റ തുടരരുത്.',
 16, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('tester', 'qa_race_condition', 'Race Condition Drill', 'QA Stress 17',
 'Trigger rapid successive requests: type A → AB → ABC in search, and quickly change filters, sorting and date ranges. Reject if the final UI shows anything other than the latest selection or an older response overwrites it.\n\nMalayalam: സെർച്ചിൽ A → AB → ABC വേഗത്തിൽ ടൈപ്പ് ചെയ്യുക, ഫിൽട്ടർ, സോർട്ട്, തീയതി വേഗത്തിൽ മാറ്റുക. അവസാന UI ഏറ്റവും പുതിയ തിരഞ്ഞെടുപ്പ് മാത്രം കാണിക്കണം; പഴയ റെസ്പോൺസ് മാറ്റിയെഴുതിയാൽ റിജക്ട് ചെയ്യുക.',
 17, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('tester', 'qa_navigation_during_requests', 'Navigation During Requests', 'QA Stress 18',
 'While an API request is active, press Back, Forward and Refresh, change route, page or filter, close the modal and switch tabs. Reject if an obsolete request modifies the new screen, state is corrupted, or an unintended mutation happens.\n\nMalayalam: API അഭ്യർത്ഥന നടക്കുമ്പോൾ Back, Forward, Refresh, route change, modal close, tab switch എന്നിവ ചെയ്യുക. പഴയ അഭ്യർത്ഥന പുതിയ സ്ക്രീൻ മാറ്റിയാലോ സ്റ്റേറ്റ് കേടായാലോ റിജക്ട് ചെയ്യുക.',
 18, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('tester', 'qa_slow_api_timeout', 'Slow API & Timeout Test', 'QA Stress 19',
 'Throttle the network and force an API timeout during the full user flow. The UI must stay usable with the correct skeleton, send no duplicate or accidental submissions, and end in a clear error. Reject on freezes, infinite loading, silent failures or permanently disabled controls.\n\nMalayalam: നെറ്റ്‌വർക്ക് സ്ലോ ആക്കി API timeout ഫോഴ്സ് ചെയ്ത് മുഴുവൻ ഫ്ലോ ടെസ്റ്റ് ചെയ്യുക. ഫ്രീസ്, അനന്ത ലോഡിംഗ്, നിശ്ശബ്ദ പരാജയം, സ്ഥിരമായി ഡിസേബിൾ ആയ ബട്ടണുകൾ ഉണ്ടെങ്കിൽ റിജക്ട് ചെയ്യുക.',
 19, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('tester', 'qa_cross_browser_data', 'Cross-Browser Data Consistency', 'QA Stress 20',
 'Test critical functionality in Chrome, Safari, Firefox, Edge, Android Chrome and iOS Safari with the same account and backend data. Business results must match; if one browser shows 260 students and another shows 760, reject and investigate the request, cache, state and database chain.\n\nMalayalam: ഒരേ അക്കൗണ്ടും ഒരേ ഡാറ്റയും ഉപയോഗിച്ച് Chrome, Safari, Firefox, Edge, Android, iOS എന്നിവയിൽ ടെസ്റ്റ് ചെയ്യുക. ഒരു ബ്രൗസറിൽ 260, മറ്റൊന്നിൽ 760 എന്ന് കാണിച്ചാൽ റിജക്ട് ചെയ്ത് കാരണം അന്വേഷിക്കുക.',
 20, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('tester', 'qa_cache_isolation', 'Cache Isolation Test', 'QA Stress 21',
 'Check first visit, normal refresh, hard refresh, incognito/private mode, after deployment, logout then login, a different account and a different browser. Reject if stale data or another user''s data is ever displayed.\n\nMalayalam: ആദ്യ സന്ദർശനം, റിഫ്രഷ്, ഹാർഡ് റിഫ്രഷ്, ഇൻകോഗ്നിറ്റോ, ഡിപ്ലോയ്മെന്റിന് ശേഷം, ലോഗൗട്ട്/ലോഗിൻ, മറ്റൊരു അക്കൗണ്ട്, മറ്റൊരു ബ്രൗസർ എന്നിവ ടെസ്റ്റ് ചെയ്യുക. പഴയതോ മറ്റൊരു യൂസറുടെയോ ഡാറ്റ കണ്ടാൽ റിജക്ട് ചെയ്യുക.',
 21, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('tester', 'qa_concurrency', 'Multi-Tab & Concurrent Operations', 'QA Stress 22',
 'Open the same account in multiple tabs and make changes in each, then use two users or sessions to perform the same critical operation simultaneously. Reject on duplicates, lost updates, incorrect counts, incorrect balances or stale state overwriting newer state.\n\nMalayalam: ഒരേ അക്കൗണ്ട് പല ടാബുകളിൽ തുറന്ന് മാറ്റങ്ങൾ വരുത്തുക; രണ്ട് യൂസർമാർ ഒരേ സമയം ഒരേ ഓപ്പറേഷൻ ചെയ്യുക. ഡ്യൂപ്ലിക്കേറ്റ്, നഷ്ടപ്പെട്ട അപ്ഡേറ്റ്, തെറ്റായ കൗണ്ട്/ബാലൻസ് ഉണ്ടെങ്കിൽ റിജക്ട് ചെയ്യുക.',
 22, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('tester', 'qa_session_expiry', 'Session Expiry Test', 'QA Stress 23',
 'Expire the session while viewing data, submitting a form, editing, deleting and uploading. The app must recover safely: no infinite loading, no silent failure, no unauthorized mutation and no data corruption.\n\nMalayalam: ഡാറ്റ കാണുമ്പോൾ, ഫോം സബ്മിറ്റ്, എഡിറ്റ്, ഡിലീറ്റ്, അപ്‌ലോഡ് ചെയ്യുമ്പോൾ സെഷൻ എക്സ്പയർ ചെയ്യുക. അനന്ത ലോഡിംഗ്, നിശ്ശബ്ദ പരാജയം, ഡാറ്റ കേടാകൽ എന്നിവ ഇല്ലാതെ സുരക്ഷിതമായി റിക്കവർ ചെയ്യണം.',
 23, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('tester', 'qa_permission_boundary', 'Permission Boundary Test', 'QA Stress 24',
 'Call every critical operation as an authorized user, an unauthorized user, a user with the wrong role and an expired session, including direct API calls. Reject if the backend allows the action; hiding it in the frontend is not sufficient.\n\nMalayalam: ഓരോ പ്രധാന ഓപ്പറേഷനും അനുമതിയുള്ള യൂസർ, അനുമതിയില്ലാത്ത യൂസർ, തെറ്റായ റോൾ, എക്സ്പയർ ആയ സെഷൻ എന്നിവ ഉപയോഗിച്ച് (നേരിട്ടുള്ള API കോൾ ഉൾപ്പെടെ) ടെസ്റ്റ് ചെയ്യുക. ഫ്രണ്ട്എൻഡിൽ മറയ്ക്കുന്നത് മതിയാവില്ല.',
 24, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('tester', 'qa_pagination_integrity', 'Pagination Integrity Test', 'QA Stress 25',
 'Check the first, a middle and the final page, a page-size change, and pagination combined with filtering, search and sorting. Reject if any record is missing or duplicated across pages.\n\nMalayalam: ആദ്യ, മധ്യ, അവസാന പേജുകൾ, പേജ് സൈസ് മാറ്റം, ഫിൽട്ടർ/സെർച്ച്/സോർട്ട് + പേജിനേഷൻ എന്നിവ ടെസ്റ്റ് ചെയ്യുക. റെക്കോർഡുകൾ നഷ്ടപ്പെട്ടാലോ ആവർത്തിച്ചാലോ റിജക്ട് ചെയ്യുക.',
 25, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('tester', 'qa_financial_integrity', 'Financial Data Integrity', 'QA Stress 26',
 'On financial screens confirm displayed amount = API amount = database amount = calculated amount. Test decimals, zero, large amounts, partial payment, full payment, refund, balance and rounding.\n\nMalayalam: ഫിനാൻഷ്യൽ സ്ക്രീനുകളിൽ കാണിക്കുന്ന തുക = API തുക = ഡാറ്റാബേസ് തുക = കണക്കാക്കിയ തുക എന്ന് ഉറപ്പാക്കുക. ദശാംശം, പൂജ്യം, വലിയ തുക, ഭാഗിക/പൂർണ്ണ പേയ്മെന്റ്, റീഫണ്ട്, ബാലൻസ്, റൗണ്ടിംഗ് എന്നിവ ടെസ്റ്റ് ചെയ്യുക.',
 26, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('tester', 'qa_api_contract', 'API Contract Verification', 'QA Stress 27',
 'Compare actual API responses with what the frontend expects: field names, types, null values, status codes, pagination metadata and error bodies. Reject on any mismatch.\n\nMalayalam: യഥാർത്ഥ API റെസ്പോൺസുകൾ ഫ്രണ്ട്എൻഡ് പ്രതീക്ഷിക്കുന്നതുമായി താരതമ്യം ചെയ്യുക: ഫീൽഡ് പേരുകൾ, ടൈപ്പുകൾ, null വാല്യൂ, സ്റ്റാറ്റസ് കോഡ്, പേജിനേഷൻ, എറർ. പൊരുത്തക്കേട് ഉണ്ടെങ്കിൽ റിജക്ട് ചെയ്യുക.',
 27, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('tester', 'qa_production_build_env', 'Production Build & Environment', 'QA Stress 28',
 'Never approve a release from development mode alone; test the actual production build. Confirm production points to the production API, database, storage, credentials and configuration.\n\nMalayalam: ഡെവലപ്മെന്റ് മോഡ് മാത്രം നോക്കി റിലീസ് അംഗീകരിക്കരുത്; യഥാർത്ഥ പ്രൊഡക്ഷൻ ബിൽഡ് ടെസ്റ്റ് ചെയ്യുക. പ്രൊഡക്ഷൻ API, ഡാറ്റാബേസ്, സ്റ്റോറേജ്, ക്രെഡൻഷ്യൽ, കോൺഫിഗറേഷൻ ശരിയാണെന്ന് ഉറപ്പാക്കുക.',
 28, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('tester', 'qa_deployment_smoke', 'Deployment Smoke Test', 'QA Stress 29',
 'Immediately after deployment verify: the site or app opens, login works, the main dashboard works, a critical read works, a critical create or update works, and logout works.\n\nMalayalam: ഡിപ്ലോയ്മെന്റിന് ശേഷം ഉടൻ: സൈറ്റ് തുറക്കുന്നു, ലോഗിൻ, ഡാഷ്ബോർഡ്, പ്രധാന റീഡ്, പ്രധാന create/update, ലോഗൗട്ട് എന്നിവ പ്രവർത്തിക്കുന്നുണ്ടെന്ന് പരിശോധിക്കുക.',
 29, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('tester', 'qa_regression', 'Regression Testing', 'QA Stress 30',
 'Every significant change must re-test the existing business flows it can affect. A new feature passing is not enough if an existing feature has broken.\n\nMalayalam: ഓരോ പ്രധാന മാറ്റത്തിനും ബാധിക്കാവുന്ന നിലവിലുള്ള ബിസിനസ് ഫ്ലോകൾ വീണ്ടും ടെസ്റ്റ് ചെയ്യണം. പുതിയ ഫീച്ചർ പാസ്സായാലും പഴയത് തകർന്നാൽ റിജക്ട് ചെയ്യുക.',
 30, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('tester', 'qa_performance_regression', 'Performance Regression Check', 'QA Stress 31',
 'After major changes compare API response time, number of API requests, payload size, rendering time, bundle size and database performance against the previous release. Reject unexplained regressions.\n\nMalayalam: വലിയ മാറ്റങ്ങൾക്ക് ശേഷം API റെസ്പോൺസ് സമയം, API അഭ്യർത്ഥനകളുടെ എണ്ണം, പേലോഡ് സൈസ്, റെൻഡറിംഗ് സമയം, ബണ്ടിൽ സൈസ്, ഡാറ്റാബേസ് പെർഫോമൻസ് എന്നിവ മുൻ റിലീസുമായി താരതമ്യം ചെയ്യുക.',
 31, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('tester', 'qa_accessibility', 'Accessibility Check', 'QA Stress 32',
 'Test keyboard navigation, visible focus, form labels, colour contrast, form error announcements, touch target size and semantic structure. Reject if a core flow cannot be completed with the keyboard.\n\nMalayalam: കീബോർഡ് നാവിഗേഷൻ, ഫോക്കസ്, ലേബലുകൾ, കോൺട്രാസ്റ്റ്, ഫോം എററുകൾ, ടച്ച് ടാർഗെറ്റ്, സെമാന്റിക് ഘടന എന്നിവ ടെസ്റ്റ് ചെയ്യുക. കീബോർഡ് കൊണ്ട് പ്രധാന ഫ്ലോ പൂർത്തിയാക്കാനാകുന്നില്ലെങ്കിൽ റിജക്ട് ചെയ്യുക.',
 32, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('tester', 'qa_responsive_matrix', 'Responsive Device Matrix', 'QA Stress 33',
 'Test mobile, tablet, laptop, desktop and large desktop widths in both portrait and landscape. Reject on overflow, clipped controls, unreadable text or broken layouts.\n\nMalayalam: മൊബൈൽ, ടാബ്‌ലെറ്റ്, ലാപ്‌ടോപ്പ്, ഡെസ്ക്ടോപ്പ്, വലിയ ഡെസ്ക്ടോപ്പ് എന്നിവ portrait, landscape രണ്ടിലും ടെസ്റ്റ് ചെയ്യുക. ഓവർഫ്ലോ, മുറിഞ്ഞ കൺട്രോളുകൾ, തകർന്ന ലേഔട്ട് ഉണ്ടെങ്കിൽ റിജക്ട് ചെയ്യുക.',
 33, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('tester', 'qa_release_acceptance', 'Final Release Acceptance', 'QA Stress 34',
 'Before production approval confirm: critical flows pass, no blocking defects, no critical console errors, no data integrity, authentication or authorization issues, no major loading issues, no obvious browser inconsistencies, no major performance regression, and the production smoke test passes.\n\nMalayalam: പ്രൊഡക്ഷൻ അംഗീകാരത്തിന് മുമ്പ്: പ്രധാന ഫ്ലോകൾ പാസ്, ബ്ലോക്കിംഗ് ഡിഫെക്ട് ഇല്ല, കൺസോൾ എറർ ഇല്ല, ഡാറ്റ/ഓതന്റിക്കേഷൻ/ഓതറൈസേഷൻ പ്രശ്നങ്ങൾ ഇല്ല, ബ്രൗസർ പൊരുത്തക്കേട് ഇല്ല, പെർഫോമൻസ് റിഗ്രഷൻ ഇല്ല, പ്രൊഡക്ഷൻ സ്മോക്ക് ടെസ്റ്റ് പാസ് എന്ന് ഉറപ്പാക്കുക.',
 34, 1);

-- ── Extended existing rules (guarded) ───────────────────────────────────────
UPDATE `codo_common_rules`
SET `description` = 'Reset components cleanly on form submission and modal unmount (useEffect cleanup). Old inputs must never bleed into next entries. Forms must explicitly manage initial, dirty, valid, invalid, submitting, success, failure, reset and cancel states, and opening another record must load only that record''s values.\n\nMalayalam: ഫോം സബ്മിറ്റ് ചെയ്താലോ മോഡൽ ക്ലോസ് ചെയ്താലോ ഫീൽഡുകൾ പൂർണ്ണമായും ക്ലിയർ ചെയ്യണം. initial, dirty, valid, invalid, submitting, success, failure, reset, cancel സ്റ്റേറ്റുകൾ വ്യക്തമായി കൈകാര്യം ചെയ്യണം; ഒരു റെക്കോർഡിന്റെ പഴയ വാല്യൂ മറ്റൊരു റെക്കോർഡിലേക്ക് പോകരുത്.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_1'
  AND `description` = 'Reset components cleanly on form submission and modal unmount (useEffect cleanup). Old inputs must never bleed into next entries.\n\nMalayalam: ഫോം സബ്മിറ്റ് ചെയ്താലോ മോഡൽ ക്ലോസ് ചെയ്താലോ ഫീൽഡുകൾ പൂർണ്ണമായും ക്ലിയർ ചെയ്യണം.';

UPDATE `codo_common_rules`
SET `description` = 'Never render a blank screen or plain text spinner during data fetch. Use layout-matching Skeleton Shimmers. Every loading state must have an explicit exit to SUCCESS, EMPTY, ERROR, TIMEOUT or CANCELLED; a skeleton or spinner must never stay active indefinitely.\n\nMalayalam: ഡാറ്റ ഫെച്ച് ചെയ്യുമ്പോൾ ശൂന്യ സ്ക്രീൻ അല്ലെങ്കിൽ സാധാരണ സ്പിന്നർ കാണിക്കരുത്; ലേഔട്ടുമായി പൊരുത്തപ്പെടുന്ന Skeleton Shimmer ഉപയോഗിക്കുക. ഓരോ ലോഡിംഗ് സ്റ്റേറ്റും SUCCESS, EMPTY, ERROR, TIMEOUT, CANCELLED എന്നിവയിൽ ഒന്നിൽ അവസാനിക്കണം; സ്കെലിറ്റൺ/സ്പിന്നർ അനന്തമായി തുടരരുത്.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_19'
  AND `description` = 'Never render a blank screen or plain text spinner during data fetch. Use layout-matching Skeleton Shimmers.\n\nMalayalam: ഡാറ്റ ഫെച്ച് ചെയ്യുമ്പോൾ ശൂന്യ സ്ക്രീൻ അല്ലെങ്കിൽ സാധാരണ സ്പിന്നർ കാണിക്കരുത്; ലേഔട്ടുമായി പൊരുത്തപ്പെടുന്ന Skeleton Shimmer ഉപയോഗിക്കുക.';

UPDATE `codo_common_rules`
SET `description` = 'Any database column used in WHERE, JOIN, ORDER BY, or GROUP BY must be explicitly indexed. Base indexes on real access patterns, inspect EXPLAIN plans for important queries, avoid unnecessary joins, columns and repeated queries, and monitor slow queries; an index alone does not prove a query is fast.\n\nMalayalam: WHERE, JOIN, ORDER BY, അല്ലെങ്കിൽ GROUP BY-യിൽ ഉപയോഗിക്കുന്ന ഏത് ഡാറ്റാബേസ് കോളവും വ്യക്തമായി ഇൻഡക്സ് ചെയ്തിരിക്കണം. യഥാർത്ഥ ക്വറി പാറ്റേണുകൾ അനുസരിച്ച് ഇൻഡക്സ് ചെയ്യുക, പ്രധാന ക്വറികൾക്ക് EXPLAIN പ്ലാൻ പരിശോധിക്കുക, അനാവശ്യ JOIN/കോളങ്ങൾ ഒഴിവാക്കുക, സ്ലോ ക്വറികൾ നിരീക്ഷിക്കുക.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_21'
  AND `description` = 'Any database column used in WHERE, JOIN, ORDER BY, or GROUP BY must be explicitly indexed.\n\nMalayalam: WHERE, JOIN, ORDER BY, അല്ലെങ്കിൽ GROUP BY-യിൽ ഉപയോഗിക്കുന്ന ഏത് ഡാറ്റാബേസ് കോളവും വ്യക്തമായി ഇൻഡക്സ് ചെയ്തിരിക്കണം.';

UPDATE `codo_common_rules`
SET `description` = 'Tables expecting more than 100 entries must implement server-side Pagination or Infinite Scroll bounds. APIs must return only the records and fields the client needs, using pagination, filters and selective fields instead of sending thousands of rows or unused columns.\n\nMalayalam: 100-ൽ അധികം എൻട്രികൾ പ്രതീക്ഷിക്കുന്ന ടേബിളുകളിൽ സർവർ-സൈഡ് പേജിനേഷൻ അല്ലെങ്കിൽ Infinite Scroll നടപ്പിലാക്കണം. API-കൾ ക്ലയന്റിന് ആവശ്യമുള്ള റെക്കോർഡുകളും ഫീൽഡുകളും മാത്രം നൽകണം; ആയിരക്കണക്കിന് റോകളോ ഉപയോഗിക്കാത്ത കോളങ്ങളോ അയക്കരുത്.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_22'
  AND `description` = 'Tables expecting more than 100 entries must implement server-side Pagination or Infinite Scroll bounds.\n\nMalayalam: 100-ൽ അധികം എൻട്രികൾ പ്രതീക്ഷിക്കുന്ന ടേബിളുകളിൽ സർവർ-സൈഡ് പേജിനേഷൻ അല്ലെങ്കിൽ Infinite Scroll നടപ്പിലാക്കണം.';

UPDATE `codo_common_rules`
SET `description` = 'After a successful POST, PUT, PATCH, or DELETE (create, update, delete, bulk update or status change), invalidate or update every frontend query and state that depends on the changed resource, including lists, detail views, counters, totals, dashboards, filters and pagination, then refetch so the UI shows the latest data. A 200 response alone does not prove the UI is correct.\n\nMalayalam: POST, PUT, PATCH, DELETE വിജയിച്ച ശേഷം ആ ഡാറ്റയെ ആശ്രയിക്കുന്ന ഫ്രണ്ട്എൻഡ് ക്വറികളും സ്റ്റേറ്റും ഇൻവാലിഡേറ്റ് ചെയ്ത് വീണ്ടും ഫെച്ച് ചെയ്യണം. പഴയ ലിസ്റ്റ് സ്ക്രീനിൽ നിൽക്കരുത്. ലിസ്റ്റ്, ഡീറ്റെയിൽ, കൗണ്ടർ, ടോട്ടൽ, ഡാഷ്ബോർഡ്, ഫിൽട്ടർ, പേജിനേഷൻ എല്ലാം അപ്ഡേറ്റ് ആകണം; API 200 നൽകിയതുകൊണ്ട് മാത്രം UI ശരിയാണെന്ന് കരുതരുത്.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_47'
  AND `description` = 'After a successful POST, PUT, PATCH, or DELETE, invalidate or update every frontend query and state that depends on the changed resource, then refetch so the UI shows the latest data.\n\nMalayalam: POST, PUT, PATCH, DELETE വിജയിച്ച ശേഷം ആ ഡാറ്റയെ ആശ്രയിക്കുന്ന ഫ്രണ്ട്എൻഡ് ക്വറികളും സ്റ്റേറ്റും ഇൻവാലിഡേറ്റ് ചെയ്ത് വീണ്ടും ഫെച്ച് ചെയ്യണം. പഴയ ലിസ്റ്റ് സ്ക്രീനിൽ നിൽക്കരുത്.';

UPDATE `codo_common_rules`
SET `description` = 'Every frontend data cache must have an explicit owner, stale time, and cache time, and must define what is cached, why, who can access it, when it refreshes and what happens after a mutation. Invalidate related queries after mutations, cancel obsolete requests, and do not keep the same server data in multiple uncontrolled stores; each piece of business state has one source of truth. Never cache private or user-specific data unintentionally.\n\nMalayalam: ഓരോ ഫ്രണ്ട്എൻഡ് ഡാറ്റ കാഷിനും വ്യക്തമായ ഉടമ, stale time, cache time ഉണ്ടായിരിക്കണം. മ്യൂട്ടേഷന് ശേഷം ബന്ധപ്പെട്ട ക്വറികൾ ഇൻവാലിഡേറ്റ് ചെയ്യുക. ഒരേ സെർവർ ഡാറ്റ പല അനിയന്ത്രിത സ്റ്റോറുകളിൽ സൂക്ഷിക്കരുത്. എന്ത്, എന്തിന്, ആർക്ക് ആക്സസ്, എപ്പോൾ റിഫ്രഷ് എന്നിവ വ്യക്തമാക്കണം; സ്വകാര്യ/യൂസർ-സ്പെസിഫിക് ഡാറ്റ അബദ്ധത്തിൽ കാഷ് ചെയ്യരുത്.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_48'
  AND `description` = 'Every frontend data cache must have an explicit owner, stale time, and cache time. Invalidate related queries after mutations, cancel obsolete requests, and do not keep the same server data in multiple uncontrolled stores.\n\nMalayalam: ഓരോ ഫ്രണ്ട്എൻഡ് ഡാറ്റ കാഷിനും വ്യക്തമായ ഉടമ, stale time, cache time ഉണ്ടായിരിക്കണം. മ്യൂട്ടേഷന് ശേഷം ബന്ധപ്പെട്ട ക്വറികൾ ഇൻവാലിഡേറ്റ് ചെയ്യുക. ഒരേ സെർവർ ഡാറ്റ പല അനിയന്ത്രിത സ്റ്റോറുകളിൽ സൂക്ഷിക്കരുത്.';

UPDATE `codo_common_rules`
SET `description` = 'Version the service worker and static assets (content-hashed filenames), define cache strategies explicitly, never serve stale dynamic or private API data, serve index.html with Cache-Control: no-cache, and invalidate old caches on activation so every deployment reaches users without Ctrl+Shift+R, clearing site data, deleting cookies or restarting the browser.\n\nMalayalam: സർവീസ് വർക്കറും സ്റ്റാറ്റിക് അസറ്റുകളും വേർഷൻ ചെയ്യുക. ഡൈനാമിക്/സ്വകാര്യ API ഡാറ്റ പഴയ കാഷായി നൽകരുത്. ആക്ടിവേഷനിൽ പഴയ കാഷ് മായ്ക്കുക; യൂസർ സൈറ്റ് ഡാറ്റ മായ്ക്കേണ്ടി വരരുത്. index.html-ന് no-cache നൽകുക; ഓരോ ഡിപ്ലോയ്മെന്റും Ctrl+Shift+R, കുക്കി ഡിലീറ്റ്, ബ്രൗസർ റീസ്റ്റാർട്ട് ഇല്ലാതെ യൂസർക്ക് ലഭിക്കണം.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_49'
  AND `description` = 'Version the service worker and static assets, define cache strategies explicitly, never serve stale dynamic or private API data, and invalidate old caches on activation so updates apply without users clearing site data.\n\nMalayalam: സർവീസ് വർക്കറും സ്റ്റാറ്റിക് അസറ്റുകളും വേർഷൻ ചെയ്യുക. ഡൈനാമിക്/സ്വകാര്യ API ഡാറ്റ പഴയ കാഷായി നൽകരുത്. ആക്ടിവേഷനിൽ പഴയ കാഷ് മായ്ക്കുക; യൂസർ സൈറ്റ് ഡാറ്റ മായ്ക്കേണ്ടി വരരുത്.';

UPDATE `codo_common_rules`
SET `description` = 'Authenticated API requests must use one consistent strategy for cookies, credentials, Authorization headers, CSRF tokens, SameSite, Secure/HTTPS, and CORS. When a session expires during an active operation, stop the loading state, keep the user''s unsaved input, prompt re-authentication, and never allow a silent failure or an unauthorized mutation.\n\nMalayalam: ഓതന്റിക്കേഷൻ വേണ്ട എല്ലാ API അഭ്യർത്ഥനകളും കുക്കി, ക്രെഡൻഷ്യൽ, Authorization, CSRF, SameSite, Secure, CORS എന്നിവയിൽ ഒരേ തന്ത്രം ഉപയോഗിക്കണം. ഓപ്പറേഷനിടയിൽ സെഷൻ എക്സ്പയർ ആയാൽ ലോഡിംഗ് നിർത്തി, സേവ് ചെയ്യാത്ത ഡാറ്റ നിലനിർത്തി, വീണ്ടും ലോഗിൻ ആവശ്യപ്പെടണം; നിശ്ശബ്ദ പരാജയമോ അനധികൃത മ്യൂട്ടേഷനോ പാടില്ല.'
WHERE `phase` = 'developer' AND `rule_key` = 'dev_rule_50'
  AND `description` = 'Authenticated API requests must use one consistent strategy for cookies, credentials, Authorization headers, CSRF tokens, SameSite, Secure/HTTPS, and CORS.\n\nMalayalam: ഓതന്റിക്കേഷൻ വേണ്ട എല്ലാ API അഭ്യർത്ഥനകളും കുക്കി, ക്രെഡൻഷ്യൽ, Authorization, CSRF, SameSite, Secure, CORS എന്നിവയിൽ ഒരേ തന്ത്രം ഉപയോഗിക്കണം.';

UPDATE `codo_common_rules`
SET `description` = 'Stress-test button structures via continuous high-speed double and triple clicks. Ensure execution locks prevent duplicate API records. Reject if duplicate calls fire or spinner is missing. Also retry after a slow response and confirm no duplicate record is created.\n\nMalayalam: ബട്ടണുകളിൽ തുടർച്ചയായ ഉയർന്ന വേഗത്തിലുള്ള ഡബിൾ/ട്രിപ്പിൾ ക്ലിക്കുകൾ ചെയ്ത് സ്ട്രെസ്-ടെസ്റ്റ് ചെയ്യുക. ഡ്യൂപ്ലിക്കേറ്റ് API റെക്കോർഡുകൾ തടയുന്ന ലോക്കുകൾ ഉറപ്പാക്കുക. സ്ലോ റെസ്പോൺസിന് ശേഷം റീട്രൈ ചെയ്താലും ഡ്യൂപ്ലിക്കേറ്റ് റെക്കോർഡ് ഉണ്ടാകരുത്.'
WHERE `phase` = 'tester' AND `rule_key` = 'qa_click_attack'
  AND `description` = 'Stress-test button structures via continuous high-speed double and triple clicks. Ensure execution locks prevent duplicate API records. Reject if duplicate calls fire or spinner is missing.\n\nMalayalam: ബട്ടണുകളിൽ തുടർച്ചയായ ഉയർന്ന വേഗത്തിലുള്ള ഡബിൾ/ട്രിപ്പിൾ ക്ലിക്കുകൾ ചെയ്ത് സ്ട്രെസ്-ടെസ്റ്റ് ചെയ്യുക. ഡ്യൂപ്ലിക്കേറ്റ് API റെക്കോർഡുകൾ തടയുന്ന ലോക്കുകൾ ഉറപ്പാക്കുക.';

UPDATE `codo_common_rules`
SET `description` = 'Open form modals, alter form field strings, and simulate a layout close command. Verify warning safeguards capture user context safely. Then walk the full form lifecycle: open → edit → cancel, validation error, API error, success, refresh and navigation. Reject if any path corrupts state or leaks old values.\n\nMalayalam: ഫോം മോഡലുകൾ തുറന്ന് ഫീൽഡുകൾ മാറ്റി ക്ലോസ് ചെയ്യാൻ ശ്രമിക്കുക. Unsaved Changes വാണിംഗ് യൂസർ കോൺടെക്സ്റ്റ് സുരക്ഷിതമായി പിടിക്കുന്നുണ്ടോ എന്ന് പരിശോധിക്കുക. open → edit → cancel / validation error / API error / success / refresh / navigation എല്ലാ വഴികളും ടെസ്റ്റ് ചെയ്യുക; സ്റ്റേറ്റ് തെറ്റിയാൽ റിജക്ട് ചെയ്യുക.'
WHERE `phase` = 'tester' AND `rule_key` = 'qa_input_interception'
  AND `description` = 'Open form modals, alter form field strings, and simulate a layout close command. Verify warning safeguards capture user context safely.\n\nMalayalam: ഫോം മോഡലുകൾ തുറന്ന് ഫീൽഡുകൾ മാറ്റി ക്ലോസ് ചെയ്യാൻ ശ്രമിക്കുക. Unsaved Changes വാണിംഗ് യൂസർ കോൺടെക്സ്റ്റ് സുരക്ഷിതമായി പിടിക്കുന്നുണ്ടോ എന്ന് പരിശോധിക്കുക.';

UPDATE `codo_common_rules`
SET `description` = 'Drop network visibility mid-action or check server error routing. Confirm immediate user notifications via interactive Toast alerts. Test ONLINE → OFFLINE → ONLINE during important operations, then retry after the failure: the app must recover, show the correct state and create no duplicate data.\n\nMalayalam: ആക്ഷൻ നടക്കുമ്പോൾ നെറ്റ്‌വർക്ക് ഡ്രോപ്പ് ചെയ്യുകയോ സർവർ എറർ റൂട്ടിംഗ് പരിശോധിക്കുകയോ ചെയ്യുക. Toast അലേർട്ടുകൾ ഉടൻ കാണിക്കുന്നുണ്ടെന്ന് ഉറപ്പാക്കുക. ONLINE → OFFLINE → ONLINE ടെസ്റ്റ് ചെയ്ത് റീട്രൈ ചെയ്യുക; ആപ്പ് ശരിയായി റിക്കവർ ചെയ്യണം, ഡ്യൂപ്ലിക്കേറ്റ് ഡാറ്റ ഉണ്ടാകരുത്.'
WHERE `phase` = 'tester' AND `rule_key` = 'qa_network_break'
  AND `description` = 'Drop network visibility mid-action or check server error routing. Confirm immediate user notifications via interactive Toast alerts.\n\nMalayalam: ആക്ഷൻ നടക്കുമ്പോൾ നെറ്റ്‌വർക്ക് ഡ്രോപ്പ് ചെയ്യുകയോ സർവർ എറർ റൂട്ടിംഗ് പരിശോധിക്കുകയോ ചെയ്യുക. Toast അലേർട്ടുകൾ ഉടൻ കാണിക്കുന്നുണ്ടെന്ന് ഉറപ്പാക്കുക.';

UPDATE `codo_common_rules`
SET `description` = 'Load views with 100+ records. Reject if pagination is missing or the UI stutters under load. Test every important list with 0, 1, normal and realistic production-scale records; five dummy rows are not enough.\n\nMalayalam: 100+ റെക്കോർഡുകളുള്ള വ്യൂകൾ ലോഡ് ചെയ്യുക. പേജിനേഷൻ ഇല്ലെങ്കിലോ UI സ്റ്റട്ടർ ആയാലോ റിജക്ട് ചെയ്യുക. 0, 1, സാധാരണ, പ്രൊഡക്ഷൻ-സ്കെയിൽ റെക്കോർഡുകൾ ഉപയോഗിച്ച് ടെസ്റ്റ് ചെയ്യുക; അഞ്ച് ഡമ്മി റോ മതിയാവില്ല.'
WHERE `phase` = 'tester' AND `rule_key` = 'qa_high_volume'
  AND `description` = 'Load views with 100+ records. Reject if pagination is missing or the UI stutters under load.\n\nMalayalam: 100+ റെക്കോർഡുകളുള്ള വ്യൂകൾ ലോഡ് ചെയ്യുക. പേജിനേഷൻ ഇല്ലെങ്കിലോ UI സ്റ്റട്ടർ ആയാലോ റിജക്ട് ചെയ്യുക.';

-- ── Backfill compliance checks for open projects ────────────────────────────
INSERT IGNORE INTO `project_compliance_checks` (`project_id`, `phase`, `rule_key`, `verified`)
SELECT pc.`project_id`, 'developer', k.`rule_key`, 0
FROM `project_compliance` pc
JOIN `projects` p ON p.`id` = pc.`project_id`
CROSS JOIN (
  SELECT 'dev_rule_51' AS `rule_key` UNION ALL
  SELECT 'dev_rule_52' UNION ALL
  SELECT 'dev_rule_53' UNION ALL
  SELECT 'dev_rule_54' UNION ALL
  SELECT 'dev_rule_55' UNION ALL
  SELECT 'dev_rule_56' UNION ALL
  SELECT 'dev_rule_57' UNION ALL
  SELECT 'dev_rule_58' UNION ALL
  SELECT 'dev_rule_59' UNION ALL
  SELECT 'dev_rule_60' UNION ALL
  SELECT 'dev_rule_61' UNION ALL
  SELECT 'dev_rule_62' UNION ALL
  SELECT 'dev_rule_63' UNION ALL
  SELECT 'dev_rule_64' UNION ALL
  SELECT 'dev_rule_65' UNION ALL
  SELECT 'dev_rule_66' UNION ALL
  SELECT 'dev_rule_67'
) k
WHERE p.`status` NOT IN ('completed', 'release_ready', 'archived')
  AND pc.`pipeline_stage` <> 'admin_ready';

INSERT IGNORE INTO `project_compliance_checks` (`project_id`, `phase`, `rule_key`, `verified`)
SELECT pc.`project_id`, 'tester', k.`rule_key`, 0
FROM `project_compliance` pc
JOIN `projects` p ON p.`id` = pc.`project_id`
CROSS JOIN (
  SELECT 'qa_loading_lifecycle' AS `rule_key` UNION ALL
  SELECT 'qa_data_reconciliation' UNION ALL
  SELECT 'qa_mutation_sync' UNION ALL
  SELECT 'qa_race_condition' UNION ALL
  SELECT 'qa_navigation_during_requests' UNION ALL
  SELECT 'qa_slow_api_timeout' UNION ALL
  SELECT 'qa_cross_browser_data' UNION ALL
  SELECT 'qa_cache_isolation' UNION ALL
  SELECT 'qa_concurrency' UNION ALL
  SELECT 'qa_session_expiry' UNION ALL
  SELECT 'qa_permission_boundary' UNION ALL
  SELECT 'qa_pagination_integrity' UNION ALL
  SELECT 'qa_financial_integrity' UNION ALL
  SELECT 'qa_api_contract' UNION ALL
  SELECT 'qa_production_build_env' UNION ALL
  SELECT 'qa_deployment_smoke' UNION ALL
  SELECT 'qa_regression' UNION ALL
  SELECT 'qa_performance_regression' UNION ALL
  SELECT 'qa_accessibility' UNION ALL
  SELECT 'qa_responsive_matrix' UNION ALL
  SELECT 'qa_release_acceptance'
) k
WHERE p.`status` NOT IN ('completed', 'release_ready', 'archived')
  AND pc.`pipeline_stage` <> 'admin_ready';
