<?php
/**
 * Update General Document Endpoint
 * PUT/PATCH /api/docs/update-general-doc/{id}
 * Updates a general document title
 */

require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/BugDocsController.php';
require_once __DIR__ . '/../BaseAPI.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if (!in_array($_SERVER['REQUEST_METHOD'], ['PUT', 'PATCH', 'POST'])) {
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
    
    // Get request body
    $input = $controller->getRequestData();
    
    // Get document ID
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
    if (!$documentId && isset($input['id'])) {
        $documentId = (int)$input['id'];
    }
    
    if (!$documentId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Document ID is required']);
        exit();
    }
    
    // Validate input
    if (empty($input['doc_title'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Document title is required']);
        exit();
    }
    
    $docTitle = trim($input['doc_title']);
    
    if (strlen($docTitle) === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Document title cannot be empty']);
        exit();
    }
    
    // Get optional fields
    $projectId = isset($input['project_id']) ? ($input['project_id'] === 'none' || $input['project_id'] === '' ? null : $input['project_id']) : null;
    $templateId = isset($input['template_id']) ? ($input['template_id'] === '0' || $input['template_id'] === '' || $input['template_id'] === 0 ? null : (int)$input['template_id']) : null;
    
    error_log("Updating document ID: {$documentId} for user: {$userId}, new title: {$docTitle}, project: " . ($projectId ?? 'none') . ", template: " . ($templateId ?? 'none'));
    
    // Check if user is admin
    $isAdmin = isset($userData->role) && $userData->role === 'admin';
    
    // Update document (allow admin to edit any document)
    $result = $controller->updateDocument($documentId, $userId, $docTitle, $isAdmin, $projectId, $templateId);
    
    http_response_code(200);
    echo json_encode($result);
    
} catch (Exception $e) {
    error_log("Error in update-general-doc.php: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

