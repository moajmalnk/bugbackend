<?php
/**
 * Create Google Doc for Bug
 * This endpoint creates a new Google Document linked to a specific bug
 */

require_once __DIR__ . '/GoogleDocsController.php';

header('Content-Type: application/json');

try {
    error_log("=== Create Bug Document Endpoint ===");
    
    // Initialize controller
    $docsController = new GoogleDocsController();
    
    // Validate user authentication
    $userData = $docsController->validateToken();
    
    if (!$userData || !isset($userData->user_id)) {
        throw new Exception('User not authenticated');
    }
    
    $userId = $userData->user_id;
    
    // Get request data
    $data = $docsController->getRequestData();
    
    if (!isset($data['bug_id'])) {
        throw new Exception('Bug ID is required');
    }
    
    $bugId = $data['bug_id'];
    
    // Validate bug_id format (UUID or numeric)
    if (empty($bugId) || (!is_numeric($bugId) && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $bugId))) {
        throw new Exception('Invalid bug ID format');
    }
    
    error_log("Creating document for bug: " . $bugId . ", user: " . $userId);
    
    // Create the Google Document
    $result = $docsController->createBugDocument($bugId, $userId);
    
    echo json_encode([
        'success' => true,
        'message' => 'Bug document created successfully',
        'data' => $result
    ]);
    
} catch (Exception $e) {
    error_log("Create document error: " . $e->getMessage());
    
    $statusCode = 500;
    $message = $e->getMessage();
    
    // Specific error handling
    if (strpos($message, 'not linked') !== false || strpos($message, 'not authenticated') !== false) {
        $statusCode = 401;
    } elseif (strpos($message, 'not found') !== false) {
        $statusCode = 404;
    } elseif (strpos($message, 'required') !== false || strpos($message, 'Invalid') !== false) {
        $statusCode = 400;
    }
    
    http_response_code($statusCode);
    echo json_encode([
        'success' => false,
        'message' => $message
    ]);
}
?>

