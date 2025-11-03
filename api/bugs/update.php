<?php
// Handle CORS headers first
$allowedOrigins = [
    'https://bugs.moajmalnk.in',
    'https://bugricer.com',
    'https://bugs.bugricer.com',
    'https://www.bugricer.com',
    'http://localhost:8080',
    'http://localhost:3000',
    'http://127.0.0.1:8080'
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins)) {
    header("Access-Control-Allow-Origin: $origin");
} else if (strpos($origin, 'localhost') !== false || strpos($origin, '127.0.0.1') !== false) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    header("Access-Control-Allow-Origin: https://bugs.bugricer.com");
}

header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Max-Age: 3600");
header('Content-Type: application/json');

// Disable HTML error output to prevent JSON corruption
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/BugController.php';
require_once __DIR__ . '/../../config/utils.php';

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

$controller = new BugController();

try {
    // Validate token
    $decoded = $controller->validateToken();
    
    // Use $_POST and $_FILES for multipart/form-data
    $data = $_POST;
    $files = $_FILES;

    // If $_POST is empty, try to get JSON input
    if (empty($data)) {
        $rawInput = file_get_contents('php://input');
        $jsonData = json_decode($rawInput, true);
        if ($jsonData) {
            $data = $jsonData;
        }
    }

    if (!isset($data['id'])) {
        throw new Exception('Bug ID is required');
    }

    // Check permissions: admin can edit any bug, or user can edit their own bug
    // Developers can edit status field for any bug
    $bugId = $data['id'];
    $userId = $decoded->user_id;
    $userRole = $decoded->role;
    
    // Check impersonation
    $is_impersonated = false;
    if (isset($decoded->impersonated)) {
        $is_impersonated = $decoded->impersonated === true || $decoded->impersonated === 'true' || $decoded->impersonated === 1;
    }
    if (!$is_impersonated && isset($decoded->admin_id) && !empty($decoded->admin_id)) {
        $is_impersonated = true;
    }
    
    // Check if the actual admin (not the impersonated user) has admin role
    $admin_role = isset($decoded->admin_role) ? strtolower(trim($decoded->admin_role)) : null;
    $user_role_lower = strtolower(trim($userRole));
    $isAdmin = ($user_role_lower === 'admin' && !$is_impersonated) || ($is_impersonated && $admin_role === 'admin');
    
    // Check if user is developer
    $isDeveloper = $user_role_lower === 'developer';
    
    // Fetch bug to check reported_by and compare field changes
    $stmt = $controller->getConnection()->prepare("SELECT * FROM bugs WHERE id = ?");
    $stmt->execute([$bugId]);
    $bug = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$bug) {
        throw new Exception('Bug not found');
    }
    
    // Determine which fields are actually being changed (not just present)
    $updatableFields = ['title', 'description', 'priority', 'status', 'expected_result', 'actual_result', 'fix_description', 'fixed_by'];
    $fieldsBeingChanged = [];
    foreach ($updatableFields as $field) {
        if (isset($data[$field])) {
            $oldValue = $bug[$field] ?? null;
            $newValue = $data[$field];
            
            // Normalize values for comparison (handle null, empty string, etc.)
            $oldValueNormalized = ($oldValue === null || $oldValue === '') ? null : $oldValue;
            $newValueNormalized = ($newValue === null || $newValue === '') ? null : $newValue;
            
            // Check if the value is actually changing
            if ($oldValueNormalized != $newValueNormalized) {
                $fieldsBeingChanged[] = $field;
            }
        }
    }
    
    // Check if only status is being changed (and potentially fixed_by, which is set automatically)
    // Allow status-only updates or status + fixed_by (when status becomes "fixed")
    $hasOnlyStatusChange = count($fieldsBeingChanged) === 1 && $fieldsBeingChanged[0] === 'status';
    $hasStatusAndFixedBy = count($fieldsBeingChanged) === 2 && 
                          in_array('status', $fieldsBeingChanged) && 
                          in_array('fixed_by', $fieldsBeingChanged) &&
                          isset($data['status']) && $data['status'] === 'fixed';
    
    $isOnlyStatusUpdate = ($hasOnlyStatusChange || $hasStatusAndFixedBy) &&
                          empty($_FILES) && 
                          (!isset($data['attachments_to_delete']) || empty($data['attachments_to_delete']));
    
    // Check if user is admin or the bug creator (using reported_by from bug array)
    $isCreator = $bug['reported_by'] === $userId;
    
    // Permission logic:
    // 1. Admins can edit everything
    // 2. Bug creators can edit everything
    // 3. Developers can edit status only
    $canEdit = false;
    $errorMessage = 'You do not have permission to edit this bug.';
    
    if ($isAdmin || $isCreator) {
        // Admins and creators can edit all fields
        $canEdit = true;
    } elseif ($isDeveloper && $isOnlyStatusUpdate) {
        // Developers can edit status only
        $canEdit = true;
    } else {
        // For other cases, determine specific error message
        if ($isDeveloper && !$isOnlyStatusUpdate) {
            $errorMessage = 'You do not have permission to edit this bug. Developers can only update the status field.';
        } else {
            $errorMessage = 'You do not have permission to edit this bug. Only admins and the bug creator can edit bugs.';
        }
    }
    
    if (!$canEdit) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => $errorMessage
        ]);
        exit();
    }

    // Add user ID from token as updated_by
    $data['updated_by'] = $userId;

    // Check if we have files to handle
    $hasFiles = !empty($_FILES['screenshots']) || !empty($_FILES['files']) || !empty($_FILES['voice_notes']);
    $hasAttachmentsToDelete = isset($data['attachments_to_delete']) && !empty($data['attachments_to_delete']);

    // If we have files or deletions, we need to handle them with updateBugWithAttachments
    if ($hasFiles || $hasAttachmentsToDelete) {
        $result = $controller->updateBugWithAttachments($data, $decoded->user_id);
    } else {
        // No files, just update the bug normally
        $result = $controller->updateBug($data);
    }

    // Send success response
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Bug updated successfully',
        'data' => $result
    ]);

} catch (Exception $e) {
    error_log("Bug update error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to update bug: ' . $e->getMessage()
    ]);
} 