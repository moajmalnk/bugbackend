<?php
/**
 * Create General Document Endpoint
 * POST /api/docs/create-general-doc
 * Creates a general user document (not tied to a bug)
 */

require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/BugSheetsController.php';
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
    // Initialize controller
    $controller = new BugSheetsController();
    
    // Validate user authentication
    $userData = $controller->validateToken();
    
    if (!$userData || !isset($userData->user_id)) {
        throw new Exception('User not authenticated');
    }
    
    $userId = $userData->user_id;
    
    // Get request body
    $input = $controller->getRequestData();
    
    // Validate input
    if (empty($input['sheet_title'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Sheet title is required']);
        exit();
    }
    
    $sheetTitle = trim($input['sheet_title']);
    $templateId = $input['template_id'] ?? null;
    $sheetType = $input['sheet_type'] ?? 'general';
    $projectId = $input['project_id'] ?? null;
    $role = $input['role'] ?? 'all';
    
    // Validate template ID if provided
    if ($templateId !== null && !is_numeric($templateId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid template ID']);
        exit();
    }
    
    // Validate project ID if provided
    if ($projectId !== null && empty($projectId)) {
        $projectId = null;
    }
    
    // Validate role
    $validRoles = ['for_me', 'all', 'admins', 'developers', 'testers'];
    // Support comma-separated roles
    if (strpos($role, ',') !== false) {
        $roles = array_map('trim', explode(',', $role));
        $validRolesList = [];
        foreach ($roles as $r) {
            if (in_array($r, $validRoles)) {
                $validRolesList[] = $r;
            }
        }
        // If "for_me" is in the list, it should be exclusive (only "for_me")
        if (in_array('for_me', $validRolesList)) {
            $role = 'for_me';
        } else {
            $role = count($validRolesList) > 0 ? implode(',', $validRolesList) : 'all';
        }
    } else {
        if (!in_array($role, $validRoles)) {
            $role = 'all';
        }
    }
    
    error_log("Creating general sheet: '{$sheetTitle}' for user: {$userId}, project: " . ($projectId ?? 'none') . ", role: {$role}");
    
    // Create sheet
    $result = $controller->createGeneralSheet($userId, $sheetTitle, $templateId, $sheetType, $projectId, $role);
    
    http_response_code(201);
    echo json_encode($result);
    
} catch (Exception $e) {
    $errorMessage = $e->getMessage();
    $errorClass = get_class($e);
    $stackTrace = $e->getTraceAsString();
    
    error_log("Error in create-general-sheet.php:");
    error_log("  Message: " . $errorMessage);
    error_log("  Class: " . $errorClass);
    error_log("  File: " . $e->getFile() . ":" . $e->getLine());
    error_log("  Stack trace: " . $stackTrace);
    
    // Determine appropriate HTTP status code based on error type
    $statusCode = 500; // Default to 500 for server errors
    $errorType = 'general';
    
    // Check if it's a Google API error that needs special handling
    if (strpos($errorMessage, 'Google Sheets API is not enabled') !== false) {
        $statusCode = 503; // Service Unavailable
        $errorType = 'api_not_enabled';
    } elseif (strpos($errorMessage, 'authentication') !== false || 
              strpos($errorMessage, 'unauthorized') !== false ||
              strpos($errorMessage, 'not connected') !== false) {
        $statusCode = 401; // Unauthorized
        $errorType = 'authentication';
    } elseif (strpos($errorMessage, 'permission') !== false || 
              strpos($errorMessage, 'forbidden') !== false ||
              strpos($errorMessage, '403') !== false) {
        $statusCode = 403; // Forbidden
        $errorType = 'permission';
    } elseif (strpos($errorMessage, 'required') !== false || 
              strpos($errorMessage, 'invalid') !== false ||
              strpos($errorMessage, 'not found') !== false) {
        $statusCode = 400; // Bad Request
        $errorType = 'validation';
    }
    
    http_response_code($statusCode);
    echo json_encode([
        'success' => false,
        'message' => $errorMessage,
        'error_type' => $errorType,
        'error_class' => $errorClass
    ]);
}

