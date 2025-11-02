<?php
require_once __DIR__ . '/ProjectActivityController.php';

$controller = new ProjectActivityController();

// Only handle GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$projectId = $_GET['project_id'] ?? null;

if (!$projectId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'project_id parameter is required']);
    exit;
}

$controller->getActivityStats($projectId);
?> 