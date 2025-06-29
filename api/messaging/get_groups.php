<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/ChatGroupController.php';
require_once __DIR__ . '/../auth/AuthController.php';
require_once __DIR__ . '/../projects/ProjectMemberController.php';

$controller = new ChatGroupController();
$auth = new AuthController();
$decoded = $auth->validateToken();
$userId = $decoded->user_id;

$projectMemberController = new ProjectMemberController();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $projectId = $_GET['project_id'] ?? null;
    
    if (!$projectId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'project_id is required']);
        exit;
    }
    
    if (!$projectMemberController->hasProjectAccess($userId, $projectId)) {
        http_response_code(403);
        echo json_encode(['error' => 'You do not have permission to view groups.']);
        exit;
    }
    
    $controller->getByProject($projectId);
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}

if ($action === 'create' || $action === 'delete') {
    if (!$user->isAdminOfProject($project_id)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
}
?> 