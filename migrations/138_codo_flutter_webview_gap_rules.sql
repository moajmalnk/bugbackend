-- CODO DEV-85..87 + QA Stress 45..46: gaps from App Developer team requirements
-- Extends DEV-75 (share) and DEV-83 (device lock). Idempotent.

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_85', 'WebView Origin Allowlist and Native Bridge Security', 'Rule 85',
 'Load only approved HTTPS origins in the production WebView. Validate deep-link and navigation targets before use. Secure native–web communication (JavaScript bridges / postMessage): expose only minimum APIs, validate origin and message schema, and never create an unrestricted bridge that can invoke privileged native functions for arbitrary page content. Never bypass TLS certificate validation in production. Coordinate bridge and origin-policy changes with web developers before release.\n\nMalayalam: Production WebView-യിൽ approved HTTPS origins മാത്രം; deep-link validate ചെയ്യുക; JS bridge/postMessage minimum API + origin validation; unrestricted bridge വേണ്ട; TLS bypass production-ൽ അനുവദിക്കരുത്.',
 85, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_86', 'App Session and WebView Data Isolation', 'Rule 86',
 'Cached data, cookies, WebView storage, and local/session storage must not expose one user''s information to another. On logout and account switch, clear account-specific native credentials and WebView authentication/session state (cookies and relevant storage) as supported by the platform. After clearing, the next login must load only the new user''s data without a hard refresh. Never leave the previous user''s private screens, tokens, or document URLs reachable.\n\nMalayalam: Cache/cookies/WebView storage ഒരു user-ന്റെ data മറ്റൊരാൾക്ക് കാണിക്കരുത്; logout/account switch-ൽ native credentials + WebView session ക്ലിയർ ചെയ്യുക; അടുത്ത login-ൽ മുൻ user-ന്റെ private data ഇല്ല.',
 86, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_87', 'WebView Performance and Stability', 'Rule 87',
 'Prevent WebView hangs, keyboard freezes, excessive loading indicators, memory leaks, slow rendering, and unnecessary or duplicate API requests in the native shell. Dispose controllers, listeners, and plugin resources on unmount. Avoid arbitrary delays, forced reloads, or security bypasses as workarounds (see Rule 66). Profile real-device performance for critical flows and keep interaction responsive under typical network conditions.\n\nMalayalam: WebView hang, keyboard freeze, excessive loading, memory leak, slow render, unnecessary API തടയുക; unmount-ൽ resources dispose ചെയ്യുക; setTimeout/reload/security bypass workaround വേണ്ട (Rule 66).',
 87, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('tester', 'qa_webview_session_isolation', 'App Session and WebView Data Isolation Drill', 'QA Stress 45',
 'Login as user A, view private data, logout (or switch account), then login as user B. Confirm B never sees A''s screens, cookies, documents, or tokens—without a hard refresh. Also test clear-app-data and session revoke. Reject any cross-user leak from WebView cache, cookies, or local storage.\n\nMalayalam: User A → logout/switch → User B; B-ക്ക് A-യുടെ screens/cookies/documents/tokens കാണരുത്; hard refresh ഇല്ലാതെ. Cross-user WebView cache/cookie leak reject.',
 45, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('tester', 'qa_webview_performance_stability', 'WebView Performance and Stability Drill', 'QA Stress 46',
 'On physical or release-like Android and iOS builds, walk critical flows under normal and Slow 3G conditions. Reject WebView hangs, keyboard freezes, stuck loaders, obvious memory growth after repeated open/close of heavy screens, and duplicate or runaway API requests. Reject fixes that only hide issues with arbitrary delays or forced reloads.\n\nMalayalam: Physical/release builds-ൽ critical flows + Slow 3G; hang/keyboard freeze/stuck loader/memory growth/duplicate API reject; setTimeout/reload-only "fix" reject.',
 46, 1);

UPDATE `codo_common_rules`
SET `description` = 'Downloads must work in the app as well as in a normal browser, including authenticated downloads and supported blob: URLs. Handle MIME type, filename, Content-Disposition, cookies/authentication, progress, cancellation, failure, and duplicate taps. Use supported native download/storage mechanisms for the relevant Android/iOS versions; request only permissions actually needed. Printing must be intentional—do not assume window.print() works in every WebView; use a supported native or verified document-print flow. PDF, image, and other supported attachments must provide usable preview/open plus download, print, and share where the file type/platform supports them. Handle missing viewers, expired links, permission denial, unsupported types, large files, and failed downloads with visible recovery. Never log file contents, authorization headers, signed URLs, or other sensitive download data.\n\nMalayalam: Authenticated/blob downloads ആപ്പിൽ പ്രവർത്തിക്കണം; MIME, filename, permissions, progress, cancel, failure കൈകാര്യം ചെയ്യുക. Print intentional native flow; PDF/image preview+download+print+share; signed URL/headers ലോഗ് ചെയ്യരുത്.',
    `updated_at` = CURRENT_TIMESTAMP
WHERE `rule_key` = 'dev_rule_75';

UPDATE `codo_common_rules`
SET `description` = 'Handle foreground/background, process recreation, OS memory pressure, WebView process termination, screen rotation, device lock/unlock, and returning from external apps (payments, Meet, etc.). Restore safe navigation/authentication state without duplicating mutations or losing important input. If the WebView process terminates, show a recoverable state and reload safely; do not blindly resubmit the last POST or payment request. Validate deep-link parameters before use; handle malformed, expired, duplicated, and unsupported links safely. Test app foreground/background, cold start, already-running app, and browser fallback cases for deep-link returns.\n\nMalayalam: BG/FG, rotation, device lock, external-app return, WebView process death കൈകാര്യം ചെയ്യുക; safe reload—POST/payment blind resubmit വേണ്ട; deep-link params validate ചെയ്യുക.',
    `updated_at` = CURRENT_TIMESTAMP
WHERE `rule_key` = 'dev_rule_83';

UPDATE `codo_common_rules`
SET `description` = 'On physical Android and iOS (or release-like builds), verify document preview and download/print/share: open PDF/image/document; rotate screen; return to WebView; check loading/error. Cover normal URL, authenticated URL, Content-Disposition, supported blob: download, large file, cancelled download, denied permission, expired URL, duplicate tap, and download failure. Print a supported page/document on each platform; cancel print; no printer available; check rendered output and page scaling. Share via the platform share sheet where in scope. Reject if browser-only success is treated as app acceptance.\n\nMalayalam: Physical Android/iOS-ൽ PDF/image preview, download matrix, print matrix, share ടെസ്റ്റ് ചെയ്യുക; browser-only pass reject.',
    `updated_at` = CURRENT_TIMESTAMP
WHERE `rule_key` = 'qa_webview_download_print';

INSERT IGNORE INTO `project_compliance_checks` (`project_id`, `phase`, `rule_key`, `verified`)
SELECT pc.`project_id`, 'developer', k.`rule_key`, 0
FROM `project_compliance` pc
JOIN `projects` p ON p.`id` = pc.`project_id`
CROSS JOIN (
  SELECT 'dev_rule_85' AS `rule_key` UNION ALL
  SELECT 'dev_rule_86' UNION ALL
  SELECT 'dev_rule_87'
) k
WHERE p.`status` NOT IN ('completed', 'release_ready', 'archived')
  AND pc.`pipeline_stage` <> 'admin_ready';

INSERT IGNORE INTO `project_compliance_checks` (`project_id`, `phase`, `rule_key`, `verified`)
SELECT pc.`project_id`, 'tester', k.`rule_key`, 0
FROM `project_compliance` pc
JOIN `projects` p ON p.`id` = pc.`project_id`
CROSS JOIN (
  SELECT 'qa_webview_session_isolation' AS `rule_key` UNION ALL
  SELECT 'qa_webview_performance_stability'
) k
WHERE p.`status` NOT IN ('completed', 'release_ready', 'archived')
  AND pc.`pipeline_stage` <> 'admin_ready';
