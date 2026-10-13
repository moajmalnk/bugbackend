<?php
/**
 * Current user: whether an admin has required a Google link before the dashboard.
 */
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../utils/google_connect_gate.php';

header('Content-Type: application/json');
header('Cache-Control: private, no-cache');
header('Vary: Authorization');

try {
    $api = new BaseAPI();
    $decoded = $api->validateToken();
    if (!$decoded || empty($decoded->user_id)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $conn = $api->getConnection();
    if (!$conn) {
        throw new Exception('Database connection failed');
    }

    $status = br_google_connect_gate_status($conn, (string) $decoded->user_id);
    echo json_encode([
        'success' => true,
        'data' => $status,
    ]);
} catch (Throwable $e) {
    error_log('google_connect_gate.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
