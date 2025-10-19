<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/ChatGroupController.php';

$controller = new ChatGroupController();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $projectId = $_GET['project_id'] ?? null;
    
    if (!$projectId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'project_id is required']);
        exit;
    }
    
    // validateToken and permission check are already handled in ChatGroupController
    $controller->getByProject($projectId);
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?> 