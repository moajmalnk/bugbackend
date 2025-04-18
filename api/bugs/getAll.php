<?php
header('Content-Type: application/json');
require_once 'BugController.php';

try {
    $controller = new BugController();
    
    // Get project_id from query params if it exists
    $projectId = isset($_GET['project_id']) ? $_GET['project_id'] : null;
    
    // Get pagination params
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    
    $controller->getAllBugs($projectId, $page, $limit);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
} 