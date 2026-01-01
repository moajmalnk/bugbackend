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
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin, X-Impersonate-User, X-User-Id");
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
require_once __DIR__ . '/../projects/ProjectMemberController.php';

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
    // Validate token (this should handle query param impersonation via BaseAPI)
    $decoded = $controller->validateToken();
    
    // Debug: Log what we got from validateToken
    error_log("BUG UPDATE - Token validation result: user_id=" . ($decoded->user_id ?? 'null') . ", role=" . ($decoded->role ?? 'null') . ", admin_id=" . ($decoded->admin_id ?? 'null') . ", admin_role=" . ($decoded->admin_role ?? 'null') . ", impersonated=" . (isset($decoded->impersonated) ? ($decoded->impersonated ? 'true' : 'false') : 'null'));
    
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
    
    // Check impersonation (BaseAPI should handle query param impersonation)
    $is_impersonated = false;
    if (isset($decoded->impersonated)) {
        $is_impersonated = $decoded->impersonated === true || $decoded->impersonated === 'true' || $decoded->impersonated === 1;
    }
    if (!$is_impersonated && isset($decoded->admin_id) && !empty($decoded->admin_id)) {
        $is_impersonated = true;
    }
    
    // Also check for query param impersonation directly (in case BaseAPI didn't process it)
    $queryImpersonateId = isset($_GET['impersonate']) ? trim($_GET['impersonate']) : null;
    if ($queryImpersonateId && $queryImpersonateId === $userId) {
        // Query param matches current user_id, meaning we're impersonating
        $is_impersonated = true;
        error_log("BUG UPDATE - Query param impersonation detected: impersonating user {$userId}");
    }
    
    // Check if the actual admin (not the impersonated user) has admin role
    $admin_role = isset($decoded->admin_role) ? strtolower(trim($decoded->admin_role)) : null;
    $admin_id = isset($decoded->admin_id) ? $decoded->admin_id : null;
    $user_role_lower = strtolower(trim($userRole));
    
    // CRITICAL: If query param impersonation exists, check token owner is admin FIRST
    // This must happen before other checks to ensure admin access is granted
    if ($queryImpersonateId && !$admin_role) {
        try {
            // Get Authorization header to extract token
            $headers = null;
            if (isset($_SERVER['Authorization'])) {
                $headers = trim($_SERVER['Authorization']);
            } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
                $headers = trim($_SERVER['HTTP_AUTHORIZATION']);
            } elseif (function_exists('apache_request_headers')) {
                $requestHeaders = apache_request_headers();
                $requestHeaders = array_combine(array_map('ucwords', array_keys($requestHeaders)), array_values($requestHeaders));
                if (isset($requestHeaders['Authorization'])) {
                    $headers = trim($requestHeaders['Authorization']);
                }
            }
            
            if ($headers && preg_match('/Bearer\s(\S+)/', $headers, $matches)) {
                $token = $matches[1];
                // Utils::validateJWT is a static method
                $tempDecoded = Utils::validateJWT($token);
                if ($tempDecoded && isset($tempDecoded->role) && strtolower(trim($tempDecoded->role)) === 'admin') {
                    $admin_role = 'admin';
                    $admin_id = $tempDecoded->user_id;
                    $is_impersonated = true;
                    error_log("BUG UPDATE - Query param check: Token owner is admin. Admin ID: {$admin_id}, Impersonating: {$userId}");
                }
            }
        } catch (Exception $e) {
            error_log("BUG UPDATE - Query param admin check failed: " . $e->getMessage());
        }
    }
    
    // If impersonating and admin_role is still not set, try to get it from database
    if ($is_impersonated && !$admin_role && $admin_id) {
        try {
            $adminStmt = $controller->getConnection()->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
            $adminStmt->execute([$admin_id]);
            $adminRow = $adminStmt->fetch(PDO::FETCH_ASSOC);
            if ($adminRow && isset($adminRow['role'])) {
                $admin_role = strtolower(trim($adminRow['role']));
                error_log("BUG UPDATE - Fetched admin_role from database: {$admin_role}");
            }
        } catch (Exception $e) {
            error_log("BUG UPDATE - Failed to fetch admin role: " . $e->getMessage());
        }
    }
    
    // Final admin check
    $isAdmin = ($user_role_lower === 'admin' && !$is_impersonated) || ($is_impersonated && $admin_role === 'admin');
    
    // Final safety: if query param exists and we still don't have admin, check token one more time
    if (!$isAdmin && $queryImpersonateId) {
        try {
            $headers = null;
            if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
                $headers = trim($_SERVER['HTTP_AUTHORIZATION']);
            } elseif (function_exists('apache_request_headers')) {
                $requestHeaders = apache_request_headers();
                if (isset($requestHeaders['Authorization'])) {
                    $headers = trim($requestHeaders['Authorization']);
                }
            }
            
            if ($headers && preg_match('/Bearer\s(\S+)/', $headers, $matches)) {
                $token = $matches[1];
                $tempDecoded = Utils::validateJWT($token);
                if ($tempDecoded && isset($tempDecoded->role) && strtolower(trim($tempDecoded->role)) === 'admin') {
                    $isAdmin = true;
                    $admin_role = 'admin';
                    $admin_id = $tempDecoded->user_id;
                    $is_impersonated = true;
                    error_log("BUG UPDATE - Final safety check: Granting admin access. Admin ID: {$admin_id}, Impersonating: {$userId}");
                }
            }
        } catch (Exception $e) {
            error_log("BUG UPDATE - Final safety check failed: " . $e->getMessage());
        }
    }
    
    // Check if user is developer (the impersonated user's role)
    $isDeveloper = $user_role_lower === 'developer';
    
    // Debug logging for impersonation
    error_log("BUG UPDATE - Impersonation check: is_impersonated={$is_impersonated}, admin_role={$admin_role}, user_role={$user_role_lower}, isAdmin=" . ($isAdmin ? 'true' : 'false') . ", admin_id={$admin_id}");
    
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
    
    // Check if only status-related fields are being changed
    // Allow: status, status + fix_description, status + fixed_by, status + fix_description + fixed_by
    // Developers can update status and fix_description together (common in FixBug page)
    $allowedDeveloperFields = ['status', 'fix_description', 'fixed_by'];
    $hasStatusChange = in_array('status', $fieldsBeingChanged);
    
    // Check if all changed fields are in the allowed list for developers
    // array_diff returns fields in $fieldsBeingChanged that are NOT in $allowedDeveloperFields
    // If empty, it means all fields are allowed
    $hasOnlyAllowedFields = empty(array_diff($fieldsBeingChanged, $allowedDeveloperFields));
    
    // Check if only status-related fields are being changed
    $isStatusUpdate = $hasStatusChange && 
                      $hasOnlyAllowedFields && // All changed fields are in allowed list
                      empty($_FILES) && 
                      (!isset($data['attachments_to_delete']) || empty($data['attachments_to_delete']));
    
    // Check if user is admin or the bug creator (using reported_by from bug array)
    // In impersonation mode, check if the impersonated user is the creator
    $isCreator = $bug['reported_by'] === $userId;
    
    // Check if developer is a member of the project
    // In impersonation mode, if admin is impersonating, they should have admin privileges
    // But we still check project membership for the impersonated developer
    $isProjectMember = false;
    if ($isDeveloper && !$isAdmin && isset($bug['project_id']) && $bug['project_id']) {
        $projectMemberController = new ProjectMemberController();
        $isProjectMember = $projectMemberController->hasProjectAccess($userId, $bug['project_id']);
        
        // Debug logging for permission issues
        error_log("BUG UPDATE PERMISSION CHECK - Developer: {$userId}, Project: {$bug['project_id']}, IsMember: " . ($isProjectMember ? 'true' : 'false'));
        error_log("BUG UPDATE PERMISSION CHECK - Fields being changed: " . json_encode($fieldsBeingChanged));
        error_log("BUG UPDATE PERMISSION CHECK - IsStatusUpdate: " . ($isStatusUpdate ? 'true' : 'false'));
    } elseif ($isAdmin && $is_impersonated) {
        // Admin impersonating - they have full access, so skip project membership check
        error_log("BUG UPDATE PERMISSION CHECK - Admin impersonating, granting full access");
    }
    
    // Permission logic:
    // 1. Admins can edit everything (including when impersonating)
    // 2. Bug creators can edit everything
    // 3. Developers can edit status only if they are members of the project
    $canEdit = false;
    $errorMessage = 'You do not have permission to edit this bug.';
    
    // Debug logging before permission check
    error_log("BUG UPDATE PERMISSION - isAdmin: " . ($isAdmin ? 'true' : 'false') . ", isCreator: " . ($isCreator ? 'true' : 'false') . ", isDeveloper: " . ($isDeveloper ? 'true' : 'false') . ", isStatusUpdate: " . ($isStatusUpdate ? 'true' : 'false') . ", isProjectMember: " . ($isProjectMember ? 'true' : 'false'));
    
    if ($isAdmin) {
        // Admins can edit all fields (including when impersonating a developer)
        $canEdit = true;
        error_log("BUG UPDATE PERMISSION - Granted: Admin access");
    } elseif ($isCreator) {
        // Bug creators can edit all fields
        $canEdit = true;
        error_log("BUG UPDATE PERMISSION - Granted: Creator access");
    } elseif ($isDeveloper && $isStatusUpdate && $isProjectMember) {
        // Developers can edit status and fix_description if they are project members
        $canEdit = true;
        error_log("BUG UPDATE PERMISSION - Granted: Developer project member");
    } else {
        // For other cases, determine specific error message
        if ($isDeveloper && !$isStatusUpdate) {
            $errorMessage = 'You do not have permission to edit this bug. Developers can only update the status and fix description fields.';
        } elseif ($isDeveloper && !$isProjectMember) {
            $errorMessage = 'You do not have permission to edit this bug. You must be a member of the project to update bug status.';
        } else {
            $errorMessage = 'You do not have permission to edit this bug. Only admins and the bug creator can edit bugs.';
        }
        error_log("BUG UPDATE PERMISSION - Denied: " . $errorMessage);
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

    // Debug logging for file uploads
    $debugInfo = [];
    if (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false) {
        $debugInfo['files_received'] = [
            'screenshots' => !empty($_FILES['screenshots']) ? count($_FILES['screenshots']['name'] ?? []) : 0,
            'files' => !empty($_FILES['files']) ? count($_FILES['files']['name'] ?? []) : 0,
            'voice_notes' => !empty($_FILES['voice_notes']) ? count($_FILES['voice_notes']['name'] ?? []) : 0,
            'has_files' => $hasFiles,
            'has_deletions' => $hasAttachmentsToDelete
        ];
        error_log("update.php - Files check: " . json_encode($debugInfo['files_received']));
    }

    // If we have files or deletions, we need to handle them with updateBugWithAttachments
    if ($hasFiles || $hasAttachmentsToDelete) {
        $result = $controller->updateBugWithAttachments($data, $decoded->user_id);
        if (isset($debugInfo['files_received'])) {
            $debugInfo['method_used'] = 'updateBugWithAttachments';
        }
    } else {
        // No files, just update the bug normally
        $result = $controller->updateBug($data);
        if (isset($debugInfo['files_received'])) {
            $debugInfo['method_used'] = 'updateBug';
        }
    }

    // Prepare response data (remove notification data from response)
    $notificationData = $result['_notification_data'] ?? null;
    unset($result['_notification_data']);
    
    // Send success response immediately
    http_response_code(200);
    $response = [
        'success' => true,
        'message' => 'Bug updated successfully',
        'data' => $result
    ];
    
    // Add debug info in local development
    if (!empty($debugInfo)) {
        $response['_debug'] = $debugInfo;
    }
    
    echo json_encode($response);
    
    // Flush response to client immediately
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        // Fallback for non-FastCGI environments
        if (ob_get_level()) {
            ob_end_flush();
        }
        flush();
    }
    
    // Send notifications asynchronously after response is sent
    if ($notificationData && isset($notificationData['status']) && $notificationData['status'] === 'fixed') {
        try {
            error_log("BUG UPDATE: Sending async notifications for bug ID: " . $notificationData['bug_id']);
            require_once __DIR__ . '/../NotificationManager.php';
            $notificationManager = NotificationManager::getInstance();
            $notificationManager->notifyBugFixed(
                $notificationData['bug_id'],
                $notificationData['bug_title'],
                $notificationData['project_id'],
                $notificationData['updated_by']
            );
            error_log("BUG UPDATE: Notifications sent successfully for bug ID: " . $notificationData['bug_id']);
        } catch (Exception $e) {
            error_log("BUG UPDATE: Failed to send async notifications: " . $e->getMessage());
        }
    }

} catch (Exception $e) {
    error_log("Bug update error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to update bug: ' . $e->getMessage()
    ]);
} 