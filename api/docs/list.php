<?php
/**
 * List Google Docs for a Bug
 * This endpoint returns all Google Documents linked to a specific bug
 */

require_once __DIR__ . '/GoogleDocsController.php';

header('Content-Type: application/json');

try {
    error_log("=== List Bug Documents Endpoint ===");
    
    // Initialize controller
    $docsController = new GoogleDocsController();
    
    // Validate user authentication
    $userData = $docsController->validateToken();
    
    if (!$userData || !isset($userData->user_id)) {
        throw new Exception('User not authenticated');
    }
    
    // Get bug_id from query parameter
    if (!isset($_GET['bug_id'])) {
        throw new Exception('Bug ID is required');
    }
    
    $bugId = $_GET['bug_id'];
    
    // Validate bug_id is numeric
    if (!is_numeric($bugId) || $bugId <= 0) {
        throw new Exception('Invalid bug ID');
    }
    
    error_log("Fetching documents for bug: " . $bugId);
    
    // Get documents
    $documents = $docsController->getBugDocuments($bugId);
    
    echo json_encode([
        'success' => true,
        'message' => 'Bug documents retrieved successfully',
        'data' => $documents
    ]);
    
} catch (Exception $e) {
    error_log("List documents error: " . $e->getMessage());
    
    $statusCode = 500;
    $message = $e->getMessage();
    
    if (strpos($message, 'not authenticated') !== false) {
        $statusCode = 401;
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

