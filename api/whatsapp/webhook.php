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

// ── DB + service setup ───────────────────────────────────────────────────────
$db     = Database::getInstance()->getConnection();
$apitxt = new APITxtService();

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
$mediaMime   = 'application/octet-stream';

if (isset($payload['entry'][0]['changes'][0]['value']['messages'][0])) {
    // ── Meta / nested APITxt relay format ────────────────────────────────────
    $msg     = $payload['entry'][0]['changes'][0]['value']['messages'][0];
    $fromRaw = (string) ($msg['from'] ?? '');
    $msgType = strtolower($msg['type'] ?? 'text');

    if ($msgType === 'text') {
        $msgText = trim($msg['text']['body'] ?? '');
    } elseif ($msgType === 'interactive') {
        $interactiveId = $msg['interactive']['button_reply']['id']
                      ?? $msg['interactive']['list_reply']['id']
                      ?? null;
        $msgText = trim(
            $msg['interactive']['button_reply']['title'] ??
            $msg['interactive']['list_reply']['title']   ??
            ''
        );
    } elseif (isset($msg[$msgType]) && is_array($msg[$msgType])) {
        $mediaUrl  = $msg[$msgType]['url']  ?? $msg[$msgType]['link'] ?? null;
        $mediaMime = $msg[$msgType]['mime_type'] ?? 'application/octet-stream';
        $msgText   = trim($msg[$msgType]['caption'] ?? '');
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
    } elseif ($msgType === 'interactive') {
        $interactiveId = $payload['interactive']['button_reply']['id']
                      ?? $payload['interactive']['list_reply']['id']
                      ?? $payload['button_reply']['id']
                      ?? $payload['list_reply']['id']
                      ?? null;
        $msgText = trim(
            $payload['interactive']['button_reply']['title'] ??
            $payload['interactive']['list_reply']['title']   ??
            ''
        );
    } elseif (isset($payload[$msgType]) && is_array($payload[$msgType])) {
        $mediaUrl  = $payload[$msgType]['url']  ?? $payload[$msgType]['link'] ?? null;
        $mediaMime = $payload[$msgType]['mime_type'] ?? 'application/octet-stream';
        $msgText   = trim($payload[$msgType]['caption'] ?? '');
    } else {
        // Flat media keys: payload['media']['url'] or payload['media']['mime_type']
        $mediaUrl  = $payload['media']['url']       ?? null;
        $mediaMime = $payload['media']['mime_type'] ?? 'application/octet-stream';
        $msgText   = trim($payload['message'] ?? '');
    }
}

$phone    = normaliseIncomingPhone($fromRaw);
$mediaExt = mimeToExt($mediaMime);

if ($phone === '') {
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
        $apitxt->sendText($phone, "⏰ Your session timed out due to inactivity. Let's start fresh!\n\nSend any message to begin.");
        http_response_code(200);
        echo json_encode(['ok' => true]);
        exit;
    }
}

// ── Load user by phone ────────────────────────────────────────────────────────
$user = getUserByPhone($db, $phone);

// ── STATE MACHINE ─────────────────────────────────────────────────────────────
$step = $session['current_step'];

