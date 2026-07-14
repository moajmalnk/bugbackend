<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../BaseAPI.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    $api = new BaseAPI();
    $api->validateToken();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        $api->sendJsonResponse(405, 'Method not allowed');
        exit;
    }

    $clientId = $_GET['client_id'] ?? null;
    if (!$clientId) {
        $api->sendJsonResponse(400, 'Missing client_id');
        exit;
    }

    $stmt = $api->getConnection()->prepare(
        "SELECT id, client_id, file_name, file_path, file_type, uploaded_by, created_at
         FROM client_attachments WHERE client_id = ? ORDER BY created_at DESC"
    );
    $stmt->execute([$clientId]);
    $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $api->sendJsonResponse(200, 'Attachments retrieved successfully', $attachments);
} catch (Exception $e) {
    error_log('Error in clients/get_attachments.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
