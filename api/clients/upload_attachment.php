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
        $api->sendJsonResponse(403, 'Only admins can upload client attachments');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $api->sendJsonResponse(405, 'Method not allowed');
        exit;
    }

    $clientId = $_POST['client_id'] ?? null;
    if (!$clientId) {
        $api->sendJsonResponse(400, 'Missing client_id');
        exit;
    }

    $check = $api->getConnection()->prepare('SELECT id FROM clients WHERE id = ?');
    $check->execute([$clientId]);
    if (!$check->fetch()) {
        $api->sendJsonResponse(404, 'Client not found');
        exit;
    }

    if (!isset($_FILES['files'])) {
        $api->sendJsonResponse(400, 'No files uploaded');
        exit;
    }

    $uploadDir = __DIR__ . '/../../uploads/client_docs/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $files = $_FILES['files'];
    $fileCount = is_array($files['name']) ? count($files['name']) : 1;
    $saved = [];

    for ($i = 0; $i < $fileCount; $i++) {
        $fileName = is_array($files['name']) ? $files['name'][$i] : $files['name'];
        $tmpName = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
        $fileType = is_array($files['type']) ? $files['type'][$i] : $files['type'];
        $error = is_array($files['error']) ? $files['error'][$i] : $files['error'];

        if ($error !== UPLOAD_ERR_OK || empty($tmpName) || !is_uploaded_file($tmpName)) {
            continue;
        }

        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($fileName));
        $targetPath = $uploadDir . uniqid('client_') . '_' . $safeName;

        if (!move_uploaded_file($tmpName, $targetPath)) {
            continue;
        }

        $attachmentId = Utils::generateUUID();
        $relativePath = str_replace(__DIR__ . '/../../', '', $targetPath);

        $stmt = $api->getConnection()->prepare(
            "INSERT INTO client_attachments (id, client_id, file_name, file_path, file_type, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $attachmentId,
            $clientId,
            $fileName,
            $relativePath,
            $fileType,
            $decoded->user_id,
        ]);

        $saved[] = [
            'id' => $attachmentId,
            'client_id' => $clientId,
            'file_name' => $fileName,
            'file_path' => $relativePath,
            'file_type' => $fileType,
            'uploaded_by' => $decoded->user_id,
        ];
    }

    $api->sendJsonResponse(200, 'Attachments uploaded successfully', $saved);
} catch (Exception $e) {
    error_log('Error in clients/upload_attachment.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
