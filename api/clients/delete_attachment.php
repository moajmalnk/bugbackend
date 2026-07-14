<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../BaseAPI.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    $api = new BaseAPI();
    $decoded = $api->validateToken();

    if ($decoded->role !== 'admin') {
        $api->sendJsonResponse(403, 'Only admins can delete client attachments');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        $api->sendJsonResponse(405, 'Method not allowed');
        exit;
    }

    $attachmentId = $_GET['id'] ?? null;
    if (!$attachmentId) {
        $api->sendJsonResponse(400, 'Missing attachment id');
        exit;
    }

    $conn = $api->getConnection();
    $stmt = $conn->prepare(
        'SELECT id, file_path FROM client_attachments WHERE id = ?'
    );
    $stmt->execute([$attachmentId]);
    $attachment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$attachment) {
        $api->sendJsonResponse(404, 'Attachment not found');
        exit;
    }

    $fullPath = realpath(__DIR__ . '/../../' . ltrim($attachment['file_path'], '/'));
    if ($fullPath && file_exists($fullPath)) {
        @unlink($fullPath);
    }

    $delete = $conn->prepare('DELETE FROM client_attachments WHERE id = ?');
    $delete->execute([$attachmentId]);

    $api->sendJsonResponse(200, 'Attachment deleted successfully');
} catch (Exception $e) {
    error_log('Error in clients/delete_attachment.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
