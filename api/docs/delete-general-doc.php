<?php
/**
 * Delete General Document Endpoint
 * DELETE /api/docs/delete-general-doc/{id}
 * Deletes a general document from both Google Drive and database
 */

require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/BugDocsController.php';
require_once __DIR__ . '/../BaseAPI.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
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
    
    // Get document ID from URL path
    // Support both /delete-general-doc/123 and /delete-general-doc?id=123
    $documentId = null;
    
    // Try to get from URL path
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $pathParts = explode('/', $path);
    $lastPart = end($pathParts);
    if (is_numeric($lastPart)) {
        $documentId = (int)$lastPart;
    }
    
    // Fallback to query parameter
    if (!$documentId && isset($_GET['id'])) {
        $documentId = (int)$_GET['id'];
    }
    
    // Fallback to request body
    if (!$documentId) {
        $input = json_decode(file_get_contents('php://input'), true);
        if (isset($input['id'])) {
            $documentId = (int)$input['id'];
        }
    }
    
    if (!$documentId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Document ID is required']);
        exit();
    }
    
    error_log("Deleting document ID: {$documentId} for user: {$userId}");
    
    // Delete document
    $controller = new BugDocsController();
    $result = $controller->deleteDocument($documentId, $userId);
    
    http_response_code(200);
    echo json_encode($result);
    
} catch (Exception $e) {
    error_log("Error in delete-general-doc.php: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

