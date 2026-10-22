-- =============================================================================
-- BugRicer Migration 140 — CODO: Hosted payment checkout UPI JS bridge (WebView)
-- DEV-88..92 + QA Stress 47; strengthens DEV-68 / QA Stress 35.
-- Mandatory DoD for any app embedding Cashfree / similar JS checkout in WebView.
-- Idempotent (INSERT IGNORE + UPDATEs). Safe to re-run.
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_general_ci;

-- ── DEV-88: Named JS bridges (deep-link alone is not enough) ─────────────────
INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_88', 'Hosted Checkout UPI JS Bridge (Android + iOS)', 'Rule 88',
 'Mandatory for native apps that open hosted payment checkout (Cashfree / similar JS SDKs) inside InAppWebView, WKWebView, or Android WebView. Do NOT treat UPI deep-link launch as enough: intercepting upi:// / phonepe:// / gpay:// only opens an app AFTER tap; UPI icons appear only when checkout JS can ask the app which UPI apps are installed. Android: register a JavascriptInterface named exactly "Android" exposing getAppList(name) and openApp(package, url). iOS: register a WKScriptMessageHandler named exactly "nativeProcess"; on "getAppList" call window.receiveAppList([{displayName, id, icon}]); before opening a UPI URL call verifyPaymentForiOS(). flutter_inappwebview addJavaScriptHandler alone is NOT enough—it only exposes flutter_inappwebview.callHandler, not window.Android / window.webkit.messageHandlers.nativeProcess. Severity: mandatory Definition of Done. Common failure signals: only “Pay by UPI ID / QR”; only “Any UPI” + one wallet; no PhonePe/GPay/Paytm tiles while Safari/Chrome shows them.\n\nMalayalam: Hosted payment WebView-യിൽ UPI deep-link മാത്രം പോരാ—icons-ന് JS bridge വേണം. Android-ൽ exact name "Android" (getAppList/openApp); iOS-ൽ exact "nativeProcess" + receiveAppList + verifyPaymentForiOS. addJavaScriptHandler മാത്രം പോരാ. Mandatory DoD.',
 88, 1);

-- ── DEV-89: Android discovery query shape + package visibility ───────────────
INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_89', 'Android UPI App Discovery and Package Visibility', 'Rule 89',
 'Android getAppList for Cashfree-shaped JS checkout must use ACTION_VIEW + Uri only. Do NOT add CATEGORY_BROWSABLE on the discovery query or GPay/PhonePe often disappear. Merge results from upi://pay and known PSP schemes (tez://, gpay://, phonepe://, paytmmp://, credpay://, payzapp://, amazonpay://, bhim://, and equivalents). Also check known package names (GPay, PhonePe, Paytm, CRED, PayZapp, etc.). Return JSON [{ "appName", "appPackage" }]. Never load S.browser_fallback_url or the Play Store inside the WebView. For intent://: Intent.parseUri(URI_INTENT_SCHEME), clear component + selector, start only if queryIntentActivities is non-empty; otherwise return false (no store fallback). Android 11+ package visibility: Manifest <queries> must include VIEW intents for every UPI scheme used plus explicit <package> entries for known UPI PSPs—without this, queryIntentActivities returns empty and checkout shows almost no apps. Severity: mandatory. Failure signals: empty/nearly empty UPI grid in-app while Chrome shows apps; Play Store opens inside WebView on intent://.\n\nMalayalam: Android getAppList-ൽ ACTION_VIEW+Uri മാത്രം; CATEGORY_BROWSABLE discovery-യിൽ വേണ്ട. upi://pay + PSP schemes + package names merge; JSON appName/appPackage. Play Store WebView-യിൽ തുറക്കരുത്. intent://-ന് queryIntentActivities empty ആണെങ്കിൽ false. Android 11+ <queries> schemes+packages നിർബന്ധം.',
 89, 1);

-- ── DEV-90: iOS LSApplicationQueriesSchemes ──────────────────────────────────
INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_90', 'iOS UPI Scheme Queries for Checkout App List', 'Rule 90',
 'For iOS WebView hosted checkout, Info.plist LSApplicationQueriesSchemes must list the same UPI / PSP schemes the nativeProcess getAppList path probes (under Apple’s 50-scheme limit). Without matching entries, canOpenURL is always false and the UPI app list returned to the checkout JS is empty even when GPay/PhonePe/Paytm are installed. Keep the scheme list aligned with Android discovery schemes used in Rule 89. Severity: mandatory. Failure signals: iOS in-app shows only UPI ID/QR while Safari shows installed UPI apps.\n\nMalayalam: iOS Info.plist-ൽ LSApplicationQueriesSchemes-ൽ UPI/PSP schemes ഉണ്ടായിരിക്കണം (Apple 50 limit). ഇല്ലെങ്കിൽ canOpenURL എപ്പോഴും false—app list ശൂന്യം. Rule 89-ലെ Android schemes-മായി align ചെയ്യുക.',
 90, 1);

