<?php
/**
 * WhatsApp Bot Webhook — APITxt Inbound Handler
 *
 * Why this exists: Receives inbound WhatsApp events from APITxt, drives a
 * per-phone state machine, verifies users via OTP, lets them pick a project,
 * collect bug content + attachments, and submits a bug into BugRicer.
 *
 * Endpoint:  POST /backend/api/whatsapp/webhook.php
 * Auth:      Static secret via APITXT_WEBHOOK_SECRET header (optional guard)
 * Idempotent: Each phone has one row in wa_sessions; re-entrant steps are safe.
 */

// ── Bootstrap ────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/utils.php';
require_once __DIR__ . '/../../config/environment.php';
require_once __DIR__ . '/../../services/APITxtService.php';

header('Content-Type: application/json');

// Use India time consistently for OTP expiry, session timestamps, and logs.
date_default_timezone_set('Asia/Kolkata');

// ── Constants ────────────────────────────────────────────────────────────────
define('SESSION_IDLE_SECS',    30 * 60);   // 30 minutes idle timeout
define('OTP_VALIDITY_SECS',    10 * 60);   // OTP expires in 10 minutes
define('OTP_MAX_ATTEMPTS',     3);         // Maximum OTP attempts per window
define('OTP_RATE_WINDOW_SECS', 10 * 60);  // Attempt-rate window

// Steps
define('STEP_IDLE',              'IDLE');
define('STEP_WAITING_OTP',       'WAITING_OTP');
define('STEP_SELECT_PROJECT',    'SELECT_PROJECT');
define('STEP_AWAITING_CONTENT',  'AWAITING_BUG_CONTENT');
define('STEP_CONFIRM',           'CONFIRM_SUBMISSION');

// ── Reject non-POST early ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

// ── Read raw body once (must happen before any output / stream consumption) ──
$rawBody = trim((string) file_get_contents('php://input'));

// ── Safe test / handshake detection ──────────────────────────────────────────
// APITxt and similar platforms send a probe payload to verify the URL.
// Return 200 immediately so they can confirm the endpoint is alive.
$probePayload = json_decode($rawBody, true);
if (
    is_array($probePayload) &&
    (
        ($probePayload['test'] ?? null) === true ||
        isset($probePayload['handshake']) ||
        ($probePayload['event'] ?? '') === 'test' ||
        ($probePayload['type']  ?? '') === 'test'
    )
) {
    http_response_code(200);
    echo json_encode(['status' => 'success', 'message' => 'Webhook handshake successful']);
    exit;
}

// ── HMAC-SHA256 signature verification (optional but strongly recommended) ───
// Secret is loaded from (in priority order):
//   1. Environment class (.env file)
//   2. $_ENV  (system environment)
//   3. $_SERVER (web-server passed variable)
$webhookSecret = Environment::get('APITXT_WEBHOOK_SECRET', '')
    ?: ($_ENV['APITXT_WEBHOOK_SECRET']    ?? '')
    ?: ($_SERVER['APITXT_WEBHOOK_SECRET'] ?? '');

if ($webhookSecret !== '') {
    // Discover the incoming signature header across LiteSpeed / Apache / Cloudflare.
    // getallheaders() is case-insensitive on PHP 8+ but we normalise manually for safety.
    $incomingSig = '';
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $hName => $hValue) {
            $lower = strtolower($hName);
            if ($lower === 'x-hub-signature-256' || $lower === 'x-apitxt-signature') {
                $incomingSig = trim($hValue);
                break;
            }
        }
    }
    // Fallback: $_SERVER superglobal (Apache/LiteSpeed convert headers to HTTP_*)
    if ($incomingSig === '') {
        $incomingSig = trim(
            $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ??
            $_SERVER['HTTP_X_APITXT_SIGNATURE']  ??
            ''
        );
    }

    if ($incomingSig !== '') {
        $hmac = hash_hmac('sha256', $rawBody, $webhookSecret);

        // Accept both "sha256=<hex>" and raw "<hex>" forms
        $expectedFull = 'sha256=' . $hmac;
        $valid = hash_equals($expectedFull, $incomingSig)
              || hash_equals($hmac, $incomingSig);

        if (!$valid) {
            error_log(sprintf(
                '[WA Webhook] 403 Signature mismatch. '
                . 'Received: "%s" | Expected: "%s" | Headers: %s',
                $incomingSig,
                $expectedFull,
                json_encode(function_exists('getallheaders') ? getallheaders() : [])
            ));
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden', 'detail' => 'Signature mismatch']);
            exit;
        }
    }
    // If no signature header arrived at all but secret is configured, we allow
    // the request through (APITxt may not send signatures on every event type).
    // Tighten this by changing the condition above to ($incomingSig === '') { 403 }.
}

