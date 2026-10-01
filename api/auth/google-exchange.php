<?php
/**
 * Exchange a single-use Google login code (issued by google-login-redirect.php)
 * for a BugRicer JWT. Response shape matches google-login.php.
 *
 * 400 missing code, 410 unknown/expired/used code, 403 revoked account.
 */

require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/google_login_lib.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$code = is_array($data) ? ($data['code'] ?? '') : '';
if (!is_string($code) || $code === '' || strlen($code) > 128) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Login code is required']);
    exit;
}

try {
    $conn = Database::getInstance()->getConnection();
    $userId = br_google_consume_login_code($conn, $code);

    if ($userId === null) {
        http_response_code(410);
        echo json_encode([
            'success' => false,
            'message' => 'This Google sign-in link has expired. Please sign in again.',
            'error_code' => 'GOOGLE_CODE_EXPIRED',
        ]);
        exit;
    }

    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(410);
        echo json_encode(['success' => false, 'message' => 'User no longer exists', 'error_code' => 'GOOGLE_CODE_EXPIRED']);
        exit;
    }

    if (!Utils::userRowIsAllowedLogin($user)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'This account is no longer active',
            'error_code' => 'ACCOUNT_REVOKED',
        ]);
        exit;
    }

    echo json_encode(br_google_build_auth_response($conn, $user));
} catch (Throwable $e) {
    error_log(json_encode([
        'event' => 'google_login.exchange.failed',
        'error' => $e->getMessage(),
    ]));
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Google sign-in failed. Please try again.']);
}