-- ── DEV-91: UA + subframe cancel + return policy ─────────────────────────────
INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_91', 'Payment WebView User-Agent and Subframe Scheme Cancel', 'Rule 91',
 'Keep a real mobile Chrome User-Agent on Android and a real Safari User-Agent on iOS. Append an app token (e.g. product name) only if the hosted site requires it. Do not force a desktop UA. On Android, prefer removing the WebView “wv” marker so checkout treats the shell like a phone browser. Cancel WebView loads for UPI / intent / PSP schemes (including iframes). On Android, use regexToCancelSubFramesLoading (or equivalent) for those schemes because Dart-level cancel is too late for iframes; the pattern must not match https://. After returning from a UPI app, stay on the checkout / pay-return page—do not reload blindly. Keep existing App Store review blocks (signup / Play Store) unchanged. Complements Rule 68 (external handoff) and Rules 88–90 (JS bridge + discovery). Severity: mandatory. Failure signals: “Web Page not available” on upi:// inside WebView; desktop UA; blind reload after UPI return loses checkout state.\n\nMalayalam: Android-ൽ real mobile Chrome UA (wv marker ഒഴിവാക്കുക); iOS-ൽ Safari UA; desktop UA വേണ്ട. UPI/intent iframe loads cancel ചെയ്യുക (Android regexToCancelSubFramesLoading; https:// match ആകരുത്). UPI return-നു് ശേഷം blind reload വേണ്ട.',
 91, 1);

-- ── DEV-92: Merchant flag after bridge is testable ───────────────────────────
INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_92', 'Merchant UPI Intent Flag After Native Bridge Ready', 'Rule 92',
 'Hosted payment links often also need the payment provider (e.g. Cashfree Support) to enable “UPI Intent in JS SDK / WebView” on the merchant. Request that merchant/feature flag ONLY after the native JS bridge (Rules 88–90) and deep-link / intent handler (Rules 68, 91) are in a testable build on physical devices. Icons may take 30–40 minutes after enablement. Document the request, merchant id, and verification time. Provider docs (Cashfree): https://www.cashfree.com/docs/payments/online/mobile/misc/upi_intent_support_js_sdk — apply the same sequencing for equivalent providers. Severity: mandatory process gate (not a substitute for the bridge). Failure signals: bridge missing but flag requested; flag enabled but still only UPI ID/QR because window.Android / nativeProcess absent.\n\nMalayalam: Merchant-ൽ UPI Intent-in-WebView flag ചോദിക്കുന്നത് native bridge+deep-link testable build-നു് ശേഷം മാത്രം. Enable-നു് ശേഷം icons 30–40 മിനിറ്റ് എടുത്തേക്കാം. Bridge ഇല്ലാതെ flag മാത്രം പോരാ.',
 92, 1);

-- ── QA Stress 47: JS checkout UPI DoD ────────────────────────────────────────
INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('tester', 'qa_webview_upi_js_checkout', 'Hosted Checkout UPI App-List & Intent DoD', 'QA Stress 47',
 'Mandatory Definition of Done for any app that embeds hosted JS payment checkout in a WebView. Physical Android and iOS. Device has at least GPay + Paytm (or PhonePe) installed AND SIM-bound. Checkout inside the app must show the same UPI app tiles as mobile Chrome/Safari for installed+bound apps—not only “Any UPI”, one wallet, or “Pay by UPI ID / QR”. Tapping an icon opens that app; canceling returns to checkout without Play Store or a broken page. intent:// must never open Play Store inside the WebView. After UPI return, checkout / pay-return remains usable (no blind reload loss). iOS and Android both verified before release. Note: installed but not SIM-bound UPI apps will not appear (provider/PSP behavior); card-only / non-UPI bank apps will not appear. ₹1–2 sandbox or live test only if policy allows—no large real-money requirement. Reject emulator-only. Common failure screenshot signals: only Pay by UPI ID/QR; only Any UPI + one app; “Web Page not available” on upi://; Play Store opening inside WebView.\n\nMalayalam: Hosted checkout WebView DoD: physical Android+iOS; GPay+Paytm/PhonePe installed+SIM-bound; in-app UPI tiles = Chrome/Safari; tap opens app; cancel → checkout (Play Store/broken page ഇല്ല); intent:// Play Store WebView-യിൽ ഇല്ല; iOS+Android രണ്ടും. Emulator-only reject.',
 47, 1);