// ── Parse payload ─────────────────────────────────────────────────────────────
$payload = json_decode($rawBody, true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// ── Top-level error fence ─────────────────────────────────────────────────────
// Catches any uncaught exception or fatal error in the processing block and
// returns a clean JSON diagnostic instead of an empty 500 page.
// Helper functions are defined outside this block so they are always available.
try {

// ── DB + service setup ───────────────────────────────────────────────────────
$db     = Database::getInstance()->getConnection();
$apitxt = new APITxtService();

// Keep MySQL session timestamps aligned with PHP (Asia/Kolkata / UTC+05:30).
try {
    $db->exec("SET time_zone = '+05:30'");
} catch (Throwable $e) {
    error_log('[WA Webhook] Failed to set MySQL session timezone: ' . $e->getMessage());
}

// ── Normalise payload — support both APITxt flat and Meta/nested formats ──────
//
// Meta Business API (and some APITxt relay modes) wraps everything in:
//   entry[0].changes[0].value.messages[0]
//
// Direct APITxt flat format uses top-level keys:
//   { from, type, text: { body }, interactive: { button_reply/list_reply }, media: { url, mime_type } }
//
// We normalise both into a single set of local variables so the state machine
// below doesn't have to branch on format.

$fromRaw     = '';
$msgType     = 'text';
$msgText     = '';
$interactiveId = null;
$mediaUrl    = null;
$mediaId     = null;
$mediaMime   = 'application/octet-stream';
$mediaBase64 = null;
$mediaDuration = null;

/**
 * Extract button/list reply id from many APITxt/Meta payload shapes.
 * Why: APITxt sometimes sends type=button (not interactive) and omits nested
 * interactive wrappers; missing this caused Confirm Submit to loop forever.
 */
$extractInteractiveId = static function (array $node): ?string {
    $candidates = [
        $node['interactive']['button_reply']['id'] ?? null,
        $node['interactive']['list_reply']['id'] ?? null,
        $node['button_reply']['id'] ?? null,
        $node['list_reply']['id'] ?? null,
        $node['button']['payload'] ?? null,
        $node['button']['id'] ?? null,
        $node['button']['text'] ?? null,
        is_string($node['button'] ?? null) ? $node['button'] : null,
        $node['interactive']['button_reply']['title'] ?? null,
        $node['button_reply']['title'] ?? null,
    ];
    foreach ($candidates as $c) {
        if (is_string($c) && trim($c) !== '') {
            return trim($c);
        }
    }
    return null;
};

$extractInteractiveTitle = static function (array $node): string {
    return trim(
        (string) (
            $node['interactive']['button_reply']['title'] ??
            $node['interactive']['list_reply']['title'] ??
            $node['button_reply']['title'] ??
            $node['list_reply']['title'] ??
            $node['button']['text'] ??
            $node['button']['title'] ??
            ''
        )
    );
};

if (isset($payload['entry'][0]['changes'][0]['value']['messages'][0])) {
    // ── Meta / nested APITxt relay format ────────────────────────────────────
    $msg     = $payload['entry'][0]['changes'][0]['value']['messages'][0];
    $fromRaw = (string) ($msg['from'] ?? '');
    $msgType = strtolower($msg['type'] ?? 'text');

    if ($msgType === 'text') {
        $msgText = trim($msg['text']['body'] ?? '');
    } elseif (in_array($msgType, ['interactive', 'button', 'button_reply'], true)) {
        $interactiveId = $extractInteractiveId($msg);
        $msgText = $extractInteractiveTitle($msg);
        if ($msgText === '' && is_string($interactiveId)) {
            $msgText = $interactiveId;
        }
    } elseif (isset($msg[$msgType]) && is_array($msg[$msgType])) {
        $mediaUrl  = $msg[$msgType]['url']  ?? $msg[$msgType]['link'] ?? ($msg['media_url'] ?? null);
        $mediaId   = $msg[$msgType]['id'] ?? ($msg['id'] ?? null);
        $mediaMime = $msg[$msgType]['mime_type'] ?? 'application/octet-stream';
        $msgText   = trim($msg[$msgType]['caption'] ?? '');
        $mediaDuration = isset($msg[$msgType]['duration']) ? (int) $msg[$msgType]['duration'] : null;
    }
} elseif (isset($payload['entry'][0]['changes'][0]['value'])) {
    // ── APITxt "value-level" format (no messages[0] wrapper) ──────────────────
    $value   = $payload['entry'][0]['changes'][0]['value'];
    $fromRaw = (string) ($value['from'] ?? $value['sender'] ?? '');
    $msgType = strtolower($value['type'] ?? 'text');

    if ($msgType === 'text') {
        $msgText = trim(
            $value['text']['body'] ??
            (is_string($value['text'] ?? null) ? $value['text'] : '') ??
            ($value['message'] ?? '')
        );
    } elseif (in_array($msgType, ['interactive', 'button', 'button_reply'], true)) {
        $interactiveId = $extractInteractiveId($value);
        $msgText = $extractInteractiveTitle($value);
        if ($msgText === '' && is_string($interactiveId)) {
            $msgText = $interactiveId;
        }
    } elseif (isset($value[$msgType]) && is_array($value[$msgType])) {
        $mediaUrl  = $value[$msgType]['url'] ?? $value[$msgType]['link'] ?? ($value['media_url'] ?? null);
        $mediaId   = $value[$msgType]['id'] ?? null;
        $mediaMime = $value[$msgType]['mime_type'] ?? 'application/octet-stream';
        $msgText   = trim($value[$msgType]['caption'] ?? '');
        $mediaDuration = isset($value[$msgType]['duration']) ? (int) $value[$msgType]['duration'] : null;
    } else {
        $mediaUrl  = $value['media']['url'] ?? ($value['media_url'] ?? null);
        $mediaId   = $value['media']['id'] ?? null;
        $mediaMime = $value['media']['mime_type'] ?? 'application/octet-stream';
    }
} elseif (isset($payload['from'])) {
    // ── Direct / flat APITxt format ───────────────────────────────────────────
    $fromRaw = (string) ($payload['from'] ?? $payload['sender'] ?? '');
    $msgType = strtolower($payload['type'] ?? 'text');

    if ($msgType === 'text') {
        $msgText = trim(
            $payload['text']['body'] ??
            (is_string($payload['text'] ?? null) ? $payload['text'] : '') ??
            ($payload['message'] ?? '')
        );
    } elseif (in_array($msgType, ['interactive', 'button', 'button_reply'], true)) {
        $interactiveId = $extractInteractiveId($payload);
        $msgText = $extractInteractiveTitle($payload);
        if ($msgText === '' && is_string($interactiveId)) {
            $msgText = $interactiveId;
        }
    } elseif (isset($payload[$msgType]) && is_array($payload[$msgType])) {
        $mediaUrl  = $payload[$msgType]['url']  ?? $payload[$msgType]['link'] ?? ($payload['media_url'] ?? null);
        $mediaId   = $payload[$msgType]['id'] ?? null;
        $mediaMime = $payload[$msgType]['mime_type'] ?? 'application/octet-stream';
        $msgText   = trim($payload[$msgType]['caption'] ?? '');
        $mediaDuration = isset($payload[$msgType]['duration']) ? (int) $payload[$msgType]['duration'] : null;
    } else {
        $mediaUrl  = $payload['media']['url'] ?? ($payload['media_url'] ?? null);
        $mediaId   = $payload['media']['id'] ?? null;
        $mediaMime = $payload['media']['mime_type'] ?? 'application/octet-stream';
        $msgText   = trim(is_string($payload['message'] ?? null) ? $payload['message'] : '');
    }
}

// Deep-extract media fields APITxt may place anywhere (media_url, image.id, base64…).
$mediaInfo = waExtractInboundMedia($payload);
$hasMediaAsset = ($mediaInfo['url'] !== null || $mediaInfo['id'] !== null || $mediaInfo['base64'] !== null);
$isInteractiveType = in_array($msgType, ['interactive', 'button', 'button_reply'], true);
if (
    !$isInteractiveType
    && $hasMediaAsset
    && $mediaInfo['type'] !== null
    && in_array($mediaInfo['type'], ['image', 'video', 'audio', 'document', 'sticker'], true)
) {
    // Prefer explicit media when a file asset is present (even if type said "text").
    if ($msgType === 'text' && $msgText !== '' && $mediaInfo['url'] === null && $mediaInfo['id'] === null) {
        // Keep as text — caption-only false positive.
    } else {
        $msgType = $mediaInfo['type'];
    }
}
if (($mediaUrl === null || $mediaUrl === '') && $mediaInfo['url'] !== null) {
    $mediaUrl = $mediaInfo['url'];
}
if (($mediaId === null || $mediaId === '') && $mediaInfo['id'] !== null) {
    $mediaId = $mediaInfo['id'];
}
if (($mediaMime === 'application/octet-stream' || $mediaMime === '') && $mediaInfo['mime'] !== null) {
    $mediaMime = $mediaInfo['mime'];
}
if ($mediaBase64 === null && $mediaInfo['base64'] !== null) {
    $mediaBase64 = $mediaInfo['base64'];
}
if ($mediaDuration === null && $mediaInfo['duration'] !== null) {
    $mediaDuration = $mediaInfo['duration'];
}
if ($msgText === '' && $mediaInfo['caption'] !== null) {
    $msgText = $mediaInfo['caption'];
}

// Voice aliases used by some BSPs / clients.
if (in_array($msgType, ['voice', 'ptt', 'ptv'], true)) {
    $msgType = ($msgType === 'ptv') ? 'video' : 'audio';
}

// Normalise button titles like "✅ Submit" → "submit" for text matching.
$msgTextNorm = strtolower(trim(preg_replace('/[^\p{L}\p{N}\s]+/u', '', $msgText) ?? $msgText));
$msgTextNorm = preg_replace('/\s+/', ' ', $msgTextNorm ?? '') ?: '';

// Deep-scan the whole payload for button replies. APITxt often delivers taps as
 // type=text / empty body, with the real title buried in nested keys.
[$deepId, $deepTitle] = waDeepFindButtonReply($payload);
if (($interactiveId === null || $interactiveId === '') && $deepId !== null) {
    $interactiveId = $deepId;
}
if ($msgText === '' && $deepTitle !== '') {
    $msgText = $deepTitle;
    $msgTextNorm = strtolower(trim(preg_replace('/[^\p{L}\p{N}\s]+/u', '', $msgText) ?? $msgText));
    $msgTextNorm = preg_replace('/\s+/', ' ', $msgTextNorm ?? '') ?: '';
}

// Resolve canonical action from id/title (APITxt frequently returns titles, not ids).
$waAction = waResolveAction($interactiveId, $msgText, $msgTextNorm);

error_log(sprintf(
    '[WA Webhook] Parsed msgType=%s interactiveId=%s msgText=%s action=%s mediaUrl=%s mediaId=%s mime=%s',
    $msgType,
    $interactiveId ?? 'null',
    mb_substr($msgText, 0, 80),
    $waAction ?? 'null',
    $mediaUrl ? mb_substr((string) $mediaUrl, 0, 80) : 'null',
    $mediaId ? mb_substr((string) $mediaId, 0, 40) : 'null',
    $mediaMime
));

$phone    = normaliseIncomingPhone($fromRaw);
$mediaExt = mimeToExt($mediaMime);

if ($phone === '') {
    error_log('[WA Webhook] Skip no_phone. Payload=' . json_encode($payload));
    http_response_code(200); // Return 200 to APITxt — it's a malformed event
    echo json_encode(['ok' => false, 'skip' => 'no_phone']);
    exit;
}

// ── Load or create session ────────────────────────────────────────────────────
$session = getOrCreateSession($db, $phone);

// ── Expire idle sessions ──────────────────────────────────────────────────────
if ($session['current_step'] !== STEP_IDLE) {
    $lastTouch = strtotime($session['last_interaction']);
    if (time() - $lastTouch > SESSION_IDLE_SECS) {
        resetSession($db, $phone);
        $session = getOrCreateSession($db, $phone);
        $apitxt->sendText($phone, "Session expired.\n\nSend any message to continue.");
        http_response_code(200);
        echo json_encode(['ok' => true]);
        exit;
    }
}

// ── Load user by phone ────────────────────────────────────────────────────────
$user = getUserByPhone($db, $phone);

// Persist the resolved user on the active WA session for traceability/debugging.
if ($user !== null) {
    $db->prepare("UPDATE wa_sessions SET user_id=? WHERE phone=?")->execute([$user['id'], $phone]);
}

// ── Unregistered phone guard ──────────────────────────────────────────────────
// Any unrecognised number gets a single, clear denial before we touch the state
// machine. We use $fromRaw (the un-normalised number from the payload) so the
// user sees exactly what we received — helpful when they've saved the wrong format.
if ($user === null) {
    $appUrl = rtrim(Environment::get('APP_BASE_URL', 'https://bugs.bugricer.com'), '/');
    $apitxt->sendText(
        $phone,
        "Access denied\n\n"
        . "This WhatsApp number is not linked to a BugRicer account.\n\n"
        . "Add your phone in Profile:\n{$appUrl}\n\n"
        . "Then message us again."
    );
    http_response_code(200);
    echo json_encode(['status' => 'unregistered_user', 'phone' => $fromRaw]);
    exit;
}

// ── Anytime commands (available in any step for registered users) ─────────────
$cmd = strtolower(trim($msgText));
$isAnytimeMenu = ($waAction === 'menu')
    || in_array($cmd, ['menu', 'projects', 'start', 'hi', 'hello', 'hey'], true);
$isAnytimeHelp = ($waAction === 'help') || $cmd === 'help';
$isCancelAnytime = ($waAction === 'cancel');
$isSubmitAnytime = ($waAction === 'submit');

if ($user !== null && ($isAnytimeMenu || $isAnytimeHelp || $isCancelAnytime || $isSubmitAnytime)) {
    $draftStarted = in_array($session['current_step'], [STEP_AWAITING_CONTENT, STEP_CONFIRM], true)
        && (!empty($session['temp_title']) || !empty($session['selected_project_id']));
    $isGreetingOnly = in_array($cmd, ['hi', 'hello', 'hey'], true) && $waAction === null;

    if ($isAnytimeHelp) {
        sendHelpMessage($apitxt, $phone, $user);
        http_response_code(200);
        echo json_encode(['ok' => true, 'cmd' => 'help']);
        exit;
    }

    // While confirming, Submit must create the bug — handle here so button title
    // matching cannot fall through and re-prompt forever.
    if ($isSubmitAnytime && $session['current_step'] === STEP_CONFIRM) {
        $confirmHandled = true;
        // Fall through into state machine with a forced confirm id.
        $interactiveId = 'confirm_submit';
        $msgTextNorm = 'submit';
        $waAction = 'submit';
    } elseif ($isCancelAnytime && $draftStarted) {
        cleanUpStagedAttachments($db, $phone);
        resetSession($db, $phone);
        $apitxt->sendInteractiveButtons(
            $phone,
            'Cancelled',
            "Draft discarded.\nTap below to start again.",
            [
                ['id' => 'wa_menu', 'title' => 'New bug'],
                ['id' => 'wa_help', 'title' => 'Help'],
            ]
        );
        http_response_code(200);
        echo json_encode(['ok' => true, 'cmd' => 'cancel']);
        exit;
    } elseif ($isAnytimeMenu && (!$isGreetingOnly || !$draftStarted)) {
        openProjectMenu($db, $apitxt, $phone, $user);
        http_response_code(200);
        echo json_encode(['ok' => true, 'cmd' => 'menu']);
        exit;
    }
}

// ── STATE MACHINE ─────────────────────────────────────────────────────────────
$step = $session['current_step'];

switch ($step) {

    // ── IDLE / Entry point ───────────────────────────────────────────────────
    case STEP_IDLE:

        // If already WA-verified, go straight to project selection
        if (!empty($user['is_wa_verified'])) {
            sendProjectPicker($db, $apitxt, $phone, $user);
            setStep($db, $phone, STEP_SELECT_PROJECT);
        } else {
            // Start OTP flow
            sendOtp($db, $apitxt, $phone, $user);
        }
        break;

    // ── OTP verification ─────────────────────────────────────────────────────
    case STEP_WAITING_OTP:
        $otp     = $session['otp_code'];
        $expiry  = strtotime($session['otp_expires_at'] ?? '1970-01-01');
        $attempts = (int)$session['otp_attempts'];
        $windowStart = strtotime($session['otp_first_attempt_at'] ?? '1970-01-01');

        // Rate limit
        if ($attempts >= OTP_MAX_ATTEMPTS && (time() - $windowStart) < OTP_RATE_WINDOW_SECS) {
            $waitMins = ceil((OTP_RATE_WINDOW_SECS - (time() - $windowStart)) / 60);
            $apitxt->sendText($phone, "Too many attempts.\nPlease wait {$waitMins} minute(s), then try again.");
            break;
        }

        // Reset attempt counter after rate window expires
        if ((time() - $windowStart) >= OTP_RATE_WINDOW_SECS) {
            $attempts = 0;
        }

        // Expired
        if (time() > $expiry) {
            $apitxt->sendInteractiveButtons($phone,
                'OTP expired',
                'Your code has expired. Tap below for a new one.',
                [['id' => 'resend_otp', 'title' => 'Resend OTP']]
            );
            break;
        }

        // Interactive button: resend
        if ($interactiveId === 'resend_otp') {
            sendOtp($db, $apitxt, $phone, $user);
            break;
        }

        // Verify OTP
        $enteredOtp = preg_replace('/\D/', '', $msgText);
        $newAttempts = $attempts + 1;
        $firstAttemptAt = ($attempts === 0) ? date('Y-m-d H:i:s') : $session['otp_first_attempt_at'];

        $db->prepare("UPDATE wa_sessions SET otp_attempts=?, otp_first_attempt_at=? WHERE phone=?")
           ->execute([$newAttempts, $firstAttemptAt, $phone]);

        if ($enteredOtp !== $otp) {
            $remaining = max(0, OTP_MAX_ATTEMPTS - $newAttempts);
            $apitxt->sendText($phone, "Incorrect code.\n{$remaining} attempt(s) left.");
            break;
        }

        // OTP correct — mark verified
        $db->prepare("UPDATE users SET is_wa_verified=1, wa_verified_at=NOW() WHERE id=?")
           ->execute([$user['id']]);
        $user['is_wa_verified'] = 1;

        $apitxt->sendText($phone, "Verified. Welcome, *{$user['name']}*.");
        sendProjectPicker($db, $apitxt, $phone, $user);
        setStep($db, $phone, STEP_SELECT_PROJECT);
        break;

    // ── Project selection ─────────────────────────────────────────────────────
    case STEP_SELECT_PROJECT:
        if ($interactiveId === null && $msgText === '') {
            sendProjectPicker($db, $apitxt, $phone, $user);
            break;
        }

        // Browse menu: letter groups / next page (APITxt has no native list menu).
        $browseId = (string) ($interactiveId ?? '');
        if ($browseId === '' && preg_match('/^(A\s*[–-]\s*I|J\s*[–-]\s*R|S\s*[–-]\s*Z|More|Next)$/i', $msgTextNorm)) {
            $mapBrowse = [
                'a i' => 'wa_browse_a',
                'j r' => 'wa_browse_j',
                's z' => 'wa_browse_s',
            ];
            // Normalise en-dash variants already stripped in msgTextNorm.
            if (preg_match('/^a\s*i$/', $msgTextNorm)) {
                $browseId = 'wa_browse_a';
            } elseif (preg_match('/^j\s*r$/', $msgTextNorm)) {
                $browseId = 'wa_browse_j';
            } elseif (preg_match('/^s\s*z$/', $msgTextNorm)) {
                $browseId = 'wa_browse_s';
            }
        }
        if (str_starts_with($browseId, 'wa_browse_') || str_starts_with($browseId, 'wa_more_')) {
            if (preg_match('/^wa_browse_([ajs])$/i', $browseId, $m)) {
                sendProjectBrowsePage($db, $apitxt, $phone, $user, strtolower($m[1]), 1);
                break;
            }
            if (preg_match('/^wa_more_([ajs]|all)_(\d+)$/i', $browseId, $m)) {
                sendProjectBrowsePage($db, $apitxt, $phone, $user, strtolower($m[1]), (int) $m[2]);
                break;
            }
        }

        // Load selectable projects (admin = all; developer/tester = assigned only).
        $memberProjects = waLoadSelectableProjects($db, $user);

        // Expect list-reply / button id = "proj_<uuid>"
        $projectId = null;
        if ($interactiveId && str_starts_with($interactiveId, 'proj_')) {
            $projectId = substr($interactiveId, 5);
        }

        // Text: token, number (within current browse page if set), exact/partial name
        if ($projectId === null && $msgText !== '') {
            if (str_starts_with($msgText, 'proj_')) {
                $projectId = substr($msgText, 5);
            } elseif (preg_match('/^\d{1,2}$/', $msgText)) {
                $pageIds = waGetPickerPageIds($db, $phone);
                $idx = ((int) $msgText) - 1;
                if ($pageIds !== null && isset($pageIds[$idx])) {
                    $projectId = $pageIds[$idx];
                } elseif ($pageIds === null && isset($memberProjects[$idx])) {
                    $projectId = $memberProjects[$idx]['id'];
                }
            } else {
                foreach ($memberProjects as $p) {
                    if (strcasecmp(trim((string) $p['name']), $msgText) === 0) {
                        $projectId = $p['id'];
                        break;
                    }
                }
                // Partial name search → open a filtered menu when multiple match.
                if ($projectId === null) {
                    $needle = mb_strtolower(trim($msgText));
                    $matches = [];
                    foreach ($memberProjects as $p) {
                        if (str_contains(mb_strtolower($p['name']), $needle)) {
                            $matches[] = $p;
                        }
                    }
                    if (count($matches) === 1) {
                        $projectId = $matches[0]['id'];
                    } elseif (count($matches) > 1) {
                        sendProjectMatchMenu($apitxt, $phone, $user, $matches);
                        waSetPickerPageIds($db, $phone, array_column($matches, 'id'));
                        break;
                    }
                }
            }
        }

        if ($projectId === null) {
            $apitxt->sendText($phone, "Project not found.\nOpen the menu or type part of the name.");
            sendProjectPicker($db, $apitxt, $phone, $user);
            break;
        }

        // Verify access against the same role-aware list.
        $project = null;
        foreach ($memberProjects as $p) {
            if ($p['id'] === $projectId) {
                $project = $p;
                break;
            }
        }

        if (!$project) {
            $apitxt->sendText($phone, "You don't have access to that project.");
            sendProjectPicker($db, $apitxt, $phone, $user);
            break;
        }

        waClearPickerPageIds($db, $phone);
        $db->prepare("UPDATE wa_sessions SET selected_project_id=?, temp_title=NULL, temp_description=NULL WHERE phone=?")
           ->execute([$projectId, $phone]);
        setStep($db, $phone, STEP_AWAITING_CONTENT);
        cleanUpStagedAttachments($db, $phone);

        $apitxt->sendInteractiveButtons(
            $phone,
            'Report a bug',
            "Project: *{$project['name']}*\n\n"
            . "Send a *title*, then details, photos, or voice notes.\n"
            . "Tap *Submit* when ready.",
            [
                ['id' => 'cancel_bug', 'title' => 'Cancel'],
                ['id' => 'wa_menu', 'title' => 'Change project'],
            ]
        );
        break;

    // ── Bug content collection ────────────────────────────────────────────────
    case STEP_AWAITING_CONTENT:
        $isSubmitText   = ($waAction === 'submit') || ($msgTextNorm === 'submit');
        $isSubmitButton = in_array((string) $interactiveId, ['submit_bug', 'confirm_submit'], true);
        $isCancelText   = ($waAction === 'cancel') || ($msgTextNorm === 'cancel');
        $isCancelButton = in_array((string) $interactiveId, ['cancel_bug', 'cancel'], true);
        $isSkipDesc     = ($waAction === 'skip')
            || in_array((string) $interactiveId, ['skip_description', 'skip'], true)
            || $msgTextNorm === 'skip';

        if ($isCancelText || $isCancelButton) {
            cleanUpStagedAttachments($db, $phone);
            resetSession($db, $phone);
            $apitxt->sendInteractiveButtons(
                $phone,
                'Cancelled',
                "Draft discarded.\nTap below to start again.",
                [
                    ['id' => 'wa_menu', 'title' => 'New bug'],
                    ['id' => 'wa_help', 'title' => 'Help'],
                ]
            );
            break;
        }

        // Allow skipping description after title is set
        if ($isSkipDesc) {
            $currentSession = getOrCreateSession($db, $phone);
            if (empty($currentSession['temp_title'])) {
                $apitxt->sendText($phone, "Please send a *title* first.");
                break;
            }
            if (empty($currentSession['temp_description'])) {
                $db->prepare("UPDATE wa_sessions SET temp_description=? WHERE phone=?")
                   ->execute(['No description provided.', $phone]);
            }
            sendDraftActions(
                $apitxt,
                $phone,
                "Add screenshots or voice notes (optional).\nOr tap *Submit*."
            );
            break;
        }

        if ($isSubmitText || $isSubmitButton) {
            $currentSession = getOrCreateSession($db, $phone);
            if (empty($currentSession['temp_title'])) {
                $apitxt->sendText($phone, "Please send a *title* before submitting.");
                break;
            }

            $tempTitle = $currentSession['temp_title'];
            $tempDesc  = $currentSession['temp_description'] ?: 'No description';
            $attachCount = countStagedAttachments($db, $phone);
            $descPreview = mb_strlen($tempDesc) > 120 ? (mb_substr($tempDesc, 0, 117) . '...') : $tempDesc;

            $apitxt->sendInteractiveButtons($phone,
                'Confirm',
                "*{$tempTitle}*\n"
                . "{$descPreview}\n"
                . "Files: *{$attachCount}*\n\n"
                . "Submit this bug?",
                [
                    ['id' => 'confirm_submit', 'title' => 'Submit'],
                    ['id' => 'cancel_bug',     'title' => 'Cancel'],
                ]
            );
            setStep($db, $phone, STEP_CONFIRM);
            break;
        }

        // Text: first message = title, second = description
        if ($msgType === 'text' && $msgText !== '') {
            $currentSession = getOrCreateSession($db, $phone);
            if (empty($currentSession['temp_title'])) {
                $db->prepare("UPDATE wa_sessions SET temp_title=? WHERE phone=?")
                   ->execute([mb_substr($msgText, 0, 255), $phone]);
                $apitxt->sendInteractiveButtons(
                    $phone,
                    'Title saved',
                    "Now send details, a screenshot, or a voice note.\nOr tap *Submit*.",
                    [
                        ['id' => 'submit_bug', 'title' => 'Submit'],
                        ['id' => 'cancel_bug', 'title' => 'Cancel'],
                    ]
                );
            } elseif (empty($currentSession['temp_description'])) {
                $db->prepare("UPDATE wa_sessions SET temp_description=? WHERE phone=?")
                   ->execute([$msgText, $phone]);
                sendDraftActions(
                    $apitxt,
                    $phone,
                    "Details saved.\nAdd files or voice notes, or tap *Submit*."
                );
            } else {
                // Extra text appends to description instead of looping the same prompt.
                $merged = rtrim((string) $currentSession['temp_description']) . "\n" . $msgText;
                $db->prepare("UPDATE wa_sessions SET temp_description=? WHERE phone=?")
                   ->execute([mb_substr($merged, 0, 4000), $phone]);
                sendDraftActions(
                    $apitxt,
                    $phone,
                    "Details updated.\nAdd more files, or tap *Submit*."
                );
            }
            break;
        }

        // Media attachment (image / video / voice / document)
        $isMediaMsg = in_array($msgType, ['image', 'video', 'audio', 'document', 'sticker'], true);
        if ($isMediaMsg) {
            waDebugPersistMediaPayload($payload, $msgType, $mediaUrl, $mediaId);

            $currentSession = getOrCreateSession($db, $phone);
            if (empty($currentSession['temp_title'])) {
                $db->prepare("UPDATE wa_sessions SET temp_title=? WHERE phone=?")
                   ->execute(['Bug reported via WhatsApp', $phone]);
            }
            if (empty($currentSession['temp_description'])) {
                $caption = $msgText !== '' ? $msgText : 'No description provided.';
                $db->prepare("UPDATE wa_sessions SET temp_description=? WHERE phone=?")
                   ->execute([$caption, $phone]);
            }

            $result = null;
            if (is_string($mediaBase64) && $mediaBase64 !== '') {
                $binary = base64_decode($mediaBase64, true);
                if ($binary !== false) {
                    $result = $apitxt->storeRawMediaToStaging($binary, $mediaMime, $phone, $mediaExt);
                }
            }
            if ($result === null) {
                $result = $apitxt->downloadAndStoreMediaToStaging(
                    (string) ($mediaUrl ?? ''),
                    $mediaMime,
                    $phone,
                    $mediaExt,
                    $mediaId ? (string) $mediaId : null
                );
            }

            if ($result === null) {
                $apitxt->sendText(
                    $phone,
                    "Got your file, but could not download it.\n"
                    . "Please send it again, or ask admin to set *WHATSAPP_CLOUD_ACCESS_TOKEN*."
                );
                sendDraftActions($apitxt, $phone, "You can still add text or tap *Submit*.");
                break;
            }

            $duration = $mediaDuration;
            if ($duration === null && isset($payload['media']['duration'])) {
                $duration = (int) $payload['media']['duration'];
            }

            $db->prepare(
                "INSERT INTO wa_submission_attachments_temp (phone, file_path, file_name, file_type, duration)
                 VALUES (?, ?, ?, ?, ?)"
            )->execute([$phone, $result['path'], $result['name'], $result['mime'], $duration]);

            $attachCount = countStagedAttachments($db, $phone);
            $kind = str_starts_with($result['mime'], 'audio/')
                ? 'Voice note'
                : (str_starts_with($result['mime'], 'image/') ? 'Photo' : 'File');
            sendDraftActions(
                $apitxt,
                $phone,
                "✅ {$kind} saved (*{$attachCount}* total).\nSend more, or tap *Submit*."
            );
            break;
        }

        sendDraftActions(
            $apitxt,
            $phone,
            "Send a title, details, photo, or voice note.\nThen tap *Submit*."
        );
        break;

    // ── Confirm submission ────────────────────────────────────────────────────
    case STEP_CONFIRM:
        // Prefer the resolved action (handles title-only button taps from APITxt).
        $isConfirm = ($waAction === 'submit')
            || in_array((string) $interactiveId, ['confirm_submit', 'submit_bug', 'submit'], true)
            || $msgTextNorm === 'submit';
        $isCancel  = ($waAction === 'cancel')
            || in_array((string) $interactiveId, ['cancel_bug', 'cancel'], true)
            || $msgTextNorm === 'cancel';

        if ($isCancel) {
            cleanUpStagedAttachments($db, $phone);
            resetSession($db, $phone);
            $apitxt->sendInteractiveButtons(
                $phone,
                'Cancelled',
                "Draft discarded.\nTap below to start again.",
                [
                    ['id' => 'wa_menu', 'title' => 'New bug'],
                    ['id' => 'wa_help', 'title' => 'Help'],
                ]
            );
            break;
        }

        // Allow adding more files on the confirm screen.
        $isMediaMsg = in_array($msgType, ['image', 'video', 'audio', 'document', 'sticker'], true);
        if ($isMediaMsg) {
            waDebugPersistMediaPayload($payload, $msgType, $mediaUrl, $mediaId);
            $result = null;
            if (is_string($mediaBase64) && $mediaBase64 !== '') {
                $binary = base64_decode($mediaBase64, true);
                if ($binary !== false) {
                    $result = $apitxt->storeRawMediaToStaging($binary, $mediaMime, $phone, $mediaExt);
                }
            }
            if ($result === null) {
                $result = $apitxt->downloadAndStoreMediaToStaging(
                    (string) ($mediaUrl ?? ''),
                    $mediaMime,
                    $phone,
                    $mediaExt,
                    $mediaId ? (string) $mediaId : null
                );
            }
            if ($result === null) {
                $apitxt->sendText($phone, "Could not save that file. Try again, then tap *Submit*.");
                break;
            }
            $db->prepare(
                "INSERT INTO wa_submission_attachments_temp (phone, file_path, file_name, file_type, duration)
                 VALUES (?, ?, ?, ?, ?)"
            )->execute([$phone, $result['path'], $result['name'], $result['mime'], $mediaDuration]);
            $attachCount = countStagedAttachments($db, $phone);
            $sess = getOrCreateSession($db, $phone);
            $apitxt->sendInteractiveButtons(
                $phone,
                'Confirm',
                "*{$sess['temp_title']}*\n"
                . "Files: *{$attachCount}*\n\n"
                . "Submit this bug?",
                [
                    ['id' => 'confirm_submit', 'title' => 'Submit'],
                    ['id' => 'cancel_bug', 'title' => 'Cancel'],
                ]
            );
            break;
        }

        if (!$isConfirm) {
            error_log('[WA Webhook] CONFIRM unmatched. payload=' . mb_substr($rawBody, 0, 1500));
            $apitxt->sendInteractiveButtons($phone,
                'Confirm',
                "Tap *Submit* to file, or *Cancel* to discard.",
                [
                    ['id' => 'confirm_submit', 'title' => 'Submit'],
                    ['id' => 'cancel_bug',     'title' => 'Cancel'],
                ]
            );
            break;
        }

        // ── Create the bug ────────────────────────────────────────────────────
        $sess = getOrCreateSession($db, $phone);
        $projectId = $sess['selected_project_id'];

        if (!$projectId) {
            $apitxt->sendText($phone, "⚠️ No project selected. Type *menu* to start again.");
            resetSession($db, $phone);
            break;
        }

        $bugId = generateUuid();
        $title = $sess['temp_title'] ?: 'Bug reported via WhatsApp';
        $desc  = $sess['temp_description'] ?: '';
        $now   = date('Y-m-d H:i:s');

        // Detect if bugs table has 'source' column
        $hasSource = columnExists($db, 'bugs', 'source');
        $hasAudio  = columnExists($db, 'bugs', 'audio_note_url');

        // Pull staged attachments
        $stagedStmt = $db->prepare(
            "SELECT * FROM wa_submission_attachments_temp WHERE phone=? ORDER BY id ASC"
        );
        $stagedStmt->execute([$phone]);
        $staged = $stagedStmt->fetchAll(PDO::FETCH_ASSOC);

        // Find first audio for audio_note_url (final path after move)
        $audioNoteUrl = null;
        foreach ($staged as $att) {
            if (str_starts_with((string) $att['file_type'], 'audio/')) {
                $audioNoteUrl = 'bugs/' . $bugId . '/' . $att['file_name'];
                break;
            }
        }

        // BugRicer uses status='pending' (not 'open') for newly raised bugs.
        $cols   = ['id', 'title', 'description', 'project_id', 'reported_by', 'priority', 'status', 'created_at', 'updated_at'];
        $vals   = [$bugId, $title, $desc, $projectId, $user['id'], 'medium', 'pending', $now, $now];

        if ($hasSource) {
            $cols[] = 'source';
            $vals[] = 'whatsapp';
        }
        if ($hasAudio && $audioNoteUrl !== null) {
            $cols[] = 'audio_note_url';
            $vals[] = $audioNoteUrl;
        }

        try {
            $colList  = implode(', ', $cols);
            $phList   = implode(', ', array_fill(0, count($vals), '?'));
            $db->prepare("INSERT INTO bugs ({$colList}) VALUES ({$phList})")->execute($vals);
        } catch (Throwable $e) {
            error_log('[WA Webhook] Bug insert failed: ' . $e->getMessage());
            $apitxt->sendText($phone,
                "Could not create the bug.\nPlease tap *Submit* again, or type *menu*."
            );
            break;
        }

        // Move staged attachments to bug_attachments
        // Also move files from wa_staging/ to bugs/<bugId>/
        $bugDir = __DIR__ . '/../../uploads/bugs/' . $bugId . '/';
        if (!is_dir($bugDir)) {
            mkdir($bugDir, 0755, true);
        }

        $hasUploadCtx = columnExists($db, 'bug_attachments', 'upload_context');
        $hasDuration  = columnExists($db, 'bug_attachments', 'duration');

        foreach ($staged as $att) {
            $srcPath  = __DIR__ . '/../../uploads/' . $att['file_path'];
            $newName  = $att['file_name'];
            $destRel  = 'bugs/' . $bugId . '/' . $newName;
            $destAbs  = $bugDir . $newName;

            // Move file from staging to bug dir
            if (file_exists($srcPath)) {
                rename($srcPath, $destAbs);
            }

            $attId = generateUuid();

            if ($hasUploadCtx && $hasDuration) {
                $db->prepare(
                    "INSERT INTO bug_attachments (id, bug_id, file_name, file_path, file_type, uploaded_by, upload_context, duration)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                )->execute([$attId, $bugId, $newName, $destRel, $att['file_type'], $user['id'], 'whatsapp', $att['duration']]);
            } elseif ($hasDuration) {
                $db->prepare(
                    "INSERT INTO bug_attachments (id, bug_id, file_name, file_path, file_type, uploaded_by, duration)
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                )->execute([$attId, $bugId, $newName, $destRel, $att['file_type'], $user['id'], $att['duration']]);
            } else {
                $db->prepare(
                    "INSERT INTO bug_attachments (id, bug_id, file_name, file_path, file_type, uploaded_by)
                     VALUES (?, ?, ?, ?, ?, ?)"
                )->execute([$attId, $bugId, $newName, $destRel, $att['file_type'], $user['id']]);
            }
        }

        // Clean up staging rows
        $db->prepare("DELETE FROM wa_submission_attachments_temp WHERE phone=?")->execute([$phone]);

        // Create in-app notification for project members
        createBugNotification($db, $bugId, $title, $projectId, $user['id']);

        // Reset session
        resetSession($db, $phone);

        // Send confirmation with deep link
        $appBaseUrl = rtrim(Environment::get('APP_BASE_URL', 'https://bugs.bugricer.com'), '/');
        $bugUrl     = $appBaseUrl . '/bugs/' . $bugId;
        $shortId    = strtoupper(substr(str_replace('-', '', $bugId), 0, 8));
        $attachText = count($staged) > 0 ? "\nFiles: " . count($staged) : '';

        $apitxt->sendInteractiveButtons(
            $phone,
            'Bug filed',
            "Ticket: *{$shortId}*\n"
            . "Title: {$title}"
            . $attachText . "\n\n"
            . "Open:\n{$bugUrl}",
            [
                ['id' => 'wa_menu', 'title' => 'New bug'],
                ['id' => 'wa_help', 'title' => 'Help'],
            ],
            'Thank you'
        );
        break;

    default:
        resetSession($db, $phone);
        $apitxt->sendText($phone, "Session reset.\nSend any message to continue.");
        break;
}

// Touch session timestamp
$db->prepare("UPDATE wa_sessions SET last_interaction=NOW() WHERE phone=?")->execute([$phone]);

http_response_code(200);
echo json_encode(['ok' => true]);

} catch (\Throwable $e) {
    error_log(sprintf(
        '[WA Webhook] Fatal error: %s in %s:%d | Phone: %s | Step: %s',
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $phone ?? 'unknown',
        $session['current_step'] ?? 'unknown'
    ));
    // Always return 200 to the webhook platform so deliveries aren't marked as
    // failed due to transient app errors. The JSON body still contains the
    // diagnostic details for debugging.
    http_response_code(200);
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage(),
        'file'  => basename($e->getFile()),
        'line'  => $e->getLine(),
    ]);
    exit;
}

