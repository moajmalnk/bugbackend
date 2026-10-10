<?php
/**
 * POST { user_id, channels: ["email","whatsapp"], note? } — ask an employee to
 * complete (or review and resubmit) their onboarding profile.
 *
 * Why: HR needs a one-click way to chase missing or wrong employee records.
 * The link signs the employee in and opens the wizard with saved details, so a
 * resubmission is a quick review rather than retyping everything. An in-app
 * push is always sent; email/WhatsApp run synchronously so the admin sees the
 * real delivery result.
 */
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../utils/workforce_access.php';
require_once __DIR__ . '/../../utils/user_onboarding.php';

header('Content-Type: application/json');
header('Cache-Control: private, no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$api = new BaseAPI();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $api->sendJsonResponse(405, 'Method not allowed');
}

$actor = $api->validateToken();
$actorId = (string) ($actor->user_id ?? '');
$actorRole = strtolower((string) ($actor->role ?? ''));
if ($actorRole !== 'admin' && !PermissionManager::getInstance()->hasPermissionOrAdmin(
    $actorId,
    'USERS_EDIT',
    $actor->role ?? null
)) {
    $api->sendJsonResponse(403, 'USERS_EDIT permission required');
}

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$userId = trim((string) ($data['user_id'] ?? ''));
if ($userId === '' || strlen($userId) > 64) {
    $api->sendJsonResponse(422, 'user_id is required', [
        'errors' => ['user_id' => ['A valid user id is required.']],
    ]);
}
if ($userId === $actorId) {
    $api->sendJsonResponse(422, 'You cannot send an onboarding request to yourself.');
}

$requested = $data['channels'] ?? ['email'];
if (!is_array($requested)) {
    $requested = [$requested];
}
$channels = array_values(array_intersect(['email', 'whatsapp'], array_map('strval', $requested)));
if ($channels === []) {
    $api->sendJsonResponse(422, 'Choose email, WhatsApp, or both.', [
        'errors' => ['channels' => ['Pick at least one channel.']],
    ]);
}

$note = trim(strip_tags((string) ($data['note'] ?? '')));
if ((function_exists('mb_strlen') ? mb_strlen($note) : strlen($note)) > 300) {
    $api->sendJsonResponse(422, 'Note must be 300 characters or fewer.', [
        'errors' => ['note' => ['Keep the note under 300 characters.']],
    ]);
}

$conn = $api->getConnection();
$cols = $conn->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
$select = ['id', 'username', 'email', 'phone', 'role'];
foreach (['role_id', 'account_active', 'onboarding_completed', 'onboarding_verification_status', 'onboarding_mode'] as $c) {
    if (in_array($c, $cols, true)) {
        $select[] = $c;
    }
}
$stmt = $conn->prepare('SELECT ' . implode(', ', $select) . ' FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    $api->sendJsonResponse(404, 'User not found');
}
if (isset($user['account_active']) && (int) $user['account_active'] === 0) {
    $api->sendJsonResponse(422, 'This account is deactivated. Activate it before requesting onboarding.');
}

$role = strtolower((string) ($user['role'] ?? ''));
$testerType = $role === 'tester' ? br_user_tester_type($conn, (string) $user['id']) : null;
$onboardingMode = br_user_onboarding_mode(array_merge($user, ['tester_type' => $testerType]));
// Why: creators could always be asked for records; an explicit "Off" from an admin wins.
$eligible = $onboardingMode !== BR_ONBOARDING_OFF
    || ($role === 'creator' && empty($user['onboarding_mode']));
if (!$eligible) {
    $api->sendJsonResponse(
        422,
        'Onboarding is off for this user. Set it to Required or Optional in Edit User first.'
    );
}

$email = trim((string) ($user['email'] ?? ''));
$phone = trim((string) ($user['phone'] ?? ''));
if (in_array('email', $channels, true) && ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
    $api->sendJsonResponse(422, 'This user has no valid email address. Edit the user and add one first.');
}
if (in_array('whatsapp', $channels, true) && $phone === '') {
    $api->sendJsonResponse(422, 'This user has no phone number. Edit the user and add one first.');
}

