<?php
/**
 * Delete General Sheet Endpoint
 * DELETE /api/sheets/delete-general-sheet/{id}
 * Deletes a general sheet from both Google Drive and database
 */

require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/BugSheetsController.php';
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
    // Initialize controller
    $controller = new BugSheetsController();
    
    // Validate user authentication
    $userData = $controller->validateToken();
    
    if (!$userData || !isset($userData->user_id)) {
        throw new Exception('User not authenticated');
    }
    
    $userId = $userData->user_id;
    
    // Get sheet ID from URL path
    // Support both /delete-general-sheet/123 and /delete-general-sheet?id=123
    $sheetId = null;
    
    // Try to get from URL path
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $pathParts = explode('/', $path);
    $lastPart = end($pathParts);
    if (is_numeric($lastPart)) {
        $sheetId = (int)$lastPart;
    }
    
    // Fallback to query parameter
    if (!$sheetId && isset($_GET['id'])) {
        $sheetId = (int)$_GET['id'];
    }
    
    // Fallback to request body
    if (!$sheetId) {
        $input = $controller->getRequestData();
        if (isset($input['id'])) {
            $sheetId = (int)$input['id'];
        }
    }
    
    if (!$sheetId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Sheet ID is required']);
        exit();
    }
    
    error_log("Deleting sheet ID: {$sheetId} for user: {$userId}");
    
    // Delete sheet
    $result = $controller->deleteSheet($sheetId, $userId);
    
    http_response_code(200);
    echo json_encode($result);
    
} catch (Exception $e) {
    error_log("Error in delete-general-sheet.php: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

