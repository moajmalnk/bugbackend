<?php
/**
 * POST { user_id } — resend the welcome email (one-click sign-in link) to an existing user.
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

$conn = $api->getConnection();
$hasTesterType = false;
try {
    $col = $conn->query("SHOW COLUMNS FROM users LIKE 'tester_type'");
    $hasTesterType = $col && $col->rowCount() > 0;
} catch (Throwable $e) {
    $hasTesterType = false;
}

$stmt = $conn->prepare(
    'SELECT id, username, email, role' . ($hasTesterType ? ', tester_type' : '') . ' FROM users WHERE id = ? LIMIT 1'
);
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    $api->sendJsonResponse(404, 'User not found');
}

$email = trim((string) ($user['email'] ?? ''));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $api->sendJsonResponse(422, 'This user has no valid email address. Edit the user and add one first.');
}

$startedAt = microtime(true);
$sent = false;
$errorMessage = null;

try {
    require_once __DIR__ . '/../../utils/welcome_invite.php';
    require_once __DIR__ . '/../../utils/email.php';
    $loginLink = br_create_welcome_login_url((string) $user['id'], (string) $user['username'], (string) $user['role']);
    $sent = (bool) sendWelcomeEmail(
        $email,
        (string) $user['username'],
        null,
        (string) $user['role'],
        $loginLink,
        $user['tester_type'] ?? null
    );
} catch (Throwable $e) {
    $errorMessage = $e->getMessage();
}

if (!$sent && $errorMessage === null) {
    try {
        $err = $conn->prepare(
            "SELECT error_message FROM notification_delivery_log
             WHERE channel = 'email' AND status = 'failed' AND recipient = ?
             ORDER BY created_at DESC, id DESC LIMIT 1"
        );
        $err->execute([$email]);
        $errorMessage = $err->fetchColumn() ?: null;
    } catch (Throwable $e) {
        $errorMessage = null;
    }
}

error_log(json_encode([
    'event' => 'user.welcome.resend',
    'user_id' => (string) $user['id'],
    'actor_id' => (string) ($actor->user_id ?? ''),
    'email_sent' => $sent,
    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
]));

if ($sent) {
    $api->sendJsonResponse(200, "Welcome email sent to {$email}.", ['email_sent' => true, 'email' => $email]);
}

$api->sendJsonResponse(
    502,
    'Welcome email could not be sent' . ($errorMessage ? ': ' . $errorMessage : '. Check SMTP settings in backend .env.'),
    ['email_sent' => false, 'email' => $email]
);
