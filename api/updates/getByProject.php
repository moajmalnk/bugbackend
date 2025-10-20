<?php
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/updateController.php';

// Get project ID from query parameter
$projectId = $_GET['project_id'] ?? null;

if (!$projectId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Project ID is required']);
    exit;
}

$controller = new UpdateController();
$controller->getByProject($projectId);
