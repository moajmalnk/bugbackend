<?php
/**
 * Get Shared Documents Endpoint (Developers/Testers)
 * GET /api/docs/get-shared-docs
 * Returns documents from projects the user is a member of
 */

require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/BugDocsController.php';
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
    $controller = new BugDocsController();
    
    // Validate user authentication
    $userData = $controller->validateToken();
    
    if (!$userData || !isset($userData->user_id)) {
        throw new Exception('User not authenticated');
    }
    
    $userId = $userData->user_id;
    
    // Get query parameters
    $includeArchived = isset($_GET['include_archived']) && $_GET['include_archived'] === 'true';
    
    error_log("Listing shared documents for user: {$userId}");
    
    // Get documents
    $result = $controller->listSharedDocuments($userId, $includeArchived);
    
    http_response_code(200);
    echo json_encode($result);
    
} catch (Exception $e) {
    error_log("Error in get-shared-docs.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

