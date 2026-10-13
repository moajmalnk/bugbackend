<?php
/**
 * POST { log_id } — resend one failed WhatsApp delivery using the saved message.
 * Sent rows are rejected. A busy Notify campaign is not queued again.
 */
require_once __DIR__ . '/../PermissionManager.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../utils/whatsapp.php';

header('Content-Type: application/json');
header('Cache-Control: private, no-cache');
header('Vary: Authorization');

try {
    $api = new BaseAPI();
    $decoded = $api->validateToken();

    if (!$decoded || !isset($decoded->user_id)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $pm = PermissionManager::getInstance();
    if (!$pm->hasPermissionOrAdmin($decoded->user_id ?? '', 'PUSH_COVERAGE_VIEW', $decoded->role ?? null)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'PUSH_COVERAGE_VIEW permission required']);
        exit;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'POST required']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON body']);
        exit;
    }

    $logId = (int) ($input['log_id'] ?? 0);
    $conn = $api->getConnection();
    if (!$conn) {
        throw new Exception('Database connection failed');
    }

    $result = br_resend_failed_whatsapp($conn, $logId);
    http_response_code((int) $result['http']);
    echo json_encode($result['payload']);
} catch (Throwable $e) {
    error_log('resend_whatsapp.php error: ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
