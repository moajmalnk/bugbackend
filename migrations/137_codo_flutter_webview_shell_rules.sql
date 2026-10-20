-- CODO DEV-73..84 + QA Stress 37..44: Flutter URL-based WebView shell rules
-- Also extends DEV-17/63/67/68 and related QA drills for native-shell acceptance.
-- Idempotent: INSERT IGNORE + guarded UPDATEs + open-project compliance backfill

-- ── New App Developer rules ─────────────────────────────────────────────────
INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_73', 'Approved WebView Integration', 'Rule 73',
 'Inspect the existing Flutter/WebView package and version before changes. Use a maintained, compatible package that meets project requirements (such as webview_flutter or flutter_inappwebview when justified). Do not migrate WebView packages merely because another exists—compare required features, maintenance, Android/iOS support, existing integration, and regression risk. Centralize WebView configuration and event handling. Test Android and iOS separately; their WebView engines and native APIs do not behave identically.\n\nMalayalam: Flutter/WebView പാക്കേജ് മാറ്റുന്നതിന് മുമ്പ് നിലവിലുള്ള പാക്കേജും വേർഷനും പരിശോധിക്കുക. ആവശ്യമായ കഴിവുകൾക്ക് maintained package ഉപയോഗിക്കുക; മറ്റൊരു പാക്കേജ് ഉണ്ടെന്നതുകൊണ്ട് മാത്രം migrate ചെയ്യരുത്. WebView config കേന്ദ്രീകരിക്കുക; Android, iOS വേറെ ടെസ്റ്റ് ചെയ്യുക.',
 73, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_74', 'WebView Navigation and External Links', 'Rule 74',
 'Define a clear navigation policy for internal HTTPS routes, approved external websites, app deep links, authentication providers, payment providers, and unsupported schemes. Internal application routes should remain in the WebView when appropriate. Approved external destinations such as YouTube, Google Meet, target="_blank", window.open, and third-party websites must open using the intended native app or a safe browser fallback. Handle new-window navigation explicitly. Validate URLs and schemes against an allowlist. Support Android back: go back in web history when appropriate, otherwise follow documented exit behavior. Never bypass TLS certificate errors or accept invalid certificates in production.\n\nMalayalam: Internal HTTPS routes WebView-യിൽ നിലനിർത്തുക; YouTube, Meet, target=_blank, ബാഹ്യ സൈറ്റുകൾ native/browser-ൽ തുറക്കുക. Scheme allowlist നിർബന്ധം; Android back web history പിന്തുടരണം; production-ൽ TLS bypass അനുവദിക്കരുത്.',
 74, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_75', 'Download, Print, and Document Preview', 'Rule 75',
 'Downloads must work in the app as well as in a normal browser, including authenticated downloads and supported blob: URLs. Handle MIME type, filename, Content-Disposition, cookies/authentication, progress, cancellation, failure, and duplicate taps. Use supported native download/storage mechanisms for the relevant Android/iOS versions; request only permissions actually needed. Printing must be intentional—do not assume window.print() works in every WebView; use a supported native or verified document-print flow. PDF, image, and other supported attachments must provide usable preview/open plus download and print where the file type/platform supports them. Handle missing viewers, expired links, permission denial, unsupported types, large files, and failed downloads with visible recovery. Never log file contents, authorization headers, signed URLs, or other sensitive download data.\n\nMalayalam: Authenticated/blob downloads ആപ്പിൽ പ്രവർത്തിക്കണം; MIME, filename, permissions, progress, cancel, failure കൈകാര്യം ചെയ്യുക. Print intentional native flow വഴി; PDF/image preview+download+print; signed URL/headers ലോഗ് ചെയ്യരുത്.',
 75, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_76', 'WebView Clipboard, Zoom, and Text Interaction', 'Rule 76',
 'Copy, cut, paste, select-all, long-press menus, and keyboard shortcuts must work for supported text controls inside the WebView (inputs, textareas, password/OTP, contenteditable, search, forms). Do not block clipboard with unnecessary gesture handlers, overlays, JavaScript interception, or WebView settings. If native clipboard bridging is required, expose only minimum functionality, validate origin and message data, and do not create an unrestricted JavaScript bridge. Follow the product pinch-zoom policy for fixed mobile interaction while preserving readable responsive layout and accessibility text scaling where possible. Implement viewport and WebView zoom settings consistently on Android and iOS. Do not use zoom disabling as a substitute for fixing a non-responsive website—coordinate viewport and CSS with the web developer.\n\nMalayalam: WebView-യിലെ ഇൻപുട്ടുകളിൽ copy/paste/select-all പ്രവർത്തിക്കണം; അനാവശ്യ gesture/overlay കൊണ്ട് clipboard തടയരുത്. Pinch-zoom policy പാലിച്ച് responsive layout/accessibility നിലനിർത്തുക; zoom disable കൊണ്ട് non-responsive site മറയ്ക്കരുത്.',
 76, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_77', 'WebView Permission Bridge', 'Rule 77',
 'Support the permissions required by actual product features (camera, microphone, location, media/file selection, notifications). Use the correct native runtime permission process and WebView permission callbacks on each platform. Ask at the moment of need and explain purpose clearly. Grant WebView permission only to approved HTTPS origins and only for the resource the user authorized. Handle granted, denied, permanently denied, revoked in OS settings, and unsupported permissions without crashes or infinite loading. Do not repeatedly prompt or silently grant native permissions. Audit permission requests before release so the app does not request unused features.\n\nMalayalam: Camera/mic/location/files/notifications-ന് ശരിയായ native runtime + WebView permission bridge ഉപയോഗിക്കുക; ആവശ്യമുള്ള സമയം മാത്രം ചോദിക്കുക; approved HTTPS origin മാത്രം; deny/permanent-deny crash/infinite load ഇല്ലാതെ recover ചെയ്യുക.',
 77, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_78', 'FCM Push for URL-Based Apps', 'Rule 78',
 'Configure FCM for every app where push notifications are part of the CODO/product delivery standard, unless approved project scope explicitly excludes them. Include required platform configuration, including Apple push setup for iOS where applicable. Request notification permission at an appropriate time and respect OS denial. Register and refresh device tokens safely; associate tokens with the correct authenticated user/device; handle logout/account switching; never log tokens. Handle foreground, background, terminated-app, notification tap, deep-link routing, expired sessions, duplicate notifications, and token refresh. Notification taps must open the correct screen without bypassing authentication or authorization. Validate push payloads; do not place sensitive personal or payment data in notification text unnecessarily.\n\nMalayalam: Scope-ലുള്ള URL apps-ന് FCM (+ iOS APNs) കോൺഫിഗർ ചെയ്യുക; token user/device-മായി ബന്ധിപ്പിക്കുക; logout-ൽ ക്ലിയർ ചെയ്യുക; tap ശരിയ screen-ലേക്ക് (auth bypass ഇല്ല); sensitive data notification text-ൽ ഇടരുത്.',
 78, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_79', 'Secure Persistent App Login', 'Rule 79',
 'Do not store a user''s password in plaintext or weaken backend session expiry just to keep the app logged in. Implement persistent app login using the project''s supported secure authentication strategy (securely stored refresh credentials, token rotation, or a backend-supported mobile session). Use platform-secure storage (iOS Keychain / Android Keystore-backed storage) for sensitive credentials—not ordinary preferences, localStorage, or JavaScript-accessible storage. Handle WebView cookies/session state deliberately; verify required cookies persist across normal app restarts where intended. Access tokens should expire as designed and refresh safely when permitted. Logout must revoke/invalidate the session as supported and clear native and WebView authentication state. Handle account switching, revoked tokens, session expiry, offline launch, and server-side logout. Longer login duration requires an explicit security-approved session policy, not bypassing authentication.\n\nMalayalam: Password plaintext ആയി സൂക്ഷിക്കരുത്; Keychain/Keystore-backed secure storage ഉപയോഗിക്കുക; WebView cookies deliberate ആയി കൈകാര്യം ചെയ്യുക; logout native+WebView auth ക്ലിയർ ചെയ്യണം; session policy bypass ചെയ്യരുത്.',
 79, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_80', 'WebView Network Errors and No Blank Screens', 'Rule 80',
 'Detect loading failure caused by no connection, DNS/TLS errors, timeout, HTTP errors, or server outage and distinguish these cases as far as practical. Show a dedicated offline/network-error state with a visible retry action—do not leave a blank WebView. When connectivity returns, retry or reload using a bounded, deliberate policy; avoid infinite automatic reload loops and repeated API mutations. Preserve safe unsent form data when practical; before retrying a mutation, determine whether the original request may already have succeeded. Use exponential backoff with bounds for suitable transient operations; do not blindly retry payment or other non-idempotent operations. A Service Worker can assist with approved web assets but is not a universal fix for native WebView networking. Flutter-level exceptions, WebView load errors, HTTP failures, JavaScript/web failures, unsupported URLs, and native plugin errors must produce a controlled fallback with retry/back/home when safe. Capture diagnostic context (app version, platform, URL category, error code, correlation ID) while redacting tokens, credentials, personal data, and signed URLs. Do not expose stack traces to end users.\n\nMalayalam: Offline/TLS/HTTP/timeout-ന് dedicated error UI + retry; ശൂന്യ WebView അനുവദിക്കരുത്; bounded reconnect; non-idempotent/payment blind retry വേണ്ട; Flutter/WebView/web error boundaries; sensitive data redact ചെയ്യുക.',
 80, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_81', 'Keyboard, Pickers, and Responsive Shell Contract', 'Rule 81',
 'Forms inside WebView must remain usable when the native keyboard opens, resizes the viewport, switches input type, or closes. Prevent keyboard overlap, stuck keyboard, frozen scrolling, focus loss, hidden submit buttons, and broken scroll position. Test Android and iOS separately, including password managers, autofill, OTP, search, textareas, and numeric/date inputs. Use deliberate resize/inset behavior and scroll-to-focused-input; do not add arbitrary delays as keyboard workarounds. Test every project-specific custom dropdown, select, date/time picker, calendar, modal, popover, autocomplete, and multi-select inside the WebView—not only in a desktop/mobile browser. The App Developer and Web Developer must agree on supported viewport widths, safe areas, navigation, keyboard, dialogs, file workflows, and touch interactions before release. The app must account for status bars, notches, bottom navigation/system bars, keyboard insets, and portrait/landscape. Do not solve layout with arbitrary fixed heights, hidden overflow, or scaling the entire page. Avoid hover-only actions; provide touch-friendly interaction. Test real-device web content on representative Android and iOS devices.\n\nMalayalam: WebView forms keyboard open/close-ൽ usable ആയിരിക്കണം; custom dropdown/date/time pickers WebView-യിൽ ടെസ്റ്റ് ചെയ്യുക; App+Web team viewport/safe-area/keyboard contract യോജിപ്പിക്കുക; real device-ൽ ടെസ്റ്റ് ചെയ്യുക.',
 81, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_82', 'Required App Update Flow', 'Rule 82',
 'Implement a remote-config/backend-driven version policy with separate latest version and minimum supported version where appropriate. Distinguish optional update from mandatory update. A mandatory update must provide a clear reason and a working official store link; users should not bypass it when the installed native binary is below the supported minimum. Compare appropriate native version/build values for each platform and release channel. Do not confuse a website deployment with a native app-binary update. Avoid permanently blocking users because the version-check service is temporarily unavailable—define a safe timeout/fallback policy, using a previously validated policy when appropriate. Test available update, mandatory update, store unavailable, offline startup, staged rollout, internal/test builds, and users returning after updating. Website content may update immediately through the WebView, but native code/plugin/permission changes still require a native app release and store distribution.\n\nMalayalam: Remote min/latest version policy; optional vs mandatory update; mandatory-ന് store link + bypass ഇല്ല; version-check timeout-ൽ permanent block വേണ്ട; website deploy ≠ native binary update.',
 82, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_83', 'App Lifecycle and WebView Process Recovery', 'Rule 83',
 'Handle foreground/background, process recreation, OS memory pressure, WebView process termination, screen rotation, and returning from external apps. Restore safe navigation/authentication state without duplicating mutations or losing important input. If the WebView process terminates, show a recoverable state and reload safely; do not blindly resubmit the last POST or payment request. Validate deep-link parameters before use; handle malformed, expired, duplicated, and unsupported links safely. Test app foreground/background, cold start, already-running app, and browser fallback cases for deep-link returns.\n\nMalayalam: BG/FG, rotation, external-app return, WebView process death കൈകാര്യം ചെയ്യുക; safe reload—POST/payment blind resubmit വേണ്ട; deep-link params validate ചെയ്യുക.',
 83, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_84', 'Native / Web / Backend Responsibility Contract', 'Rule 84',
 'Document which behavior belongs to Flutter native code, which belongs to React/Next.js website code, and which belongs to PHP/Python backend code. Coordinate required changes instead of patching the same behavior independently in multiple layers. Shared workflows such as authentication, download, payment callback, permissions, and push routing must have one documented integration contract.\n\nMalayalam: Flutter native, React/Next website, PHP/Python backend responsibilities ഡോക്യുമെന്റ് ചെയ്യുക; auth/download/payment/permissions/push-ന് ഒരു integration contract മാത്രം.',
 84, 1);

