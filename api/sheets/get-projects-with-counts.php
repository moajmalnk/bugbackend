<?php
/**
 * Get Projects with Sheet Counts Endpoint
 * GET /api/sheets/get-projects-with-counts.php
 * Returns projects with sheet counts for card display
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
    
    error_log("Getting projects with sheet counts for user: {$userId}");
    
    // Get projects with counts
    $result = $controller->getProjectsWithSheetCounts($userId);
    
    http_response_code(200);
    echo json_encode($result);
    
} catch (Exception $e) {
    error_log("Error in get-projects-with-counts.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

