<?php
/**
 * Get Sheets by Project Endpoint
 * GET /api/sheets/get-by-project.php?project_id=xxx
 * Returns sheets for a specific project with access validation
 */

require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/BugSheetsController.php';
require_once __DIR__ . '/../BaseAPI.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

try {
    // Initialize controller
    $controller = new BugSheetsController();
    
    // Validate user authentication
    $userData = $controller->validateToken();
    
    if (!$userData || !isset($userData->user_id)) {
        throw new Exception('User not authenticated');
    }
    
    $userId = $userData->user_id;
    
    // Get query parameters
    $projectId = isset($_GET['project_id']) && !empty($_GET['project_id']) ? $_GET['project_id'] : null;
    $includeArchived = isset($_GET['include_archived']) && $_GET['include_archived'] === 'true';
    
    if (!$projectId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'project_id parameter is required']);
        exit();
    }
    
    error_log("Getting sheets for project: {$projectId}, user: {$userId}");
    
    // Get sheets
    $result = $controller->getSheetsByProject($projectId, $userId, $includeArchived);
    
    http_response_code(200);
    echo json_encode($result);
    
} catch (Exception $e) {
    error_log("Error in get-by-project.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    $statusCode = 500;
    if (strpos($e->getMessage(), 'Access denied') !== false) {
        $statusCode = 403;
    } elseif (strpos($e->getMessage(), 'required') !== false) {
        $statusCode = 400;
    }
    
    http_response_code($statusCode);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

