<?php
/**
 * POST { user_id, channels: ["email","whatsapp"] } — resend the welcome invite (one-click sign-in link).
 *
 * Why: When SMTP hiccups at creation time the employee is stranded with no way in.
 * This re-sends synchronously so the admin sees the real delivery result and SMTP
 * error immediately. No password is included or changed — the signed link is enough
 * to sign in, and resetting a password here could lock out someone already active.
 */
require_once __DIR__ . '/../BaseAPI.php';

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
$actorRole = strtolower((string) ($actor->role ?? ''));
if ($actorRole !== 'admin' && !PermissionManager::getInstance()->hasPermissionOrAdmin(
    (string) ($actor->user_id ?? ''),
    'USERS_CREATE',
    $actor->role ?? null
)) {
    $api->sendJsonResponse(403, 'USERS_CREATE permission required');
}

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$userId = trim((string) ($data['user_id'] ?? ''));
if ($userId === '' || strlen($userId) > 64) {
    $api->sendJsonResponse(422, 'user_id is required', [
        'errors' => ['user_id' => ['A valid user id is required.']],
    ]);
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

$conn = $api->getConnection();
$hasTesterType = false;
try {
    $col = $conn->query("SHOW COLUMNS FROM users LIKE 'tester_type'");
    $hasTesterType = $col && $col->rowCount() > 0;
} catch (Throwable $e) {
    $hasTesterType = false;
}

$stmt = $conn->prepare(
    'SELECT id, username, email, phone, role' . ($hasTesterType ? ', tester_type' : '') . ' FROM users WHERE id = ? LIMIT 1'
);
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    $api->sendJsonResponse(404, 'User not found');
}

$email = trim((string) ($user['email'] ?? ''));
$phone = trim((string) ($user['phone'] ?? ''));
if (in_array('email', $channels, true) && ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
    $api->sendJsonResponse(422, 'This user has no valid email address. Edit the user and add one first.');
}
if (in_array('whatsapp', $channels, true) && $phone === '') {
    $api->sendJsonResponse(422, 'This user has no phone number. Edit the user and add one first.');
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

$startedAt = microtime(true);
$results = [];
$loginLink = '';

try {
    require_once __DIR__ . '/../../utils/welcome_invite.php';
    $loginLink = br_create_welcome_login_url((string) $user['id'], (string) $user['username'], (string) $user['role']);
} catch (Throwable $e) {
    $api->sendJsonResponse(500, 'Could not create the sign-in link: ' . $e->getMessage());
}

if (in_array('email', $channels, true)) {
    $sent = false;
    $error = null;
    try {
        require_once __DIR__ . '/../../utils/email.php';
        $sent = (bool) sendWelcomeEmail($email, (string) $user['username'], null, (string) $user['role'], $loginLink, $user['tester_type'] ?? null);
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
        $sent = (bool) sendWelcomeWhatsApp($phone, (string) $user['username'], $loginLink, $email ?: null, null, (string) $user['role'], $user['tester_type'] ?? null);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    if (!$sent && $error === null) {
        $error = $lastDeliveryError($conn, 'whatsapp', $phone) ?? 'Check WhatsApp API settings in backend .env.';
    }
    $results['whatsapp'] = ['sent' => $sent, 'to' => $phone, 'error' => $sent ? null : $error];
}

$sentChannels = array_keys(array_filter($results, static fn ($r) => $r['sent']));
$failedChannels = array_keys(array_filter($results, static fn ($r) => !$r['sent']));

error_log(json_encode([
    'event' => 'user.welcome.resend',
    'user_id' => (string) $user['id'],
    'actor_id' => (string) ($actor->user_id ?? ''),
    'channels' => $channels,
    'sent' => $sentChannels,
    'failed' => $failedChannels,
    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
]));

$labels = ['email' => 'email', 'whatsapp' => 'WhatsApp'];
$parts = [];
foreach ($results as $channel => $r) {
    $parts[] = $r['sent']
        ? "{$labels[$channel]} sent to {$r['to']}"
        : "{$labels[$channel]} failed: {$r['error']}";
}
$message = ucfirst(implode('; ', $parts)) . '.';

if ($sentChannels === []) {
    $api->sendJsonResponse(502, $message, ['results' => $results], false);
}
$api->sendJsonResponse(200, $message, ['results' => $results, 'partial' => $failedChannels !== []]);