$mode = (int) ($user['onboarding_completed'] ?? 0) === 1 ? 'update' : 'complete';

$startedAt = microtime(true);
try {
    require_once __DIR__ . '/../../utils/welcome_invite.php';
    $link = br_create_welcome_login_url((string) $user['id'], (string) $user['username'], (string) $user['role'])
        . '&next=' . rawurlencode('profile?onboarding=address');
} catch (Throwable $e) {
    $api->sendJsonResponse(500, 'Could not create the sign-in link. Please try again.');
}

$requestedBy = null;
try {
    $a = $conn->prepare('SELECT username FROM users WHERE id = ? LIMIT 1');
    $a->execute([$actorId]);
    $requestedBy = $a->fetchColumn() ?: null;
} catch (Throwable $e) {
    $requestedBy = null;
}

/** Why: sendEmail/sendWhatsAppMessage return bool only; the reason lives in the delivery log. */
$lastDeliveryError = static function (PDO $conn, string $channel, string $recipient): ?string {
    try {
        $q = $conn->prepare(
            "SELECT error_message FROM notification_delivery_log
             WHERE channel = ? AND status = 'failed' AND recipient = ?
             ORDER BY created_at DESC, id DESC LIMIT 1"
        );
        $q->execute([$channel, $recipient]);
        $msg = $q->fetchColumn();
        return $msg ? (string) $msg : null;
    } catch (Throwable $e) {
        return null;
    }
};

$results = [];
if (in_array('email', $channels, true)) {
    $sent = false;
    $error = null;
    try {
        require_once __DIR__ . '/../../utils/email.php';
        $sent = (bool) sendOnboardingRequestEmail($email, (string) $user['username'], $mode, $link, $note, $requestedBy);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    if (!$sent && $error === null) {
        $error = $lastDeliveryError($conn, 'email', $email) ?? 'Check SMTP settings in backend .env.';
    }
    $results['email'] = ['sent' => $sent, 'to' => $email, 'error' => $sent ? null : $error];
}

if (in_array('whatsapp', $channels, true)) {
    $sent = false;
    $error = null;
    try {
        require_once __DIR__ . '/../../utils/whatsapp.php';
        $sent = (bool) sendOnboardingRequestWhatsApp($phone, (string) $user['username'], $mode, $link, $note);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    if (!$sent && $error === null) {
        $error = $lastDeliveryError($conn, 'whatsapp', $phone) ?? 'Check WhatsApp API settings in backend .env.';
    }
    $results['whatsapp'] = ['sent' => $sent, 'to' => $phone, 'error' => $sent ? null : $error];
}

$pushSent = false;
try {
    require_once __DIR__ . '/../NotificationManager.php';
    $pushSent = (bool) NotificationManager::getInstance()->notifyOnboardingRequested((string) $user['id'], $mode, $note);
} catch (Throwable $e) {
    error_log('onboarding request push: ' . $e->getMessage());
}

$sentChannels = array_keys(array_filter($results, static fn ($r) => $r['sent']));
$failedChannels = array_keys(array_filter($results, static fn ($r) => !$r['sent']));

error_log(json_encode([
    'event' => 'user.onboarding.request',
    'user_id' => (string) $user['id'],
    'actor_id' => $actorId,
    'mode' => $mode,
    'channels' => $channels,
    'sent' => $sentChannels,
    'failed' => $failedChannels,
    'push' => $pushSent,
    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
]));

$labels = ['email' => 'Email', 'whatsapp' => 'WhatsApp'];
$parts = [];
foreach ($results as $channel => $r) {
    $parts[] = $r['sent']
        ? "{$labels[$channel]} sent to {$r['to']}"
        : "{$labels[$channel]} failed: {$r['error']}";
}
if ($pushSent) {
    $parts[] = 'in-app notification sent';
}
$message = implode('; ', $parts) . '.';

$payload = ['results' => $results, 'push' => $pushSent, 'mode' => $mode, 'partial' => $failedChannels !== []];
if ($sentChannels === [] && !$pushSent) {
    $api->sendJsonResponse(502, $message, $payload, false);
}
$api->sendJsonResponse(200, $message, $payload);
