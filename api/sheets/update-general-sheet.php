<?php
/**
 * Update General Sheet Endpoint
 * PUT/PATCH /api/sheets/update-general-sheet/{id}
 * Updates a general sheet title
 */

require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/BugSheetsController.php';
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
    $controller = new BugSheetsController();
    
    // Validate user authentication
    $userData = $controller->validateToken();
    
    if (!$userData || !isset($userData->user_id)) {
        throw new Exception('User not authenticated');
    }
    
    $userId = $userData->user_id;
    
    // Get request body
    $input = $controller->getRequestData();
    
    // Get sheet ID
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
    if (!$sheetId && isset($input['id'])) {
        $sheetId = (int)$input['id'];
    }
    
    if (!$sheetId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Sheet ID is required']);
        exit();
    }
    
    // Validate input
    if (empty($input['sheet_title'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Sheet title is required']);
        exit();
    }
    
    $sheetTitle = trim($input['sheet_title']);
    
    if (strlen($sheetTitle) === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Sheet title cannot be empty']);
        exit();
    }
    
    // Get optional fields
    $projectId = isset($input['project_id']) ? ($input['project_id'] === 'none' || $input['project_id'] === '' ? null : $input['project_id']) : null;
    $templateId = isset($input['template_id']) ? ($input['template_id'] === '0' || $input['template_id'] === '' || $input['template_id'] === 0 ? null : (int)$input['template_id']) : null;
    $role = isset($input['role']) ? $input['role'] : 'all';
    
    // Validate role (support comma-separated roles for multi-select)
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
    
    error_log("Updating sheet ID: {$sheetId} for user: {$userId}, new title: {$sheetTitle}, project: " . ($projectId ?? 'none') . ", template: " . ($templateId ?? 'none') . ", role: {$role}");
    
    // Check if user is admin
    $isAdmin = isset($userData->role) && $userData->role === 'admin';
    
    // Update sheet (allow admin to edit any sheet)
    $result = $controller->updateSheet($sheetId, $userId, $sheetTitle, $isAdmin, $projectId, $templateId, $role);
    
    http_response_code(200);
    echo json_encode($result);
    
} catch (Exception $e) {
    error_log("Error in update-general-sheet.php: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

