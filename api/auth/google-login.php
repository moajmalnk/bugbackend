<?php
/**
 * Google Sign-In Authentication Endpoint (JSON / popup clients)
 * Handles secure verification of Google ID tokens and user provisioning.
 * The same-tab flow uses google-login-redirect.php + google-exchange.php.
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

try {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!$data) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
        exit;
    }

    if (empty($data['id_token']) || !is_string($data['id_token'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID token is required']);
        exit;
    }

    $payload = br_google_verify_id_token($data['id_token']);

    $conn = Database::getInstance()->getConnection();
    $user = br_google_resolve_user($conn, $payload);

    echo json_encode(br_google_build_auth_response($conn, $user));
} catch (GoogleLoginException $e) {
    http_response_code($e->status);
    $body = ['success' => false, 'message' => $e->getMessage()];
    if ($e->reason === 'account_revoked') {
        $body['error_code'] = 'ACCOUNT_REVOKED';
    }
    echo json_encode($body);
} catch (Exception $e) {
    error_log("Google authentication error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Authentication failed: ' . $e->getMessage()
    ]);
}
