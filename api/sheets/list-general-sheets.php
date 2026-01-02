<?php
/**
 * List General Sheets Endpoint
 * GET /api/sheets/list-general-sheets
 * Returns all general sheets for the authenticated user
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
    $includeArchived = isset($_GET['include_archived']) && $_GET['include_archived'] === 'true';
    $projectId = isset($_GET['project_id']) && !empty($_GET['project_id']) ? $_GET['project_id'] : null;
    
    error_log("Listing general sheets for user: {$userId}, project: " . ($projectId ?? 'all'));
    
    // Get sheets
    $result = $controller->listUserSheets($userId, $includeArchived, $projectId);
    
    http_response_code(200);
    echo json_encode($result);
    
} catch (Exception $e) {
    error_log("Error in list-general-sheets.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error_details' => $e->getTraceAsString()
    ]);
}