-- Strengthen DEV-68: deep-link alone is incomplete for JS checkout icons
UPDATE `codo_common_rules`
SET `description` = 'In Flutter/native WebViews that host payment gateways, select the User-Agent dynamically by platform (Android → Mobile Chrome; iOS → Safari). Explicitly intercept non-HTTP(S) schemes such as upi://, intent://, and gpay://: deny in-WebView navigation and delegate to the external app with LaunchMode.externalApplication so handoff and deep-link returns succeed. Maintain an allowlist of permitted payment schemes/domains; do not launch arbitrary URI schemes from page content. Handle success, failure, pending, cancellation, return from payment app, duplicate callbacks, and repeated launches. A return/deep-link alone must never be treated as proof of payment—verify final status through the trusted backend/provider verification or webhook flow (see Rules 52 and 58). Payment status changes must be idempotent. Test in provider sandbox before production. Use an external browser or native SDK when required by provider policy rather than forcing every flow into a WebView. CRITICAL: for Cashfree / similar JS checkout SDKs, deep-link interception alone does NOT populate UPI app icons—implement Rules 88–92 (named JS bridges, Android discovery + <queries>, iOS LSApplicationQueriesSchemes, subframe cancel, merchant flag sequencing) and pass QA Stress 47 before Done.\n\nMalayalam: പേയ്മെന്റ് WebView-യിൽ platform UA; upi:// intent:// gpay:// externalApplication-ല്; scheme allowlist; return URL മാത്രം payment proof അല്ല—backend/webhook verify (Rules 52/58). JS checkout icons-ന് deep-link മാത്രം പോരാ—Rules 88–92 + QA Stress 47 നിർബന്ധം.',
    `updated_at` = CURRENT_TIMESTAMP
WHERE `rule_key` = 'dev_rule_68';

UPDATE `codo_common_rules`
SET `description` = 'On physical Android and iOS devices, open an in-app payment gateway WebView and verify: UPI/payment app grids are populated on both platforms (for JS SDKs: same tiles as Chrome/Safari for installed+SIM-bound apps—see QA Stress 47), custom-scheme intents (upi://, intent://, gpay://) trigger an external app handshake, and return deep-link callbacks restore session integrity without forcing re-login or losing payment context. Also cover UPI app available/unavailable, success/failure/pending/cancel, return to active app, cold start, repeated callback, app killed during payment, and network lost on return. Never accept the client callback alone as proof of payment—confirm backend/provider verification. Reject emulator-only verification, a blank UPI grid, “only Pay by UPI ID/QR”, “only Any UPI + one app”, “Web Page not available” on upi://, or Play Store opening inside WebView.\n\nMalayalam: യഥാർത്ഥ Android/iOS-ൽ UPI grid + intent handoff + return session. JS checkout-ന് QA Stress 47 കൂടി. Cold start/kill/duplicate callback/offline return ടെസ്റ്റ്; client callback മാത്രം payment proof അല്ല—backend verify; emulator-only/ശൂന്യ grid/ID-QR-only/Play-Store-in-WebView reject.',
    `updated_at` = CURRENT_TIMESTAMP
WHERE `rule_key` = 'qa_in_app_payment_upi';

INSERT IGNORE INTO `project_compliance_checks` (`project_id`, `phase`, `rule_key`, `verified`)
SELECT pc.`project_id`, 'developer', k.`rule_key`, 0
FROM `project_compliance` pc
JOIN `projects` p ON p.`id` = pc.`project_id`
CROSS JOIN (
  SELECT 'dev_rule_88' AS `rule_key` UNION ALL
  SELECT 'dev_rule_89' UNION ALL
  SELECT 'dev_rule_90' UNION ALL
  SELECT 'dev_rule_91' UNION ALL
  SELECT 'dev_rule_92'
) k
WHERE p.`status` NOT IN ('completed', 'release_ready', 'archived')
  AND pc.`pipeline_stage` <> 'admin_ready';

INSERT IGNORE INTO `project_compliance_checks` (`project_id`, `phase`, `rule_key`, `verified`)
SELECT pc.`project_id`, 'tester', k.`rule_key`, 0
FROM `project_compliance` pc
JOIN `projects` p ON p.`id` = pc.`project_id`
CROSS JOIN (
  SELECT 'qa_webview_upi_js_checkout' AS `rule_key`
) k
WHERE p.`status` NOT IN ('completed', 'release_ready', 'archived')
  AND pc.`pipeline_stage` <> 'admin_ready';