// ════════════════════════════════════════════════════════════════════════════
// Helper functions
// ════════════════════════════════════════════════════════════════════════════

/** Normalise an inbound phone from APITxt to digits-only E.164 without '+'. */
function normaliseIncomingPhone(string $raw): string
{
    $digits = preg_replace('/\D/', '', $raw);
    // If 10 digits, prepend country code 91 (India default)
    if (strlen($digits) === 10) {
        $digits = '91' . $digits;
    }
    return $digits;
}

/**
 * Recursively find button/list reply id+title anywhere in the webhook payload.
 * @return array{0:?string,1:string}
 */
function waDeepFindButtonReply(array $node): array
{
    $id = null;
    $title = '';

    $walk = function ($n) use (&$walk, &$id, &$title) {
        if (!is_array($n)) {
            return;
        }
        foreach (['button_reply', 'list_reply'] as $key) {
            if (isset($n[$key]) && is_array($n[$key])) {
                if ($id === null && !empty($n[$key]['id'])) {
                    $id = trim((string) $n[$key]['id']);
                }
                if ($title === '' && !empty($n[$key]['title'])) {
                    $title = trim((string) $n[$key]['title']);
                }
            }
        }
        if (isset($n['button']) && is_array($n['button'])) {
            if ($id === null) {
                $cand = $n['button']['payload'] ?? $n['button']['id'] ?? null;
                if (is_string($cand) && trim($cand) !== '') {
                    $id = trim($cand);
                }
            }
            if ($title === '') {
                $cand = $n['button']['text'] ?? $n['button']['title'] ?? null;
                if (is_string($cand) && trim($cand) !== '') {
                    $title = trim($cand);
                }
            }
        }
        foreach ($n as $v) {
            if (is_array($v)) {
                $walk($v);
            }
        }
    };
    $walk($node);

    if ($title === '' && is_string($id)) {
        $title = $id;
    }
    return [$id, $title];
}

