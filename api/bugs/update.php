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
    
    // Why: While impersonating, act as the target user (tester/developer) — no
    // elevated admin edit rights. That keeps retests/bug edits scoped to assigned projects.
    $is_impersonated = BaseAPI::isImpersonating($decoded);
    $user_role_lower = strtolower(trim((string) $userRole));
    $isAdmin = BaseAPI::hasGlobalDataScope($decoded);
    $isDeveloper = $user_role_lower === 'developer';
    $admin_id = isset($decoded->admin_id) ? $decoded->admin_id : null;
    $admin_role = isset($decoded->admin_role) ? strtolower(trim((string) $decoded->admin_role)) : null;

    error_log("BUG UPDATE - Impersonation check: is_impersonated=" . ($is_impersonated ? 'true' : 'false') . ", admin_role={$admin_role}, user_role={$user_role_lower}, isAdmin=" . ($isAdmin ? 'true' : 'false') . ", admin_id={$admin_id}");
    
    // Fetch bug to check reported_by and compare field changes
    $stmt = $controller->getConnection()->prepare("SELECT * FROM bugs WHERE id = ?");
    $stmt->execute([$bugId]);
    $bug = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$bug) {
        throw new Exception('Bug not found');
    }
    
    // Determine which fields are actually being changed (not just present)
    $updatableFields = ['title', 'description', 'priority', 'status', 'expected_result', 'actual_result', 'fix_description', 'fixed_by', 'project_id', 'already_raised', 'bug_level', 'tester_retested', 'tester_issue_fixed', 'tester_verification_notes'];
    $fieldsBeingChanged = [];
    foreach ($updatableFields as $field) {
        if (isset($data[$field]) || array_key_exists($field, $data)) {
            // isset misses null; allow nullable retest clears via array_key_exists for retest fields
            if (!array_key_exists($field, $data) && !isset($data[$field])) {
                continue;
            }
            if (!array_key_exists($field, $data)) {
                continue;
            }
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
    
    if (
        array_key_exists('bug_types', $data)
        || array_key_exists('bug_type_ids', $data)
        || array_key_exists('bug_type_ids[]', $data)
    ) {
        $fieldsBeingChanged[] = 'bug_types';
        $fieldsBeingChanged = array_values(array_unique($fieldsBeingChanged));
    }

    // Check if only status-related fields are being changed
    // Allow: status, status + fix_description, status + fixed_by, status + fix_description + fixed_by
    // Developers can update status and fix_description together (common in FixBug page)
    $allowedDeveloperFields = [
        'status',
        'fix_description',
        'fixed_by',
        'priority',
        'bug_level',
        'already_raised',
        'bug_types',
    ];
    $allowedTesterRetestFields = [
        'tester_retested',
        'tester_issue_fixed',
        'bug_level',
        'tester_verification_notes',
        'status',
        'already_raised',
    ];
    $hasStatusChange = in_array('status', $fieldsBeingChanged, true);
    
    // Check if all changed fields are in the allowed list for developers
    // array_diff returns fields in $fieldsBeingChanged that are NOT in $allowedDeveloperFields
    // If empty, it means all fields are allowed (including no-op / empty change set)
    $hasOnlyAllowedFields = empty(array_diff($fieldsBeingChanged, $allowedDeveloperFields));
    $hasOnlyTesterRetestFields = empty(array_diff($fieldsBeingChanged, $allowedTesterRetestFields));
    
    // Developer status/fix updates: do NOT require status to change (fix notes / fixed_by alone OK).
    // Attachments are allowed for fix evidence — empty($_FILES) used to incorrectly block those.
    $isStatusUpdate = $hasOnlyAllowedFields &&
                      (
                          $hasStatusChange
                          || !empty(array_intersect($fieldsBeingChanged, [
                              'fix_description',
                              'fixed_by',
                              'priority',
                              'bug_level',
                              'already_raised',
                              'bug_types',
                          ]))
                          || empty($fieldsBeingChanged)
                      ) &&
                      (!isset($data['attachments_to_delete']) || $data['attachments_to_delete'] === '' || $data['attachments_to_delete'] === '[]');

    $isTester = $user_role_lower === 'tester';
    $hasVerificationIntent = array_key_exists('tester_retested', $data)
        || array_key_exists('tester_issue_fixed', $data)
        || array_key_exists('tester_verification_notes', $data)
        || (!empty($data['verification_upload']) || ($data['upload_context'] ?? '') === 'verification');
    $currentBugStatus = (string) ($bug['status'] ?? '');
    $isRetestUpdate = $hasVerificationIntent
        && $hasOnlyTesterRetestFields
        && in_array($currentBugStatus, ['fixed', 'rejected'], true);
    
    // Check if user is admin or the bug creator (using reported_by from bug array)
    // In impersonation mode, check if the impersonated user is the creator
    $isCreator = (string)($bug['reported_by'] ?? '') === (string)$userId;
    
    // Check if developer is a member of the project
    // In impersonation mode, require project membership as the target user
    $isProjectMember = false;
    if (($isDeveloper || $isTester) && !$isAdmin && isset($bug['project_id']) && $bug['project_id']) {
        $projectMemberController = new ProjectMemberController();
        $isProjectMember = $projectMemberController->hasProjectAccess($userId, $bug['project_id']);
        
        // Debug logging for permission issues
        error_log("BUG UPDATE PERMISSION CHECK - Role user: {$userId}, Project: {$bug['project_id']}, IsMember: " . ($isProjectMember ? 'true' : 'false'));
        error_log("BUG UPDATE PERMISSION CHECK - Fields being changed: " . json_encode($fieldsBeingChanged));
        error_log("BUG UPDATE PERMISSION CHECK - IsStatusUpdate: " . ($isStatusUpdate ? 'true' : 'false') . ", IsRetestUpdate: " . ($isRetestUpdate ? 'true' : 'false'));
    }
    
    // Permission logic:
    // 1. Real admins (not impersonating) can edit everything
    // 2. Bug creators can edit everything
    // 3. Developers can edit status/fix fields if they are members of the project
    // 4. Testers can save retest verification on fixed bugs in assigned projects
    $canEdit = false;
    $errorMessage = 'You do not have permission to edit this bug.';
    
    // Debug logging before permission check
    error_log("BUG UPDATE PERMISSION - isAdmin: " . ($isAdmin ? 'true' : 'false') . ", isCreator: " . ($isCreator ? 'true' : 'false') . ", isDeveloper: " . ($isDeveloper ? 'true' : 'false') . ", isTester: " . ($isTester ? 'true' : 'false') . ", isStatusUpdate: " . ($isStatusUpdate ? 'true' : 'false') . ", isProjectMember: " . ($isProjectMember ? 'true' : 'false'));
    
    if ($isAdmin) {
        // Real admins can edit all fields
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
    } elseif ($isTester && $isRetestUpdate && $isProjectMember) {
        $canEdit = true;
        error_log("BUG UPDATE PERMISSION - Granted: Tester retest verification");
    } else {
        // For other cases, determine specific error message
        if ($isDeveloper && !$isStatusUpdate) {
            $errorMessage = 'You do not have permission to edit this bug. Developers can only update status, priority, bug level, bug type, already raised, and fix description.';
        } elseif ($isDeveloper && !$isProjectMember) {
            $errorMessage = 'You do not have permission to edit this bug. You must be a member of the project to update bug status.';
        } elseif ($isTester && !$isRetestUpdate) {
            $errorMessage = 'Testers can only update verification fields (retested / issue fixed / bug level / notes / evidence) on fixed bugs.';
        } elseif ($isTester && !$isProjectMember) {
            $errorMessage = 'You must be a project member to verify fixes.';
        } else {
            $errorMessage = 'You do not have permission to edit this bug. Only admins and the bug creator can edit bugs.';
        }
        error_log("BUG UPDATE PERMISSION - Denied: " . $errorMessage);
    }
    
    // When moving bug to another project, admin / developer / tester with access may convert
    if (
        isset($data['project_id']) &&
        $data['project_id'] !== '' &&
        (string) $data['project_id'] !== (string) ($bug['project_id'] ?? '')
    ) {
        $canConvertRole = $isAdmin || $isCreator || $isDeveloper || $isTester;
        if (!$canConvertRole) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'Only admins, developers, and testers can change the project.',
            ]);
            exit();
        }
        $projectMemberController = new ProjectMemberController();
        if (
            !$isAdmin &&
            !$projectMemberController->hasProjectAccess($userId, $bug['project_id'] ?? '')
        ) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'You do not have access to the bug\'s current project.',
            ]);
            exit();
        }
        if (!$projectMemberController->hasProjectAccess($userId, $data['project_id'])) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'You do not have access to the selected project.',
            ]);
            exit();
        }
        // Allow project-only convert for developer/tester (not only status updates)
        if (($isDeveloper || $isTester) && !$isAdmin && !$isCreator) {
            $onlyProjectChange = empty(array_diff($fieldsBeingChanged, ['project_id']));
            if ($onlyProjectChange && in_array('project_id', $fieldsBeingChanged, true)) {
                $canEdit = true;
            } elseif (in_array('project_id', $fieldsBeingChanged, true) && !$canEdit) {
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'message' => 'Project convert must be done alone. Use Convert to move the bug, then edit other fields.',
                ]);
                exit();
            }
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

    // Respond to the client FIRST so Fix Bug UI is not stuck on "Updating..."
    // while FCM / email / WhatsApp run. Then finish notifications after flush.
    ignore_user_abort(true);
    if (function_exists('session_write_close')) {
        @session_write_close();
    }

    http_response_code(200);
    $response = [
        'success' => true,
        'message' => 'Bug updated successfully',
        'data' => $result,
        '_bug_types_debug' => [
            'marker' => 'bug-types-sync-v3-20260729',
            'request_has_types' => array_key_exists('bug_types', $data)
                || array_key_exists('bug_type_ids', $data)
                || array_key_exists('bug_type_ids[]', $data),
            'post_keys' => array_keys($data),
            'bug_types_field' => $data['bug_types'] ?? null,
            'bug_type_ids_field' => $data['bug_type_ids'] ?? ($data['bug_type_ids[]'] ?? null),
            'returned_bug_types' => $result['bug_types'] ?? null,
            'returned_bug_types_count' => is_array($result['bug_types'] ?? null)
                ? count($result['bug_types'])
                : 0,
        ],
    ];
    
    // Add debug info in local development
    if (!empty($debugInfo)) {
        $response['_debug'] = $debugInfo;
    }
    
    echo json_encode($response);

    // Flush response to client before slow notification side-effects
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        if (ob_get_level()) {
            @ob_end_flush();
        }
        @flush();
    }

    if ($notificationData && ($notificationData['status'] ?? '') === 'fixed') {
        try {
            error_log("BUG UPDATE: Sending notifications for bug ID: " . $notificationData['bug_id']);
            require_once __DIR__ . '/../NotificationManager.php';
            $notificationManager = NotificationManager::getInstance();
            $notificationManager->notifyBugFixed(
                $notificationData['bug_id'],
                $notificationData['bug_title'],
                $notificationData['project_id'],
                $notificationData['updated_by'],
                $notificationData['reported_by'] ?? null,
                $notificationData['fixed_at'] ?? null,
                $notificationData['bug_level'] ?? 'normal',
                $notificationData['already_raised'] ?? 0
            );
            error_log("BUG UPDATE: Notifications sent for bug ID: " . $notificationData['bug_id']);
        } catch (Exception $e) {
            error_log("BUG UPDATE: Failed to send notifications: " . $e->getMessage());
        }
    }

    // ── WhatsApp outbound status template ────────────────────────────────────
    // Send the approved 'bug_update' template whenever a bug status changes to
    // fixed, in_progress (→ Testing), or declined/rejected (→ Closed),
    // but only when the reporter has verified their WhatsApp number.
    if ($notificationData && isset($notificationData['status'])) {
        $newStatus = $notificationData['status'];
        $triggerStatuses = ['fixed', 'in_progress', 'declined', 'rejected', 'closed', 'testing'];
        if (in_array($newStatus, $triggerStatuses, true)) {
            try {
                require_once __DIR__ . '/../../services/APITxtService.php';
                require_once __DIR__ . '/../../config/environment.php';
                $apitxt = new APITxtService();

                if ($apitxt->isConfigured()) {
                    $conn = $controller->getConnection();

                    // Load reporter's phone + WA verification flag
                    $rStmt = $conn->prepare(
                        "SELECT u.name, u.phone, u.is_wa_verified
                         FROM users u WHERE u.id = ? LIMIT 1"
                    );
                    $rStmt->execute([$notificationData['reported_by'] ?? '']);
                    $reporter = $rStmt->fetch(PDO::FETCH_ASSOC);

                    // Load project name
                    $pStmt = $conn->prepare("SELECT name FROM projects WHERE id = ? LIMIT 1");
                    $pStmt->execute([$notificationData['project_id'] ?? '']);
                    $project = $pStmt->fetch(PDO::FETCH_ASSOC);

                    if (
                        $reporter
                        && !empty($reporter['is_wa_verified'])
                        && !empty($reporter['phone'])
                    ) {
                        // Normalise phone to digits only (E.164 without '+')
                        $recipientPhone = preg_replace('/\D/', '', $reporter['phone']);

                        // Map internal status to a human-readable label
                        $statusLabels = [
                            'fixed'       => 'Fixed',
                            'in_progress' => 'In Testing',
                            'testing'     => 'In Testing',
                            'declined'    => 'Closed',
                            'rejected'    => 'Closed',
                            'closed'      => 'Closed',
                        ];
                        $statusLabel = $statusLabels[$newStatus] ?? ucfirst($newStatus);

                        $bugTitle    = $notificationData['bug_title']   ?? 'Bug';
                        $projectName = $project['name']                  ?? 'Your Project';
                        $bugIdShort  = $notificationData['bug_id']       ?? '';

                        // Template body_params: {{1}} name, {{2}} ticket id,
                        //   {{3}} project name, {{4}} issue title, {{5}} status
                        $bodyParams = [
                            $reporter['name'],
                            $bugIdShort,
                            $projectName,
                            $bugTitle,
                            $statusLabel,
                        ];

                        // URL button dynamic suffix is the bug ID
                        $urlButtons = ['url_button_0' => $bugIdShort];

                        $apitxt->sendTemplate(
                            $recipientPhone,
                            'bug_update',
                            $bodyParams,
                            $urlButtons
                        );
                        error_log("BUG UPDATE: WA bug_update template sent to {$recipientPhone} for bug {$bugIdShort}");
                    }
                }
            } catch (Throwable $e) {
                error_log("BUG UPDATE: WA template failed: " . $e->getMessage());
            }
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