-- ── New Tester / QA rules ───────────────────────────────────────────────────
INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('tester', 'qa_webview_download_print', 'WebView Download, Print and Document Matrix', 'QA Stress 37',
 'On physical Android and iOS (or release-like builds), verify document preview and download/print: open PDF/image/document; rotate screen; return to WebView; check loading/error. Cover normal URL, authenticated URL, Content-Disposition, supported blob: download, large file, cancelled download, denied permission, expired URL, duplicate tap, and download failure. Print a supported page/document on each platform; cancel print; no printer available; check rendered output and page scaling. Reject if browser-only success is treated as app acceptance.\n\nMalayalam: Physical Android/iOS-ൽ PDF/image preview, download matrix (auth/blob/cancel/deny/expire/duplicate), print matrix ടെസ്റ്റ് ചെയ്യുക; browser-only pass reject.',
 37, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('tester', 'qa_webview_external_links', 'WebView External Link and Scheme Drill', 'QA Stress 38',
 'Verify same-site route stays in WebView; external HTTPS, YouTube, Google Meet, target="_blank", window.open open the intended native app or safe browser; unsupported schemes are blocked or fail safely; app installed vs not installed has a safe fallback; Android back follows web history then documented exit. Reject silent ignored new-window links or TLS bypass.\n\nMalayalam: Same-site WebView-യിൽ; external/YouTube/Meet/blank native/browser-ൽ; unsupported scheme safe fail; Android back ശരി; TLS bypass reject.',
 38, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('tester', 'qa_webview_clipboard_zoom', 'WebView Clipboard and Zoom Drill', 'QA Stress 39',
 'On Android and iOS, verify copy/paste/long-press/select-all in text input, textarea, password, OTP, contenteditable, and search controls. Confirm accidental pinch-zoom policy, text scaling, orientation, and readability. Reject blocked clipboard menus or zoom used to hide non-responsive layout.\n\nMalayalam: Android/iOS-ൽ clipboard (input/textarea/password/OTP/contenteditable/search) + zoom/readability ടെസ്റ്റ്; blocked clipboard അല്ലെങ്കിൽ zoom-as-layout-fix reject.',
 39, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('tester', 'qa_webview_permissions', 'WebView Native Permission Bridge Drill', 'QA Stress 40',
 'Test first request, grant, deny, deny permanently, revoke in OS settings, second attempt, unapproved origin request, offline request, and permission not required. Confirm UI explains and recovers without crash or infinite loading. This is OS/WebView permission bridging—not API role AuthZ (see Permission Boundary Test).\n\nMalayalam: Grant/deny/permanent-deny/OS revoke/second attempt/unapproved origin ടെസ്റ്റ്; crash/infinite load ഇല്ല; ഇത് OS permission bridge ആണ് — API AuthZ അല്ല.',
 40, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('tester', 'qa_app_persistent_login', 'App Persistent Login and Secure Storage Drill', 'QA Stress 41',
 'Close and reopen app, background/resume, token expiry, refresh-token failure, logout, account switch, revoke session, and clear app data. Confirm no plaintext password storage and that logout clears native and WebView auth state. Reject weakened backend expiry used only to keep the app logged in.\n\nMalayalam: App reopen/background/token expiry/logout/account switch ടെസ്റ്റ്; plaintext password ഇല്ല; logout native+WebView auth ക്ലിയർ; session expiry weaken ചെയ്ത് login നിലനിർത്തൽ reject.',
 41, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('tester', 'qa_forced_app_update', 'Forced / Optional App Update Drill', 'QA Stress 42',
 'Test current version, optional update, mandatory update, store link, offline startup, remote-config timeout/fallback, and successful update return. Reject if users can bypass mandatory update below minimum supported binary, or if temporary version-check outage permanently blocks all users without a defined fallback.\n\nMalayalam: Optional/mandatory update, store link, offline, remote-config timeout ടെസ്റ്റ്; mandatory bypass അല്ലെങ്കിൽ timeout-ൽ permanent block (fallback ഇല്ലാതെ) reject.',
 42, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('tester', 'qa_webview_keyboard_pickers', 'WebView Keyboard and Picker Compatibility Drill', 'QA Stress 43',
 'Focus each input; open/close keyboard; scroll focused field; submit near the bottom; password manager/autofill; rotate device. Verify custom dropdown/calendar/date/time/multi-select with keyboard visible, modal within scroll container, long lists, timezone, locale, null values, and saved value persistence inside the WebView—not only in a mobile browser.\n\nMalayalam: Keyboard open/close/scroll/submit + autofill/rotate; custom dropdown/date/time pickers WebView-യിൽ (browser മാത്രമല്ല) ടെസ്റ്റ് ചെയ്യുക.',
 43, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('tester', 'qa_fcm_push_routing', 'FCM Push Permission and Deep-Link Routing Drill', 'QA Stress 44',
 'When push is in scope: permission grant/denial, foreground/background/terminated app, token refresh, logout/account switch, notification tap deep link, expired session, duplicate message, and invalid payload. Reject if tap bypasses auth or lands on the wrong screen, or if tokens are not cleared on logout.\n\nMalayalam: Push scope-ൽ permission, FG/BG/terminated, token refresh, logout clear, tap deep link, expired session ടെസ്റ്റ്; auth bypass/wrong screen/token leak reject.',
 44, 1);

-- ── Extend existing developer rules ─────────────────────────────────────────
UPDATE `codo_common_rules`
SET `description` = 'Date and time pickers must handle null/undefined states cleanly and map display formatting separately from ISO payloads. For URL-based Flutter apps, every project-specific custom dropdown, select, date picker, time picker, calendar, modal, popover, autocomplete, and multi-select must also be verified inside the Android/iOS WebView—not only in a desktop or mobile browser.\n\nMalayalam: തീയതി/സമയ പിക്കറുകൾ null/undefined സ്റ്റേറ്റുകൾ ശരിയായി കൈകാര്യം ചെയ്യുകയും ISO പേലോഡിൽ നിന്ന് ഡിസ്പ്ലേ ഫോർമാറ്റിംഗ് വേർതിരിക്കുകയും വേണം. URL-based Flutter apps-ൽ custom pickers Android/iOS WebView-യിലും ടെസ്റ്റ് ചെയ്യണം—browser മാത്രം പോരാ.',
    `updated_at` = CURRENT_TIMESTAMP
WHERE `rule_key` = 'dev_rule_17';

UPDATE `codo_common_rules`
SET `description` = 'Important production operations must emit useful structured logs and monitoring signals (operation, user ID, request ID, outcome, duration). For URL-based Flutter apps, include native app version/build, OS version, WebView/platform version where available, and a correlation/request ID for diagnosable failures (WebView load, download/print, deep-link, permission, push-routing, crashes). Logs must never contain passwords, access tokens, API secrets, sensitive personal data, document contents, or signed URLs.\n\nMalayalam: പ്രധാന പ്രൊഡക്ഷൻ ഓപ്പറേഷനുകൾക്ക് structured ലോഗിംഗ് വേണം. Flutter URL apps-ൽ app version/build, OS, WebView version, correlation ID ഉൾപ്പെടുത്തുക. പാസ്‌വേഡ്, ടോക്കൺ, signed URL, document contents ലോഗ് ചെയ്യരുത്.',
    `updated_at` = CURRENT_TIMESTAMP
WHERE `rule_key` = 'dev_rule_63';

UPDATE `codo_common_rules`
SET `description` = 'A feature is not complete because it works on the developer''s machine. Before release it must be fully implemented, code reviewed, tested, security checked, performance checked, browser checked where applicable, verified as a production build, deployed, and smoke-tested on its critical flow in production. For URL-based Flutter apps, also verify signed/release Android and iOS builds (not debug emulator only), production URLs, permissions, app links, store update behavior, push configuration, download/print, and payment return flow. A website that works in a browser is not sufficient acceptance for the native shell.\n\nMalayalam: ഡെവലപ്പറുടെ മെഷീനിൽ പ്രവർത്തിച്ചതുകൊണ്ട് ഫീച്ചർ പൂർത്തിയായി എന്നല്ല. URL-based Flutter apps-ന് signed/release Android+iOS builds, download/print/payment return/push എന്നിവയും പാസ് വേണം—browser-ൽ website മാത്രം മതിയാവില്ല.',
    `updated_at` = CURRENT_TIMESTAMP
WHERE `rule_key` = 'dev_rule_67';

UPDATE `codo_common_rules`
SET `description` = 'In Flutter/native WebViews that host payment gateways, select the User-Agent dynamically by platform (Android → Mobile Chrome; iOS → Safari). Explicitly intercept non-HTTP(S) schemes such as upi://, intent://, and gpay://: deny in-WebView navigation and delegate to the external app with LaunchMode.externalApplication so UPI/app grids populate and deep-link returns succeed. Maintain an allowlist of permitted payment schemes/domains; do not launch arbitrary URI schemes from page content. Handle success, failure, pending, cancellation, return from payment app, duplicate callbacks, and repeated launches. A return/deep-link alone must never be treated as proof of payment—verify final status through the trusted backend/provider verification or webhook flow (see Rules 52 and 58). Payment status changes must be idempotent. Test in provider sandbox before production. Use an external browser or native SDK when required by provider policy rather than forcing every flow into a WebView.\n\nMalayalam: പേയ്മെന്റ് WebView-യിൽ platform UA; upi:// intent:// gpay:// externalApplication-ല്; scheme allowlist; return URL മാത്രം payment proof അല്ല—backend/webhook verify (Rules 52/58); idempotent; sandbox ടെസ്റ്റ്; provider require ചെയ്താൽ external browser/SDK.',
    `updated_at` = CURRENT_TIMESTAMP
WHERE `rule_key` = 'dev_rule_68';

-- ── Extend existing QA rules ────────────────────────────────────────────────
UPDATE `codo_common_rules`
SET `description` = 'Drop network visibility mid-action or check server error routing. Confirm immediate user notifications via interactive Toast alerts. Test ONLINE → OFFLINE → ONLINE during important operations, then retry after the failure: the app must recover, show the correct state and create no duplicate data. For URL-based Flutter apps, also verify dedicated WebView offline/network-error chrome with a visible retry—never leave a blank WebView—and that reconnect does not create duplicate mutations.\n\nMalayalam: ആക്ഷൻ നടക്കുമ്പോൾ നെറ്റ്‌വർക്ക് ഡ്രോപ്പ് ചെയ്യുക; Toast ഉടൻ കാണിക്കുക; ONLINE→OFFLINE→ONLINE റിക്കവർ; ഡ്യൂപ്ലിക്കേറ്റ് ഇല്ല. Flutter URL apps-ൽ WebView offline/error UI + retry; ശൂന്യ WebView reject.',
    `updated_at` = CURRENT_TIMESTAMP
WHERE `rule_key` = 'qa_network_break';

UPDATE `codo_common_rules`
SET `description` = 'Drive every asynchronous screen through LOADING → SUCCESS, EMPTY, ERROR, TIMEOUT and CANCELLED. Reject if loading can remain indefinitely, a skeleton stays after data loads, skeleton and data render together, or the wrong skeleton remains after navigation. For URL-based Flutter apps, also reject unexplained blank WebViews or Flutter shell hangs after load failures, TLS errors, or WebView process issues.\n\nMalayalam: ഓരോ async സ്ക്രീനും LOADING → SUCCESS, EMPTY, ERROR, TIMEOUT, CANCELLED വഴി ടെസ്റ്റ് ചെയ്യുക. Flutter URL apps-ൽ unexplained blank WebView / shell hang reject.',
    `updated_at` = CURRENT_TIMESTAMP
WHERE `rule_key` = 'qa_loading_lifecycle';

UPDATE `codo_common_rules`
SET `description` = 'Expire the session while viewing data, submitting a form, editing, deleting and uploading. The app must recover safely: no infinite loading, no silent failure, no unauthorized mutation and no data corruption. For URL-based Flutter apps, also test app kill/reopen, background/resume, account switch, and confirm secure storage behavior (no plaintext passwords; logout clears native and WebView auth—see Rule 79).\n\nMalayalam: സെഷൻ എക്സ്പയർ സമയം സുരക്ഷിതമായി റിക്കവർ ചെയ്യണം. Flutter URL apps-ൽ kill/reopen, account switch, secure storage (plaintext password ഇല്ല; logout auth ക്ലിയർ—Rule 79) ടെസ്റ്റ് ചെയ്യുക.',
    `updated_at` = CURRENT_TIMESTAMP
WHERE `rule_key` = 'qa_session_expiry';

UPDATE `codo_common_rules`
SET `description` = 'Never approve a release from development mode alone; test the actual production build. Confirm production points to the production API, database, storage, credentials and configuration. For URL-based Flutter apps, verify signed/staging or release-like Android and iOS builds—do not rely only on the debug emulator.\n\nMalayalam: ഡെവലപ്മെന്റ് മോഡ് മാത്രം നോക്കി റിലീസ് അംഗീകരിക്കരുത്. Flutter URL apps-ന് signed/staging Android+iOS builds ടെസ്റ്റ് ചെയ്യുക—debug emulator മാത്രം പോരാ.',
    `updated_at` = CURRENT_TIMESTAMP
WHERE `rule_key` = 'qa_production_build_env';

UPDATE `codo_common_rules`
SET `description` = 'Every significant change must re-test the existing business flows it can affect. A new feature passing is not enough if an existing feature has broken. For URL-based Flutter apps, every change to the website or the Flutter wrapper must retest the affected shared shell workflows (auth, download, payment return, permissions, push routing).\n\nMalayalam: ഓരോ പ്രധാന മാറ്റത്തിനും ബാധിക്കാവുന്ന flows വീണ്ടും ടെസ്റ്റ് ചെയ്യണം. Flutter URL apps-ൽ website അല്ലെങ്കിൽ Flutter wrapper മാറ്റം shared shell workflows regression ടെസ്റ്റ് ചെയ്യണം.',
    `updated_at` = CURRENT_TIMESTAMP
WHERE `rule_key` = 'qa_regression';

UPDATE `codo_common_rules`
SET `description` = 'Test mobile, tablet, laptop, desktop and large desktop widths in both portrait and landscape. Reject on overflow, clipped controls, unreadable text or broken layouts. For URL-based Flutter apps, also verify notches, safe areas, and keyboard insets inside the native app shell on physical devices.\n\nMalayalam: മൊബൈൽ മുതൽ ലാർജ് ഡെസ്ക്ടോപ്പ് വരെ portrait/landscape ടെസ്റ്റ് ചെയ്യുക. Flutter URL apps-ൽ notch/safe area/keyboard insets native shell-ൽ physical device-ൽ ടെസ്റ്റ് ചെയ്യുക.',
    `updated_at` = CURRENT_TIMESTAMP
WHERE `rule_key` = 'qa_responsive_matrix';

UPDATE `codo_common_rules`
SET `description` = 'Before production approval confirm: critical flows pass, no blocking defects, no critical console errors, no data integrity, authentication or authorization issues, no major loading issues, no obvious browser inconsistencies, no major performance regression, and the production smoke test passes. For URL-based Flutter apps, the applicable native-shell checks (download/preview/print, external/deep links, payment server-verify, clipboard, permissions, persistent login, network recovery, no blank screens, keyboard/pickers, forced update, FCM when in scope, release builds) must also pass—browser-only success is not acceptance.\n\nMalayalam: പ്രൊഡക്ഷൻ അംഗീകാരത്തിന് മുമ്പ് critical flows + smoke പാസ്. Flutter URL apps-ന് native-shell checks കൂടി പാസ് വേണം—browser-only acceptance അല്ല.',
    `updated_at` = CURRENT_TIMESTAMP
WHERE `rule_key` = 'qa_release_acceptance';

UPDATE `codo_common_rules`
SET `description` = 'On physical Android and iOS devices, open an in-app payment gateway WebView and verify: UPI/payment app grids are populated on both platforms, custom-scheme intents (upi://, intent://, gpay://) trigger an external app handshake, and return deep-link callbacks restore session integrity without forcing re-login or losing payment context. Also cover UPI app available/unavailable, success/failure/pending/cancel, return to active app, cold start, repeated callback, app killed during payment, and network lost on return. Never accept the client callback alone as proof of payment—confirm backend/provider verification. Reject emulator-only verification or a blank UPI grid.\n\nMalayalam: യഥാർത്ഥ Android/iOS-ൽ UPI grid + intent handoff + return session. Cold start/kill/duplicate callback/offline return ടെസ്റ്റ്; client callback മാത്രം payment proof അല്ല—backend verify; emulator-only/ശൂന്യ grid reject.',
    `updated_at` = CURRENT_TIMESTAMP
WHERE `rule_key` = 'qa_in_app_payment_upi';

-- ── Backfill compliance checks for open projects ────────────────────────────
INSERT IGNORE INTO `project_compliance_checks` (`project_id`, `phase`, `rule_key`, `verified`)
SELECT pc.`project_id`, 'developer', k.`rule_key`, 0
FROM `project_compliance` pc
JOIN `projects` p ON p.`id` = pc.`project_id`
CROSS JOIN (
  SELECT 'dev_rule_73' AS `rule_key` UNION ALL
  SELECT 'dev_rule_74' UNION ALL
  SELECT 'dev_rule_75' UNION ALL
  SELECT 'dev_rule_76' UNION ALL
  SELECT 'dev_rule_77' UNION ALL
  SELECT 'dev_rule_78' UNION ALL
  SELECT 'dev_rule_79' UNION ALL
  SELECT 'dev_rule_80' UNION ALL
  SELECT 'dev_rule_81' UNION ALL
  SELECT 'dev_rule_82' UNION ALL
  SELECT 'dev_rule_83' UNION ALL
  SELECT 'dev_rule_84'
) k
WHERE p.`status` NOT IN ('completed', 'release_ready', 'archived')
  AND pc.`pipeline_stage` <> 'admin_ready';

INSERT IGNORE INTO `project_compliance_checks` (`project_id`, `phase`, `rule_key`, `verified`)
SELECT pc.`project_id`, 'tester', k.`rule_key`, 0
FROM `project_compliance` pc
JOIN `projects` p ON p.`id` = pc.`project_id`
CROSS JOIN (
  SELECT 'qa_webview_download_print' AS `rule_key` UNION ALL
  SELECT 'qa_webview_external_links' UNION ALL
  SELECT 'qa_webview_clipboard_zoom' UNION ALL
  SELECT 'qa_webview_permissions' UNION ALL
  SELECT 'qa_app_persistent_login' UNION ALL
  SELECT 'qa_forced_app_update' UNION ALL
  SELECT 'qa_webview_keyboard_pickers' UNION ALL
  SELECT 'qa_fcm_push_routing'
) k
WHERE p.`status` NOT IN ('completed', 'release_ready', 'archived')
  AND pc.`pipeline_stage` <> 'admin_ready';
