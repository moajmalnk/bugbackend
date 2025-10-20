<?php
/**
 * Create Bug Document Endpoint
 * POST /api/docs/create-bug-doc
 * Creates a Google Doc for a specific bug with template support
 */

require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/BugDocsController.php';
require_once __DIR__ . '/../BaseAPI.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

try {
    // Validate JWT token
    $baseAPI = new BaseAPI();
    $userId = $baseAPI->getUserId();
    
    if (!$userId) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'User not authenticated']);
        exit();
    }
    
    // Get request body
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validate input
    if (empty($input['bug_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Bug ID is required']);
        exit();
    }
    
    $bugId = $input['bug_id'];
    $bugTitle = $input['bug_title'] ?? '';
    $templateName = $input['template_name'] ?? 'Bug Report Template';
    
    // Validate bug ID format (UUID or numeric)
    if (!is_numeric($bugId) && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $bugId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid bug ID format']);
        exit();
    }
    
    error_log("Creating bug document for bug: {$bugId}, user: {$userId}");
    
    // Create document
    $controller = new BugDocsController();
    $result = $controller->createBugDocument($bugId, $userId, $bugTitle, $templateName);
    
    http_response_code(201);
    echo json_encode($result);
    
} catch (Exception $e) {
    error_log("Error in create-bug-doc.php: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

