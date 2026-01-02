<?php
/**
 * List Templates Endpoint
 * GET /api/sheets/list-templates
 * Returns all active sheet templates
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
    $category = $_GET['category'] ?? null;
    
    error_log("Listing templates for user: {$userId}" . ($category ? ", category: {$category}" : ""));
    
    // Get templates
    $result = $controller->listTemplates($category);
    
    http_response_code(200);
    echo json_encode($result);
    
} catch (Exception $e) {
    error_log("Error in list-templates.php: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