switch ($step) {

    // ── IDLE / Entry point ───────────────────────────────────────────────────
    case STEP_IDLE:
        if ($user === null) {
            $apitxt->sendText($phone,
                "👋 Hi! I'm the *BugRicer* bot.\n\n"
                . "Your number is not registered in BugRicer. "
                . "Please contact your project admin to get access."
            );
            break;
        }

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
        if ($user === null) {
            $apitxt->sendText($phone, "❌ Account not found. Please contact your admin.");
            resetSession($db, $phone);
            break;
        }

        $otp     = $session['otp_code'];
        $expiry  = strtotime($session['otp_expires_at'] ?? '1970-01-01');
        $attempts = (int)$session['otp_attempts'];
        $windowStart = strtotime($session['otp_first_attempt_at'] ?? '1970-01-01');

        // Rate limit
        if ($attempts >= OTP_MAX_ATTEMPTS && (time() - $windowStart) < OTP_RATE_WINDOW_SECS) {
            $waitMins = ceil((OTP_RATE_WINDOW_SECS - (time() - $windowStart)) / 60);
            $apitxt->sendText($phone, "🔒 Too many attempts. Please wait {$waitMins} minute(s) and try again.");
            break;
        }

        // Reset attempt counter after rate window expires
        if ((time() - $windowStart) >= OTP_RATE_WINDOW_SECS) {
            $attempts = 0;
        }

        // Expired
        if (time() > $expiry) {
            $apitxt->sendInteractiveButtons($phone,
                'OTP Expired',
                'Your OTP has expired. Would you like a new one?',
                [['id' => 'resend_otp', 'title' => '🔄 Resend OTP']]
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
            $apitxt->sendText($phone, "❌ Incorrect OTP. You have {$remaining} attempt(s) left.");
            break;
        }

        // OTP correct — mark verified
        $db->prepare("UPDATE users SET is_wa_verified=1, wa_verified_at=NOW() WHERE id=?")
           ->execute([$user['id']]);
        $user['is_wa_verified'] = 1;

        $apitxt->sendText($phone, "✅ Phone verified! Welcome to BugRicer, *{$user['name']}*.");
        sendProjectPicker($db, $apitxt, $phone, $user);
        setStep($db, $phone, STEP_SELECT_PROJECT);
        break;

    // ── Project selection ─────────────────────────────────────────────────────
    case STEP_SELECT_PROJECT:
        if ($interactiveId === null && $msgText === '') {
            sendProjectPicker($db, $apitxt, $phone, $user);
            break;
        }

        // Expect list-reply id = "proj_<uuid>"
        $projectId = null;
        if ($interactiveId && str_starts_with($interactiveId, 'proj_')) {
            $projectId = substr($interactiveId, 5);
        }

        if ($projectId === null) {
            $apitxt->sendText($phone, "Please use the project list above to select a project. 👇");
            sendProjectPicker($db, $apitxt, $phone, $user);
            break;
        }

        // Verify user is a member
        $stmt = $db->prepare(
            "SELECT p.id, p.name FROM projects p
             JOIN project_members pm ON pm.project_id = p.id
             WHERE pm.user_id = ? AND p.id = ? LIMIT 1"
        );
        $stmt->execute([$user['id'], $projectId]);
        $project = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$project) {
            $apitxt->sendText($phone, "⚠️ You don't have access to that project. Please choose from the list:");
            sendProjectPicker($db, $apitxt, $phone, $user);
            break;
        }

        $db->prepare("UPDATE wa_sessions SET selected_project_id=? WHERE phone=?")
           ->execute([$projectId, $phone]);
        setStep($db, $phone, STEP_AWAITING_CONTENT);

        $apitxt->sendText($phone,
            "📁 Project: *{$project['name']}*\n\n"
            . "Please send your bug report now. You can:\n"
            . "• Send a *title* as your first text message\n"
            . "• Then a *description*\n"
            . "• Attach screenshots, videos, voice notes or documents\n\n"
            . "When done, type *SUBMIT* or use the Submit button."
        );
        break;

    // ── Bug content collection ────────────────────────────────────────────────
    case STEP_AWAITING_CONTENT:
        // Handle "Submit" trigger
        $isSubmitText   = strtolower($msgText) === 'submit';
        $isSubmitButton = $interactiveId === 'submit_bug';
        $isCancelText   = strtolower($msgText) === 'cancel';
        $isCancelButton = $interactiveId === 'cancel_bug';

        if ($isCancelText || $isCancelButton) {
            cleanUpStagedAttachments($db, $phone);
            resetSession($db, $phone);
            $apitxt->sendText($phone, "🗑️ Bug report cancelled. Send anything to start again.");
            break;
        }

        if ($isSubmitText || $isSubmitButton) {
            // Show confirmation
            $tempTitle = $session['temp_title'] ?? '(no title)';
            $tempDesc  = $session['temp_description'] ?? '(no description)';
            $attachCount = countStagedAttachments($db, $phone);

            $apitxt->sendInteractiveButtons($phone,
                '📋 Confirm Bug Report',
                "*Title:* {$tempTitle}\n*Description:* {$tempDesc}\n*Attachments:* {$attachCount}",
                [
                    ['id' => 'confirm_submit', 'title' => '✅ Submit'],
                    ['id' => 'cancel_bug',     'title' => '❌ Cancel'],
                ],
                'Reply Submit to file the bug, or Cancel to discard.'
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
                $apitxt->sendText($phone,
                    "✏️ Title saved!\n\n"
                    . "Now send a *description* of the bug (steps to reproduce, expected vs actual result)."
                );
            } elseif (empty($currentSession['temp_description'])) {
                $db->prepare("UPDATE wa_sessions SET temp_description=? WHERE phone=?")
                   ->execute([$msgText, $phone]);
                $apitxt->sendInteractiveButtons($phone,
                    '📎 Attachments',
                    "Description saved!\n\nYou can now send screenshots, videos, voice notes, or documents. "
                    . "When you're done, tap *Submit*.",
                    [
                        ['id' => 'submit_bug', 'title' => '✅ Submit'],
                        ['id' => 'cancel_bug', 'title' => '❌ Cancel'],
                    ]
                );
            } else {
                $apitxt->sendText($phone,
                    "Title & description already set. Send attachments now or tap *Submit*."
                );
            }
            break;
        }

        // Media attachment
        if (in_array($msgType, ['image', 'video', 'audio', 'document', 'sticker'], true) && $mediaUrl) {
            $result = $apitxt->downloadAndStoreMediaToStaging($mediaUrl, $mediaMime, $phone, $mediaExt);
            if ($result === null) {
                $apitxt->sendText($phone, "⚠️ Could not download that file. Please try again.");
                break;
            }

            // Detect duration from payload (APITxt may provide it for audio/video)
            $duration = isset($payload['media']['duration']) ? (int)$payload['media']['duration'] : null;

            $db->prepare(
                "INSERT INTO wa_submission_attachments_temp (phone, file_path, file_name, file_type, duration)
                 VALUES (?, ?, ?, ?, ?)"
            )->execute([$phone, $result['path'], $result['name'], $result['mime'], $duration]);

            $attachCount = countStagedAttachments($db, $phone);
            $apitxt->sendInteractiveButtons($phone,
                '📎 Attachment received',
                "✅ File saved ({$attachCount} attachment(s) so far). Send more or submit.",
                [
                    ['id' => 'submit_bug', 'title' => '✅ Submit'],
                    ['id' => 'cancel_bug', 'title' => '❌ Cancel'],
                ]
            );
            break;
        }

        // Anything else
        $apitxt->sendText($phone,
            "📝 Send text, images, videos, audio or documents.\nType *SUBMIT* when done, or *CANCEL* to discard."
        );
        break;

    // ── Confirm submission ────────────────────────────────────────────────────
    case STEP_CONFIRM:
        $isConfirm = $interactiveId === 'confirm_submit';
        $isCancel  = $interactiveId === 'cancel_bug' || strtolower($msgText) === 'cancel';

        if ($isCancel) {
            cleanUpStagedAttachments($db, $phone);
            resetSession($db, $phone);
            $apitxt->sendText($phone, "🗑️ Bug report discarded. Send anything to start again.");
            break;
        }

        if (!$isConfirm) {
            $apitxt->sendInteractiveButtons($phone,
                '📋 Awaiting confirmation',
                'Please tap Submit to file the bug, or Cancel to discard.',
                [
                    ['id' => 'confirm_submit', 'title' => '✅ Submit'],
                    ['id' => 'cancel_bug',     'title' => '❌ Cancel'],
                ]
            );
            break;
        }

        // ── Create the bug ────────────────────────────────────────────────────
        if ($user === null) {
            $apitxt->sendText($phone, "❌ Could not identify your account. Session reset.");
            resetSession($db, $phone);
            break;
        }

        $sess = getOrCreateSession($db, $phone);
        $projectId = $sess['selected_project_id'];

        if (!$projectId) {
            $apitxt->sendText($phone, "⚠️ No project selected. Restarting…");
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

        // Find first audio for audio_note_url
        $audioNoteUrl = null;
        foreach ($staged as $att) {
            if (str_starts_with($att['file_type'], 'audio/')) {
                $audioNoteUrl = $att['file_path'];
                break;
            }
        }

        // Build INSERT dynamically
        $cols   = ['id', 'title', 'description', 'project_id', 'reported_by', 'priority', 'status', 'created_at', 'updated_at'];
        $vals   = [$bugId, $title, $desc, $projectId, $user['id'], 'medium', 'open', $now, $now];

        if ($hasSource) {
            $cols[] = 'source';
            $vals[] = 'whatsapp';
        }
        if ($hasAudio && $audioNoteUrl !== null) {
            $cols[] = 'audio_note_url';
            $vals[] = $audioNoteUrl;
        }

        $colList  = implode(', ', $cols);
        $phList   = implode(', ', array_fill(0, count($vals), '?'));
        $db->prepare("INSERT INTO bugs ({$colList}) VALUES ({$phList})")->execute($vals);

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
        $attachText = count($staged) > 0 ? "\n📎 " . count($staged) . " attachment(s)" : '';

        $apitxt->sendText($phone,
            "🎉 Bug filed successfully!\n\n"
            . "*Ticket:* #{$bugId}\n"
            . "*Title:* {$title}"
            . $attachText . "\n\n"
            . "Track it here:\n{$bugUrl}\n\n"
            . "Send any message to report another bug."
        );
        break;

    default:
        resetSession($db, $phone);
        $apitxt->sendText($phone, "Something went wrong. Session reset — please send any message to start.");
        break;
}

// Touch session timestamp
$db->prepare("UPDATE wa_sessions SET last_interaction=NOW() WHERE phone=?")->execute([$phone]);

http_response_code(200);
echo json_encode(['ok' => true]);

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
    // Try digits-only, digits with +, and the Utils normalised form
    $variants = array_unique([
        $phone,
        '+' . $phone,
        preg_replace('/\D/', '', $phone),
    ]);
    foreach ($variants as $v) {
        $stmt = $db->prepare(
            "SELECT id, name, email, phone, is_wa_verified, wa_verified_at, role
             FROM users WHERE REPLACE(REPLACE(phone,'+',''),'-','') = ? LIMIT 1"
        );
        $stmt->execute([preg_replace('/\D/', '', $v)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
    }
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
        "👋 Hi *{$user['name']}*! Welcome to BugRicer.\n\n"
        . "Your OTP is: *{$otp}*\n\n"
        . "_(Valid for 10 minutes. Do not share this code.)_"
    );
}

function sendProjectPicker(PDO $db, APITxtService $apitxt, string $phone, array $user): void
{
    $stmt = $db->prepare(
        "SELECT p.id, p.name
         FROM projects p
         JOIN project_members pm ON pm.project_id = p.id
         WHERE pm.user_id = ?
         ORDER BY p.name ASC
         LIMIT 10"
    );
    $stmt->execute([$user['id']]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($projects)) {
        $apitxt->sendText($phone, "⚠️ You are not assigned to any projects yet. Please contact your admin.");
        resetSession($db, $phone);
        return;
    }

    $rows = array_map(fn($p) => [
        'id'          => 'proj_' . $p['id'],
        'title'       => mb_substr($p['name'], 0, 24),
        'description' => 'Select this project',
    ], $projects);

    $apitxt->sendListMenu($phone,
        '📁 Select Project',
        "Hi *{$user['name']}*, please select the project you want to report a bug for:",
        'Choose Project',
        [['title' => 'Your Projects', 'rows' => $rows]]
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
