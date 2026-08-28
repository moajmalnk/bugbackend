<?php
/**
 * Create General Document Endpoint
 * POST /api/docs/create-general-doc
 * Creates a general user document (not tied to a bug)
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
    
    // Validate input
    if (empty($input['doc_title'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Document title is required']);
        exit();
    }
    
    $docTitle = trim($input['doc_title']);
    $templateId = $input['template_id'] ?? null;
    $docType = $input['doc_type'] ?? 'general';
    $projectId = $input['project_id'] ?? null;
    $role = $input['role'] ?? 'all';
    $allowedUserIds = $input['allowed_user_ids'] ?? ($input['allowed_users'] ?? null);
    
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
    $validRoles = ['for_me', 'all', 'admins', 'developers', 'testers', 'creators'];
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
    
    $googleAccountUserId = $controller->resolveGoogleAccountUserId($userData);
    error_log("Creating general document: '{$docTitle}' for user: {$userId}, googleAccountUserId: {$googleAccountUserId}, project: " . ($projectId ?? 'none') . ", role: {$role}");
    
    // Create document (owned by impersonated user; Google API uses admin token while impersonating)
    $result = $controller->createGeneralDocument($userId, $docTitle, $templateId, $docType, $projectId, $role, $allowedUserIds, $googleAccountUserId);
    
    http_response_code(201);
    echo json_encode($result);
    
} catch (Exception $e) {
    error_log("Error in create-general-doc.php: " . $e->getMessage());

    $authService = new GoogleAuthService();
    $isScope = $authService->isScopeInsufficientMessage($e->getMessage());

    http_response_code($isScope ? 403 : 400);
    echo json_encode([
        'success' => false,
        'message' => $isScope
            ? 'Your Google account needs Docs permission. Disconnect and reconnect, then allow Google Docs access.'
            : $e->getMessage(),
        'error_code' => $isScope ? 'GOOGLE_SCOPE_INSUFFICIENT' : null,
    ]);
}