/**
 * Deep-scan inbound webhook JSON for media URL / id / mime / base64.
 * Why: APITxt and Meta shapes differ; attachments were ignored when only `id`
 * or top-level `media_url` was present (Files: 0 in confirm).
 *
 * @return array{
 *   type: ?string,
 *   url: ?string,
 *   id: ?string,
 *   mime: ?string,
 *   caption: ?string,
 *   duration: ?int,
 *   base64: ?string
 * }
 */
function waExtractInboundMedia(array $payload): array
{
    $out = [
        'type' => null,
        'url' => null,
        'id' => null,
        'mime' => null,
        'caption' => null,
        'duration' => null,
        'base64' => null,
    ];

    $mediaTypes = ['image', 'video', 'audio', 'document', 'sticker', 'voice', 'ptt', 'ptv'];

    $walk = null;
    $walk = static function ($node, string $parentKey = '') use (&$walk, &$out, $mediaTypes): void {
        if (!is_array($node)) {
            return;
        }

        foreach ($node as $key => $value) {
            $k = is_string($key) ? strtolower($key) : '';

            if (is_string($value)) {
                $v = trim($value);
                if ($v === '') {
                    continue;
                }
                if (in_array($k, ['media_url', 'mediaurl', 'file_url', 'fileurl', 'download_url', 'downloadurl'], true)
                    || (($k === 'url' || $k === 'link') && preg_match('#^https?://#i', $v))
                ) {
                    if ($out['url'] === null && preg_match('#^https?://#i', $v)) {
                        $out['url'] = $v;
                    }
                }
                if (in_array($k, ['mime_type', 'mimetype', 'mime', 'content_type', 'contenttype'], true)) {
                    if ($out['mime'] === null) {
                        $out['mime'] = $v;
                    }
                }
                if (in_array($k, ['caption', 'filename', 'file_name'], true) && $out['caption'] === null) {
                    $out['caption'] = $v;
                }
                if (in_array($k, ['media_id', 'mediaid'], true) && $out['id'] === null) {
                    $out['id'] = $v;
                }
                if ($k === 'id' && in_array($parentKey, $mediaTypes, true) && $out['id'] === null) {
                    $out['id'] = $v;
                }
                if (in_array($k, ['data', 'base64', 'media_base64', 'file_base64'], true)
                    && strlen($v) > 64
                    && $out['base64'] === null
                ) {
                    $out['base64'] = preg_replace('#^data:[^;]+;base64,#', '', $v) ?: $v;
                }
                if ($k === 'type' && in_array(strtolower($v), $mediaTypes, true) && $out['type'] === null) {
                    $out['type'] = strtolower($v);
                }
                continue;
            }

            if (is_int($value) || is_float($value)) {
                if ($k === 'duration' && $out['duration'] === null) {
                    $out['duration'] = (int) $value;
                }
                continue;
            }

            if (is_array($value)) {
                if (in_array($k, $mediaTypes, true) && $out['type'] === null) {
                    $out['type'] = $k;
                }
                $walk($value, $k !== '' ? $k : $parentKey);
            }
        }
    };

    $walk($payload);

    if ($out['type'] !== null) {
        if (in_array($out['type'], ['voice', 'ptt'], true)) {
            $out['type'] = 'audio';
        } elseif ($out['type'] === 'ptv') {
            $out['type'] = 'video';
        }
    }

    // Infer type from mime when missing.
    if ($out['type'] === null && is_string($out['mime'])) {
        $base = strtolower(explode(';', $out['mime'])[0]);
        if (str_starts_with($base, 'image/')) {
            $out['type'] = 'image';
        } elseif (str_starts_with($base, 'audio/')) {
            $out['type'] = 'audio';
        } elseif (str_starts_with($base, 'video/')) {
            $out['type'] = 'video';
        } elseif ($base !== '' && $base !== 'application/octet-stream') {
            $out['type'] = 'document';
        }
    }

    // Infer type when URL/id present but type missing.
    if ($out['type'] === null && ($out['url'] !== null || $out['id'] !== null || $out['base64'] !== null)) {
        $out['type'] = 'document';
    }

    return $out;
}

/** Persist a trimmed media webhook sample for debugging failed downloads. */
function waDebugPersistMediaPayload(array $payload, string $msgType, $mediaUrl, $mediaId): void
{
    $dir = __DIR__ . '/../../uploads/wa_staging/';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $sample = [
        'at' => date('c'),
        'msgType' => $msgType,
        'mediaUrl' => is_string($mediaUrl) ? mb_substr($mediaUrl, 0, 200) : null,
        'mediaId' => is_string($mediaId) ? mb_substr($mediaId, 0, 80) : null,
        'keys' => array_keys($payload),
        'payload' => $payload,
    ];
    @file_put_contents(
        $dir . 'last_media_payload.json',
        json_encode($sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
}

/**
 * Map free-form button titles/ids to a canonical action.
 * Why: APITxt often echoes the visible button label ("Submit") instead of our id.
 */
function waResolveAction(?string $interactiveId, string $msgText, string $msgTextNorm): ?string
{
    $idNorm = strtolower(trim(preg_replace('/[^\p{L}\p{N}\s]+/u', '', (string) $interactiveId) ?? (string) $interactiveId));
    $idNorm = preg_replace('/\s+/', ' ', $idNorm ?? '') ?: '';

    // Prefer exact short replies (actual button taps), not long prompt text.
    $exact = $msgTextNorm !== '' ? $msgTextNorm : $idNorm;
    $map = [
        'submit' => 'submit',
        'confirm submit' => 'submit',
        'confirm_submit' => 'submit',
        'submit bug' => 'submit',
        'submit_bug' => 'submit',
        'cancel' => 'cancel',
        'cancel bug' => 'cancel',
        'cancel_bug' => 'cancel',
        'cancel draft' => 'cancel',
        'projects' => 'menu',
        'project' => 'menu',
        'menu' => 'menu',
        'wa menu' => 'menu',
        'wa_menu' => 'menu',
        'change project' => 'menu',
        'new bug' => 'menu',
        'help' => 'help',
        'wa help' => 'help',
        'wa_help' => 'help',
        'skip' => 'skip',
        'skip description' => 'skip',
        'skip_description' => 'skip',
        'resend otp' => 'resend_otp',
        'resend_otp' => 'resend_otp',
    ];
    if (isset($map[$exact])) {
        return $map[$exact];
    }
    if (isset($map[$idNorm])) {
        return $map[$idNorm];
    }

    // Raw id exact matches (keep underscores).
    $rawId = strtolower(trim((string) $interactiveId));
    if (isset($map[$rawId])) {
        return $map[$rawId];
    }
    if (str_starts_with($rawId, 'proj_')) {
        return 'project';
    }

    // Last-line heuristic for reply-quoted button taps.
    $lines = preg_split('/\R/u', trim($msgText)) ?: [];
    $last = strtolower(trim(preg_replace('/[^\p{L}\p{N}\s]+/u', '', (string) end($lines)) ?? ''));
    $last = preg_replace('/\s+/', ' ', $last ?? '') ?: '';
    if (isset($map[$last])) {
        return $map[$last];
    }

    return null;
}

function mimeToExt(string $mime): string
{
    $map = [
        'audio/ogg'       => 'ogg',
        'audio/mpeg'      => 'mp3',
        'audio/mp4'       => 'm4a',
        'audio/aac'       => 'aac',
        'audio/webm'      => 'webm',
        'video/mp4'       => 'mp4',
        'video/3gpp'      => '3gp',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'application/pdf' => 'pdf',
    ];
    $base = strtolower(explode(';', $mime)[0]);
    return $map[$base] ?? 'bin';
}

function getOrCreateSession(PDO $db, string $phone): array
{
    $stmt = $db->prepare("SELECT * FROM wa_sessions WHERE phone=? LIMIT 1");
    $stmt->execute([$phone]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return $row;
    }
    $db->prepare(
        "INSERT INTO wa_sessions (phone, current_step) VALUES (?, 'IDLE')"
    )->execute([$phone]);
    $stmt->execute([$phone]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function setStep(PDO $db, string $phone, string $step): void
{
    $db->prepare("UPDATE wa_sessions SET current_step=? WHERE phone=?")->execute([$step, $phone]);
}

function resetSession(PDO $db, string $phone): void
{
    $db->prepare(
        "UPDATE wa_sessions SET
           current_step='IDLE',
           selected_project_id=NULL,
           otp_code=NULL,
           otp_expires_at=NULL,
           otp_attempts=0,
           otp_first_attempt_at=NULL,
           temp_title=NULL,
           temp_description=NULL
         WHERE phone=?"
    )->execute([$phone]);
}

function getUserByPhone(PDO $db, string $phone): ?array
{
    $digits = preg_replace('/\D/', '', $phone);
    $variants = array_unique(array_filter([
        $digits,
        '+' . $digits,
        // Local variant without country code, useful when user saved as 10-digit.
        (strlen($digits) > 10) ? substr($digits, -10) : $digits,
        // India E.164-like fallback for 10-digit mobile numbers.
        (strlen($digits) === 10) ? ('91' . $digits) : null,
    ]));

    foreach ($variants as $v) {
        $stmt = $db->prepare(
            "SELECT id,
                     username AS name,
                     email,
                     phone,
                     role,
                     is_wa_verified,
                     wa_verified_at
             FROM users
             WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,'+',''),'-',''),' ',''),'(',''),')','') = ?
             LIMIT 1"
        );
        $stmt->execute([preg_replace('/\D/', '', $v)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
    }
    error_log('[WA Webhook] User not found for phone variants: ' . json_encode($variants));
    return null;
}

function sendOtp(PDO $db, APITxtService $apitxt, string $phone, array $user): void
{
    $otp     = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    $expires = date('Y-m-d H:i:s', time() + OTP_VALIDITY_SECS);

    $db->prepare(
        "UPDATE wa_sessions SET
           current_step='WAITING_OTP',
           otp_code=?,
           otp_expires_at=?,
           otp_attempts=0,
           otp_first_attempt_at=NULL
         WHERE phone=?"
    )->execute([$otp, $expires, $phone]);

    $apitxt->sendText($phone,
        "Hi *{$user['name']}*\n\n"
        . "Your BugRicer verification code:\n"
        . "*{$otp}*\n\n"
        . "Valid for 10 minutes."
    );
}

function openProjectMenu(PDO $db, APITxtService $apitxt, string $phone, array $user): void
{
    cleanUpStagedAttachments($db, $phone);
    $db->prepare(
        "UPDATE wa_sessions SET
           current_step=?,
           selected_project_id=NULL,
           otp_code=NULL,
           otp_expires_at=NULL,
           otp_attempts=0,
           otp_first_attempt_at=NULL,
           temp_title=NULL,
           temp_description=NULL,
           last_interaction=NOW()
         WHERE phone=?"
    )->execute([STEP_SELECT_PROJECT, $phone]);
    sendProjectPicker($db, $apitxt, $phone, $user);
}

function sendHelpMessage(APITxtService $apitxt, string $phone, array $user): void
{
    $apitxt->sendInteractiveButtons(
        $phone,
        'Help',
        "Hi *{$user['name']}*\n\n"
        . "1. Choose a project\n"
        . "2. Send title + details / photos / voice\n"
        . "3. Tap Submit\n\n"
        . "Commands: *menu* · *cancel* · *submit*",
        [
            ['id' => 'wa_menu', 'title' => 'Projects'],
            ['id' => 'cancel_bug', 'title' => 'Cancel draft'],
        ]
    );
}

function sendDraftActions(APITxtService $apitxt, string $phone, string $body): void
{
    $apitxt->sendInteractiveButtons(
        $phone,
        'Bug draft',
        $body,
        [
            ['id' => 'submit_bug', 'title' => 'Submit'],
            ['id' => 'cancel_bug', 'title' => 'Cancel'],
        ]
    );
}

/**
 * Why: Match Projects page visibility — admins see every project; developers
 * and testers only see projects they are assigned to. Order mirrors the app
 * picker: Ongoing → Release Ready → Completed → Archived, then name.
 *
 * @return list<array{id: string, name: string, status?: string}>
 */
function waLoadSelectableProjects(PDO $db, array $user): array
{
    $role = strtolower(trim((string) ($user['role'] ?? '')));
    $isAdmin = ($role === 'admin');

    $orderSql = "ORDER BY
        CASE LOWER(COALESCE(p.status, ''))
            WHEN 'active' THEN 0
            WHEN 'release_ready' THEN 1
            WHEN 'completed' THEN 2
            WHEN 'archived' THEN 3
            ELSE 4
        END ASC,
        p.name ASC";

    if ($isAdmin) {
        $stmt = $db->query(
            "SELECT p.id, p.name, p.status
             FROM projects p
             {$orderSql}
             LIMIT 40"
        );
        $projects = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } else {
        $stmt = $db->prepare(
            "SELECT DISTINCT p.id, p.name, p.status
             FROM projects p
             INNER JOIN project_members pm ON pm.project_id = p.id
             WHERE pm.user_id = ?
             {$orderSql}
             LIMIT 40"
        );
        $stmt->execute([(string) $user['id']]);
        $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $unique = [];
    foreach ($projects as $p) {
        $unique[$p['id']] = [
            'id' => (string) $p['id'],
            'name' => (string) $p['name'],
            'status' => (string) ($p['status'] ?? ''),
        ];
    }
    return array_values($unique);
}

function waIsAdminUser(array $user): bool
{
    return strtolower(trim((string) ($user['role'] ?? ''))) === 'admin';
}

/** Persist current on-screen project ids so reply "1"/"2" maps correctly. */
function waSetPickerPageIds(PDO $db, string $phone, array $ids): void
{
    $payload = '__wa_picker__:' . implode(',', array_map('strval', $ids));
    $db->prepare("UPDATE wa_sessions SET temp_description=? WHERE phone=?")
       ->execute([$payload, $phone]);
}

/** @return list<string>|null */
function waGetPickerPageIds(PDO $db, string $phone): ?array
{
    $stmt = $db->prepare("SELECT temp_description FROM wa_sessions WHERE phone=? LIMIT 1");
    $stmt->execute([$phone]);
    $raw = (string) ($stmt->fetchColumn() ?: '');
    if (!str_starts_with($raw, '__wa_picker__:')) {
        return null;
    }
    $ids = array_values(array_filter(explode(',', substr($raw, strlen('__wa_picker__:')))));
    return $ids === [] ? null : $ids;
}

function waClearPickerPageIds(PDO $db, string $phone): void
{
    $stmt = $db->prepare("SELECT temp_description FROM wa_sessions WHERE phone=? LIMIT 1");
    $stmt->execute([$phone]);
    $raw = (string) ($stmt->fetchColumn() ?: '');
    if (str_starts_with($raw, '__wa_picker__:')) {
        $db->prepare("UPDATE wa_sessions SET temp_description=NULL WHERE phone=?")->execute([$phone]);
    }
}

/**
 * Filter projects by first-letter browse group.
 * @param 'a'|'j'|'s'|'all' $group
 * @param list<array{id:string,name:string,status?:string}> $projects
 * @return list<array{id:string,name:string,status?:string}>
 */
function waFilterProjectsByGroup(array $projects, string $group): array
{
    if ($group === 'all') {
        return $projects;
    }
    $out = [];
    foreach ($projects as $p) {
        $ch = mb_strtoupper(mb_substr(ltrim($p['name']), 0, 1));
        if ($ch < 'A' || $ch > 'Z') {
            // Digits / symbols → put in A–I bucket.
            if ($group === 'a') {
                $out[] = $p;
            }
            continue;
        }
        if ($group === 'a' && $ch >= 'A' && $ch <= 'I') {
            $out[] = $p;
        } elseif ($group === 'j' && $ch >= 'J' && $ch <= 'R') {
            $out[] = $p;
        } elseif ($group === 's' && $ch >= 'S' && $ch <= 'Z') {
            $out[] = $p;
        }
    }
    return $out;
}

function sendProjectMatchMenu(APITxtService $apitxt, string $phone, array $user, array $matches): void
{
    $matches = array_values($matches);
    $slice = array_slice($matches, 0, 8);
    $lines = [];
    foreach ($slice as $i => $p) {
        $lines[] = ($i + 1) . '. ' . $p['name'];
    }
    $buttons = [];
    foreach (array_slice($slice, 0, 2) as $p) {
        $buttons[] = ['id' => 'proj_' . $p['id'], 'title' => mb_substr($p['name'], 0, 20)];
    }
    if (count($matches) > 2) {
        $buttons[] = ['id' => 'wa_browse_a', 'title' => 'Browse menu'];
    }
    while (count($buttons) < 1) {
        break;
    }
    $apitxt->sendInteractiveButtons(
        $phone,
        'Projects',
        "Hi *{$user['name']}*\nMatches (" . count($matches) . "):\n\n"
        . implode("\n", $lines) . "\n\n"
        . "Reply with a *number*, or tap below.",
        $buttons
    );
}

function sendProjectBrowsePage(
    PDO $db,
    APITxtService $apitxt,
    string $phone,
    array $user,
    string $group,
    int $page
): void {
    $page = max(1, $page);
    $all = waLoadSelectableProjects($db, $user);
    $filtered = waFilterProjectsByGroup($all, $group);
    $perPage = 8;
    $total = count($filtered);
    $offset = ($page - 1) * $perPage;
    $slice = array_slice($filtered, $offset, $perPage);

    if ($slice === []) {
        $apitxt->sendText($phone, "No projects in this group.");
        sendProjectPicker($db, $apitxt, $phone, $user);
        return;
    }

    $groupLabel = match ($group) {
        'a' => 'A–I',
        'j' => 'J–R',
        's' => 'S–Z',
        default => 'All',
    };

    $lines = [];
    foreach ($slice as $i => $p) {
        $lines[] = ($i + 1) . '. ' . $p['name'];
    }
    waSetPickerPageIds($db, $phone, array_column($slice, 'id'));

    $buttons = [];
    foreach (array_slice($slice, 0, 2) as $p) {
        $buttons[] = ['id' => 'proj_' . $p['id'], 'title' => mb_substr($p['name'], 0, 20)];
    }
    $hasMore = ($offset + $perPage) < $total;
    if ($hasMore) {
        $buttons[] = ['id' => 'wa_more_' . $group . '_' . ($page + 1), 'title' => 'More ›'];
    } else {
        $buttons[] = ['id' => 'wa_menu', 'title' => 'Main menu'];
    }
    $buttons = array_slice($buttons, 0, 3);

    $pageNote = $total > $perPage
        ? " · page {$page}/" . (int) ceil($total / $perPage)
        : '';

    $apitxt->sendInteractiveButtons(
        $phone,
        'Projects',
        "*{$groupLabel}* ({$total}{$pageNote})\n\n"
        . implode("\n", $lines) . "\n\n"
        . "Reply *1–" . count($slice) . "*, type a name, or tap below.",
        $buttons
    );
}

function sendProjectPicker(PDO $db, APITxtService $apitxt, string $phone, array $user): void
{
    $projects = waLoadSelectableProjects($db, $user);
    $isAdmin = waIsAdminUser($user);
    waClearPickerPageIds($db, $phone);

    if (empty($projects)) {
        $apitxt->sendText(
            $phone,
            $isAdmin
                ? "No projects found yet."
                : "No projects assigned to you yet.\nAsk an admin to add you to a project."
        );
        resetSession($db, $phone);
        return;
    }

    $count = count($projects);
    $scopeLine = $isAdmin ? 'All projects' : 'Your assigned projects';

    // Few projects → direct buttons (clean).
    if ($count <= 3) {
        $buttons = [];
        foreach ($projects as $p) {
            $buttons[] = [
                'id'    => 'proj_' . $p['id'],
                'title' => mb_substr($p['name'], 0, 20),
            ];
        }
        $apitxt->sendInteractiveButtons(
            $phone,
            'Projects',
            "Hi *{$user['name']}*\n{$scopeLine} — tap one to report a bug.",
            $buttons
        );
        return;
    }

    // Medium list → short page (no 40-line dump).
    if ($count <= 8) {
        sendProjectBrowsePage($db, $apitxt, $phone, $user, 'all', 1);
        return;
    }

    // Large list → browse menu (APITxt cannot send WhatsApp native list messages).
    $apitxt->sendInteractiveButtons(
        $phone,
        'Projects',
        "Hi *{$user['name']}*\n*{$scopeLine}* ({$count})\n\n"
        . "Open a group, or type part of the project name.",
        [
            ['id' => 'wa_browse_a', 'title' => 'A – I'],
            ['id' => 'wa_browse_j', 'title' => 'J – R'],
            ['id' => 'wa_browse_s', 'title' => 'S – Z'],
        ]
    );
}

function countStagedAttachments(PDO $db, string $phone): int
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM wa_submission_attachments_temp WHERE phone=?");
    $stmt->execute([$phone]);
    return (int)$stmt->fetchColumn();
}

function cleanUpStagedAttachments(PDO $db, string $phone): void
{
    $stmt = $db->prepare("SELECT file_path FROM wa_submission_attachments_temp WHERE phone=?");
    $stmt->execute([$phone]);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $rel) {
        $abs = __DIR__ . '/../../uploads/' . $rel;
        if (file_exists($abs)) {
            @unlink($abs);
        }
    }
    $db->prepare("DELETE FROM wa_submission_attachments_temp WHERE phone=?")->execute([$phone]);
}

function columnExists(PDO $db, string $table, string $column): bool
{
    try {
        $stmt = $db->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        return $stmt && $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function generateUuid(): string
{
    $data    = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Create an in-app notification so project members see the new WhatsApp bug.
 * Mirrors the pattern used in BugController::create().
 */
function createBugNotification(PDO $db, string $bugId, string $title, string $projectId, string $reportedBy): void
{
    try {
        // Fetch members to notify (exclude the reporter)
        $stmt = $db->prepare(
            "SELECT user_id FROM project_members WHERE project_id=? AND user_id != ?"
        );
        $stmt->execute([$projectId, $reportedBy]);
        $members = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($members as $memberId) {
            $notifId = generateUuid();
            $db->prepare(
                "INSERT INTO notifications (id, user_id, type, title, message, data, is_read, created_at)
                 VALUES (?, ?, 'bug_created', ?, ?, ?, 0, NOW())"
            )->execute([
                $notifId,
                $memberId,
                'New bug via WhatsApp',
                mb_substr($title, 0, 100),
                json_encode(['bug_id' => $bugId, 'source' => 'whatsapp']),
            ]);
        }
    } catch (Throwable $e) {
        // Non-fatal — log and continue
        error_log('wa_webhook createBugNotification: ' . $e->getMessage());
    }
}
