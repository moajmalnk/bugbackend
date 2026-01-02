<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../oauth/GoogleAuthService.php';
require_once __DIR__ . '/../projects/ProjectMemberController.php';

class BugSheetsController extends BaseAPI {
    private $authService;
    
    public function __construct() {
        parent::__construct();
        $this->authService = new GoogleAuthService();
    }
    
    /**
     * Create a bug-specific sheet from template
     * 
     * @param string $bugId Bug ID
     * @param string $userId User ID
     * @param string $bugTitle Bug title
     * @param string $templateName Template name (optional, defaults to 'Bug Report Template')
     * @return array Sheet details with URL
     */
    public function createBugSheet($bugId, $userId, $bugTitle, $templateName = 'Bug Report Template') {
        try {
            // Get authenticated client
            $client = $this->authService->getClientForUser($userId);
            $sheetsService = new Google\Service\Sheets($client);
            $driveService = new Google\Service\Drive($client);
            
            // Get bug details
            $bugDetails = $this->getBugDetails($bugId);
            if (!$bugDetails) {
                throw new Exception('Bug not found');
            }
            
            // Get template if specified
            $template = $this->getTemplate($templateName);
            
            if ($template) {
                // Create from template
                $templateSheetId = $template['google_sheet_id'] ?? $template['google_doc_id'] ?? null;
                if (!$templateSheetId) {
                    throw new Exception('Template does not have a valid Google Sheet ID');
                }
                
                $result = $this->createFromTemplate(
                    $driveService,
                    $sheetsService,
                    $templateSheetId,
                    "Bug - {$bugDetails['title']} - {$bugId}",
                    $this->getBugPlaceholders($bugDetails)
                );
                $sheetId = $result['sheetId'];
                $sheetUrl = $result['sheetUrl'];
                $templateId = $template['id'];
            } else {
                // Create blank sheet with content
                $sheetName = "Bug - {$bugDetails['title']} - {$bugId}";
                $spreadsheet = new Google\Service\Sheets\Spreadsheet(['properties' => ['title' => $sheetName]]);
                $sheet = $sheetsService->spreadsheets->create($spreadsheet);
                $sheetId = $sheet->getSpreadsheetId();
                $sheetUrl = "https://docs.google.com/spreadsheets/d/{$sheetId}/edit";
                $templateId = null;
                
                // Set default sharing permissions (Anyone with link - Editor)
                $this->setDefaultSharingPermissions($driveService, $sheetId);
                
                // Add initial content
                $this->addBugContent($sheetsService, $sheetId, $bugDetails);
            }
            
            // Save to bug_sheets table
            $stmt = $this->conn->prepare(
                "INSERT INTO bug_sheets 
                (bug_id, google_sheet_id, google_sheet_url, sheet_name, created_by, template_id) 
                VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $bugId,
                $sheetId,
                $sheetUrl,
                "Bug - {$bugDetails['title']} - {$bugId}",
                $userId,
                $templateId
            ]);
            
            error_log("Bug sheet created: {$sheetId}");
            
            // Send notifications to project members
            try {
                require_once __DIR__ . '/../NotificationManager.php';
                $notificationManager = NotificationManager::getInstance();
                $notificationManager->notifySheetCreated(
                    $sheetId,
                    "Bug - {$bugDetails['title']} - {$bugId}",
                    $bugDetails['project_id'] ?? null,
                    $userId
                );
            } catch (Exception $e) {
                error_log("Failed to send sheet creation notification: " . $e->getMessage());
            }
            
            return [
                'success' => true,
                'sheet_id' => $sheetId,
                'sheet_url' => $sheetUrl,
                'sheet_name' => "Bug - {$bugDetails['title']} - {$bugId}"
            ];
            
        } catch (Exception $e) {
            error_log("Error creating bug sheet: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get user role from database
     * 
     * @param string $userId User ID
     * @return string User role (admin, developer, tester, user)
     */
    private function getUserRole($userId) {
        try {
            $stmt = $this->conn->prepare("SELECT role FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            return $user ? strtolower($user['role']) : 'user';
        } catch (Exception $e) {
            error_log("Error getting user role: " . $e->getMessage());
            return 'user';
        }
    }
    
    /**
     * Build SQL filter for role-based access
     * 
     * @param string $userRole User's role
     * @param string $tableAlias Table alias for user_sheets (default: 's')
     * @return string SQL WHERE clause for role filtering
     */
    private function getRoleFilterSQL($userRole, $tableAlias = 's', $userId = null) {
        $filter = '';
        
        // Map user roles to their database values
        $roleMap = [
            'admin' => 'admins',
            'developer' => 'developers',
            'tester' => 'testers'
        ];
        $dbRole = isset($roleMap[$userRole]) ? $roleMap[$userRole] : $userRole;
        
        // Build base filter - exclude "for_me" items unless user is the creator
        // "for_me" items should only be visible to their creator
        $excludeForMe = '';
        if ($userId) {
            // Allow "for_me" items if user is the creator
            $excludeForMe = " AND ({$tableAlias}.role != 'for_me' OR {$tableAlias}.creator_user_id = '{$userId}')";
        } else {
            // If no userId provided, exclude all "for_me" items
            $excludeForMe = " AND {$tableAlias}.role != 'for_me'";
        }
        
        // Admins can see all documents (except "for_me" unless they're the creator)
        if ($userRole === 'admin') {
            // Admins can see: all, admins, or any document containing 'admins' in comma-separated list
            // Use FIND_IN_SET for precise comma-separated matching, or LIKE as fallback
            $filter = "({$tableAlias}.role IS NULL OR {$tableAlias}.role = 'all' OR {$tableAlias}.role = 'admins' OR FIND_IN_SET('admins', {$tableAlias}.role) > 0 OR {$tableAlias}.role LIKE 'admins,%' OR {$tableAlias}.role LIKE '%,admins' OR {$tableAlias}.role LIKE '%,admins,%'){$excludeForMe}";
        }
        // Developers can see: all, developers, or documents containing 'developers' in comma-separated list
        else if ($userRole === 'developer') {
            $filter = "({$tableAlias}.role IS NULL OR {$tableAlias}.role = 'all' OR {$tableAlias}.role = 'developers' OR FIND_IN_SET('developers', {$tableAlias}.role) > 0 OR {$tableAlias}.role LIKE 'developers,%' OR {$tableAlias}.role LIKE '%,developers' OR {$tableAlias}.role LIKE '%,developers,%'){$excludeForMe}";
        }
        // Testers can see: all, testers, or documents containing 'testers' in comma-separated list
        else if ($userRole === 'tester') {
            $filter = "({$tableAlias}.role IS NULL OR {$tableAlias}.role = 'all' OR {$tableAlias}.role = 'testers' OR FIND_IN_SET('testers', {$tableAlias}.role) > 0 OR {$tableAlias}.role LIKE 'testers,%' OR {$tableAlias}.role LIKE '%,testers' OR {$tableAlias}.role LIKE '%,testers,%'){$excludeForMe}";
        }
        // Regular users can only see 'all'
        else {
            $filter = "({$tableAlias}.role IS NULL OR {$tableAlias}.role = 'all'){$excludeForMe}";
        }
        
        error_log("🔐 Role Filter - User Role: {$userRole}, UserId: {$userId}, Filter: {$filter}");
        return $filter;
    }
    
    /**
     * Create a general user sheet
     * 
     * @param string $userId User ID
     * @param string $sheetTitle Sheet title
     * @param int|null $templateId Template ID (optional)
     * @param string $sheetType Sheet type (default: 'general')
     * @param string|null $projectId Project ID (optional)
     * @param string $role Role access (default: 'all')
     * @return array Sheet details with URL
     */
    public function createGeneralSheet($userId, $sheetTitle, $templateId = null, $sheetType = 'general', $projectId = null, $role = 'all') {
        try {
            // Get authenticated client
            $client = $this->authService->getClientForUser($userId);
            $sheetsService = new Google\Service\Sheets($client);
            $driveService = new Google\Service\Drive($client);
            
            $docId = null;
            $docUrl = null;
            
            if ($templateId) {
                // Get template details
                $template = $this->getTemplateById($templateId);
                if (!$template) {
                    throw new Exception('Template not found');
                }
                
                // Create from template
                $templateSheetId = $template['google_sheet_id'] ?? $template['google_doc_id'] ?? null;
                if (!$templateSheetId) {
                    throw new Exception('Template does not have a valid Google Sheet ID');
                }
                
                try {
                    $result = $this->createFromTemplate(
                        $driveService,
                        $sheetsService,
                        $templateSheetId,
                        $sheetTitle,
                        $this->getGeneralPlaceholders($userId, $sheetTitle)
                    );
                    $sheetId = $result['sheetId'];
                    $sheetUrl = $result['sheetUrl'];
                } catch (Exception $e) {
                    // Check if it's a Google API error
                    if ($this->isGoogleApiException($e)) {
                        $errorMessage = $this->formatGoogleApiError($e);
                        error_log("Google API error creating sheet from template: " . $errorMessage);
                        throw new Exception($errorMessage);
                    }
                    throw $e;
                }
            } else {
                // Create blank sheet
                try {
                    $spreadsheet = new Google\Service\Sheets\Spreadsheet(['properties' => ['title' => $sheetTitle]]);
                    $sheet = $sheetsService->spreadsheets->create($spreadsheet);
                    $sheetId = $sheet->getSpreadsheetId();
                    $sheetUrl = "https://docs.google.com/spreadsheets/d/{$sheetId}/edit";
                    
                    // Set default sharing permissions (Anyone with link - Editor)
                    $this->setDefaultSharingPermissions($driveService, $sheetId);
                } catch (Exception $e) {
                    // Check if it's a Google API error
                    if ($this->isGoogleApiException($e)) {
                        $errorMessage = $this->formatGoogleApiError($e);
                        error_log("Google API error creating blank sheet: " . $errorMessage);
                        throw new Exception($errorMessage);
                    }
                    throw $e;
                }
            }
            
            // Check if project_id column exists, if not, try to add it (graceful fallback)
            // If it exists, check if it needs to be resized for comma-separated values
            try {
                $checkColumn = $this->conn->query("SHOW COLUMNS FROM user_sheets LIKE 'project_id'");
                if ($checkColumn->rowCount() == 0) {
                    $this->conn->exec("ALTER TABLE user_sheets ADD COLUMN project_id VARCHAR(500) DEFAULT NULL COMMENT 'Reference to projects.id (comma-separated for multiple projects)'");
                } else {
                    // Check if column needs to be resized (if it's VARCHAR(36), expand it)
                    $columnInfo = $this->conn->query("SHOW COLUMNS FROM user_sheets WHERE Field = 'project_id'")->fetch(PDO::FETCH_ASSOC);
                    if ($columnInfo && isset($columnInfo['Type']) && strpos($columnInfo['Type'], 'varchar(36)') !== false) {
                        try {
                            $this->conn->exec("ALTER TABLE user_sheets MODIFY COLUMN project_id VARCHAR(500) DEFAULT NULL COMMENT 'Reference to projects.id (comma-separated for multiple projects)'");
                            error_log("✅ Expanded project_id column from VARCHAR(36) to VARCHAR(500) for multi-select support");
                        } catch (Exception $e) {
                            error_log("Note: project_id column resize failed (may not be necessary): " . $e->getMessage());
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("Note: project_id column check/add failed (may already exist): " . $e->getMessage());
            }
            
            // Check if role column exists, if not, try to add it
            // If it exists, check if it needs to be resized for comma-separated values
            try {
                $checkRoleColumn = $this->conn->query("SHOW COLUMNS FROM user_sheets LIKE 'role'");
                if ($checkRoleColumn->rowCount() == 0) {
                    $this->conn->exec("ALTER TABLE user_sheets ADD COLUMN role VARCHAR(100) DEFAULT 'all' COMMENT 'Role access: all, admins, developers, testers (comma-separated for multiple)'");
                } else {
                    // Check if column needs to be resized (if it's VARCHAR(20), expand it)
                    $columnInfo = $this->conn->query("SHOW COLUMNS FROM user_sheets WHERE Field = 'role'")->fetch(PDO::FETCH_ASSOC);
                    if ($columnInfo && isset($columnInfo['Type']) && strpos($columnInfo['Type'], 'varchar(20)') !== false) {
                        try {
                            $this->conn->exec("ALTER TABLE user_sheets MODIFY COLUMN role VARCHAR(100) DEFAULT 'all' COMMENT 'Role access: all, admins, developers, testers (comma-separated for multiple)'");
                            error_log("✅ Expanded role column from VARCHAR(20) to VARCHAR(100) for multi-select support");
                        } catch (Exception $e) {
                            error_log("Note: role column resize failed (may not be necessary): " . $e->getMessage());
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("Note: role column check/add failed (may already exist): " . $e->getMessage());
            }
            
            // Save to user_sheets table
            $stmt = $this->conn->prepare(
                "INSERT INTO user_sheets 
                (sheet_title, google_sheet_id, google_sheet_url, creator_user_id, template_id, sheet_type, project_id, role) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$sheetTitle, $sheetId, $sheetUrl, $userId, $templateId, $sheetType, $projectId, $role]);
            $insertId = $this->conn->lastInsertId();
            
            error_log("General sheet created: {$sheetId} for user: {$userId} with role: {$role}");
            
            // Send notifications to project members
            try {
                require_once __DIR__ . '/../NotificationManager.php';
                $notificationManager = NotificationManager::getInstance();
                $notificationManager->notifySheetCreated(
                    $sheetId,
                    $sheetTitle,
                    $projectId,
                    $userId
                );
            } catch (Exception $e) {
                error_log("Failed to send sheet creation notification: " . $e->getMessage());
            }
            
            return [
                'success' => true,
                'id' => $insertId,
                'sheet_id' => $sheetId,
                'sheet_url' => $sheetUrl,
                'sheet_title' => $sheetTitle
            ];
            
        } catch (Exception $e) {
            error_log("Error creating general sheet: " . $e->getMessage());
            error_log("Exception class: " . get_class($e));
            error_log("Stack trace: " . $e->getTraceAsString());
            
            // Check if it's a Google API error
            if ($this->isGoogleApiException($e)) {
                $errorMessage = $this->formatGoogleApiError($e);
                error_log("Formatted Google API error: " . $errorMessage);
                throw new Exception($errorMessage);
            }
            
            throw $e;
        }
    }
    
    /**
     * List all general sheets for a user
     * 
     * @param string $userId User ID
     * @param bool $includeArchived Include archived sheets (default: false)
     * @param string|null $projectId Filter by project ID (optional)
     * @return array List of sheets
     */
    public function listUserSheets($userId, $includeArchived = false, $projectId = null) {
        try {
            // Get user role for filtering
            $userRole = $this->getUserRole($userId);
            
            // Check if project_id column exists by trying to select it
            $hasProjectColumn = false;
            try {
                $testStmt = $this->conn->query("SHOW COLUMNS FROM user_sheets WHERE Field = 'project_id'");
                $hasProjectColumn = $testStmt->rowCount() > 0;
            } catch (Exception $e) {
                error_log("Note: Could not check for project_id column: " . $e->getMessage());
                // Try alternative method - just try to select the column
                try {
                    $testStmt = $this->conn->query("SELECT project_id FROM user_sheets LIMIT 1");
                    $hasProjectColumn = true;
                } catch (Exception $e2) {
                    error_log("Note: project_id column does not exist: " . $e2->getMessage());
                    $hasProjectColumn = false;
                }
            }
            
            // Check if role column exists
            $hasRoleColumn = false;
            try {
                $testStmt = $this->conn->query("SHOW COLUMNS FROM user_sheets WHERE Field = 'role'");
                $hasRoleColumn = $testStmt->rowCount() > 0;
            } catch (Exception $e) {
                try {
                    $testStmt = $this->conn->query("SELECT role FROM user_sheets LIMIT 1");
                    $hasRoleColumn = true;
                } catch (Exception $e2) {
                    $hasRoleColumn = false;
                }
            }
            
            $sql = "SELECT 
                        s.id,
                        s.sheet_title,
                        s.google_sheet_id,
                        s.google_sheet_url,
                        s.sheet_type,
                        s.is_archived,
                        s.created_at,
                        s.updated_at,
                        s.last_accessed_at,
                        t.template_name";
            
            if ($hasProjectColumn) {
                $sql .= ", s.project_id, COALESCE(p.name, '') as project_name";
            }
            
            if ($hasRoleColumn) {
                $sql .= ", s.role";
            }
            
            // Try sheet_templates first, fallback to doc_templates
            $templateTable = 'sheet_templates';
            try {
                $testStmt = $this->conn->query("SHOW TABLES LIKE 'sheet_templates'");
                if ($testStmt->rowCount() === 0) {
                    $templateTable = 'doc_templates';
                }
            } catch (Exception $e) {
                $templateTable = 'doc_templates';
            }
            
            $sql .= " FROM user_sheets s
                    LEFT JOIN {$templateTable} t ON s.template_id = t.id";
            
            if ($hasProjectColumn) {
                $sql .= " LEFT JOIN projects p ON s.project_id COLLATE utf8mb4_unicode_ci = p.id COLLATE utf8mb4_unicode_ci";
            }
            
            $sql .= " WHERE CONVERT(s.creator_user_id, CHAR) COLLATE utf8mb4_unicode_ci = ?";
            
            $params = [$userId];
            
            if (!$includeArchived) {
                $sql .= " AND s.is_archived = 0";
            }
            
            if ($projectId !== null && $hasProjectColumn) {
                // Support comma-separated project IDs: check if the project ID is in the list
                $sql .= " AND (s.project_id = ? OR s.project_id LIKE ? OR s.project_id LIKE ? OR s.project_id LIKE ?)";
                $params[] = $projectId; // Exact match
                $params[] = $projectId . ',%'; // At start of list
                $params[] = '%,' . $projectId; // At end of list
                $params[] = '%,' . $projectId . ',%'; // In middle of list
            }
            
            // Add role-based filtering
            if ($hasRoleColumn) {
                $sql .= " AND " . $this->getRoleFilterSQL($userRole, 's', $userId);
            }
            
            $sql .= " ORDER BY s.created_at DESC";
            
            error_log("Executing SQL: " . $sql);
            error_log("Params: " . print_r($params, true));
            error_log("User role: " . $userRole);
            
            try {
                $stmt = $this->conn->prepare($sql);
                $stmt->execute($params);
                $sheets = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $sqlError) {
                error_log("SQL execution failed: " . $sqlError->getMessage());
                // If query failed and we tried to use project_id, retry without it
                if ($hasProjectColumn) {
                    error_log("Retrying query without project columns...");
                    $sql = "SELECT 
                                s.id,
                                s.sheet_title,
                                s.google_sheet_id,
                                s.google_sheet_url,
                                s.sheet_type,
                                s.is_archived,
                                s.created_at,
                                s.updated_at,
                                s.last_accessed_at,
                                t.template_name";
                    
                    if ($hasRoleColumn) {
                        $sql .= ", s.role";
                    }
                    
                    // Try sheet_templates first, fallback to doc_templates
                    $templateTable = 'sheet_templates';
                    try {
                        $testStmt = $this->conn->query("SHOW TABLES LIKE 'sheet_templates'");
                        if ($testStmt->rowCount() === 0) {
                            $templateTable = 'doc_templates';
                        }
                    } catch (Exception $e) {
                        $templateTable = 'doc_templates';
                    }
                    
                    $sql .= " FROM user_sheets s
                            LEFT JOIN {$templateTable} t ON s.template_id = t.id
                            WHERE s.creator_user_id = ?";
                    
                    $params = [$userId];
            
            if (!$includeArchived) {
                $sql .= " AND s.is_archived = 0";
            }
            
            // Add role-based filtering
                if ($hasRoleColumn) {
                    $sql .= " AND " . $this->getRoleFilterSQL($userRole, 's', $userId);
                }
                
                $sql .= " ORDER BY s.created_at DESC";
                
                $stmt = $this->conn->prepare($sql);
                    $stmt->execute($params);
            $sheets = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $hasProjectColumn = false; // Mark as not available
                } else {
                    throw $sqlError; // Re-throw if we weren't using project columns
                }
            }
            
            // Ensure project_id and project_name exist in results
            foreach ($sheets as &$sheet) {
                if (!isset($sheet['project_id'])) {
                    $sheet['project_id'] = null;
                }
                if (!isset($sheet['project_name'])) {
                    $sheet['project_name'] = null;
                }
                if (!isset($sheet['role'])) {
                    $sheet['role'] = 'all';
                }
            }
            unset($sheet);
            
            return [
                'success' => true,
                'sheets' => $sheets,
                'count' => count($sheets)
            ];
            
        } catch (Exception $e) {
            error_log("Error listing user sheets: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * List all sheets from all users (admins, developers, testers, and others) for admin users, grouped by project
     * 
     * @param string $userId User ID (should be admin)
     * @param bool $includeArchived Include archived sheets (default: false)
     * @return array Sheets grouped by project
     */
    public function listAllSheets($userId, $includeArchived = false) {
        try {
            // Check if project_id column exists
            $hasProjectColumn = false;
            try {
                $testStmt = $this->conn->query("SHOW COLUMNS FROM user_sheets WHERE Field = 'project_id'");
                $hasProjectColumn = $testStmt->rowCount() > 0;
            } catch (Exception $e) {
                try {
                    $testStmt = $this->conn->query("SELECT project_id FROM user_sheets LIMIT 1");
                    $hasProjectColumn = true;
                } catch (Exception $e2) {
                    $hasProjectColumn = false;
                }
            }
            
            // Check if role column exists
            $hasRoleColumn = false;
            try {
                $testStmt = $this->conn->query("SHOW COLUMNS FROM user_sheets WHERE Field = 'role'");
                $hasRoleColumn = $testStmt->rowCount() > 0;
            } catch (Exception $e) {
                try {
                    $testStmt = $this->conn->query("SELECT role FROM user_sheets LIMIT 1");
                    $hasRoleColumn = true;
                } catch (Exception $e2) {
                    $hasRoleColumn = false;
                }
            }
            
            $sql = "SELECT 
                        s.id,
                        s.sheet_title,
                        s.google_sheet_id,
                        s.google_sheet_url,
                        s.sheet_type,
                        s.is_archived,
                        s.created_at,
                        s.updated_at,
                        s.last_accessed_at,
                        s.creator_user_id,
                        u.username as creator_name,
                        t.template_name";
            
            if ($hasProjectColumn) {
                $sql .= ", s.project_id, COALESCE(p.name, '') as project_name";
            } else {
                $sql .= ", NULL as project_id, '' as project_name";
            }
            
            if ($hasRoleColumn) {
                $sql .= ", s.role";
            }
            
            $sql .= " FROM user_sheets s
                    LEFT JOIN sheet_templates t ON s.template_id = t.id
                    LEFT JOIN users u ON s.creator_user_id COLLATE utf8mb4_unicode_ci = u.id COLLATE utf8mb4_unicode_ci";
            
            if ($hasProjectColumn) {
                $sql .= " LEFT JOIN projects p ON s.project_id COLLATE utf8mb4_unicode_ci = p.id COLLATE utf8mb4_unicode_ci";
            }
            
            $sql .= " WHERE 1=1";
            
            if (!$includeArchived) {
                $sql .= " AND s.is_archived = 0";
            }
            
            $sql .= " ORDER BY " . ($hasProjectColumn ? "COALESCE(p.name, 'No Project'), " : "") . "s.created_at DESC";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            $sheets = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Ensure project fields exist
            foreach ($sheets as &$sheet) {
                if (!isset($sheet['project_id'])) {
                    $sheet['project_id'] = null;
                }
                if (!isset($sheet['project_name'])) {
                    $sheet['project_name'] = null;
                }
                if (!isset($sheet['creator_name'])) {
                    $sheet['creator_name'] = null;
                }
                if (!isset($sheet['role'])) {
                    $sheet['role'] = 'all';
                }
            }
            unset($sheet);
            
            // Group by project
            $grouped = [];
            foreach ($sheets as $sheet) {
                $projectId = $sheet['project_id'] ?? 'no-project';
                $projectName = $sheet['project_name'] ?? 'No Project';
                
                if (!isset($grouped[$projectId])) {
                    $grouped[$projectId] = [
                        'project_id' => $sheet['project_id'],
                        'project_name' => $projectName,
                        'sheets' => []
                    ];
                }
                
                $grouped[$projectId]['sheets'][] = $sheet;
            }
            
            return [
                'success' => true,
                'sheets' => array_values($grouped),
                'count' => count($sheets)
            ];
            
        } catch (Exception $e) {
            error_log("Error listing all sheets: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * List shared sheets for developers/testers from projects they're members of
     * 
     * @param string $userId User ID
     * @param bool $includeArchived Include archived sheets (default: false)
     * @return array List of sheets
     */
    public function listSharedSheets($userId, $includeArchived = false) {
        try {
            // Get user role for filtering
            $userRole = $this->getUserRole($userId);
            
            $memberController = new ProjectMemberController();
            $userProjects = $memberController->getUserProjects($userId);
            
            $projectIds = array_column($userProjects, 'project_id');
            
            // If user has no projects, still show sheets with no project (project_id IS NULL)
            // that match their role (developers can see 'all' and 'developers' role sheets)
            if (empty($projectIds)) {
                // Only return sheets with no project that match role
                $hasRoleColumn = false;
                try {
                    $testStmt = $this->conn->query("SHOW COLUMNS FROM user_sheets WHERE Field = 'role'");
                    $hasRoleColumn = $testStmt->rowCount() > 0;
                } catch (Exception $e) {
                    try {
                        $testStmt = $this->conn->query("SELECT role FROM user_sheets LIMIT 1");
                        $hasRoleColumn = true;
                    } catch (Exception $e2) {
                        $hasRoleColumn = false;
                    }
                }
                
                $sql = "SELECT 
                            s.id,
                            s.sheet_title,
                            s.google_sheet_id,
                            s.google_sheet_url,
                            s.sheet_type,
                            s.is_archived,
                            s.created_at,
                            s.updated_at,
                            s.last_accessed_at,
                            s.creator_user_id,
                            u.username as creator_name,
                            t.template_name,
                            s.project_id,
                            '' as project_name";
                
                if ($hasRoleColumn) {
                    $sql .= ", s.role";
                }
                
                $sql .= " FROM user_sheets s
                        LEFT JOIN sheet_templates t ON s.template_id = t.id
                        LEFT JOIN users u ON s.creator_user_id COLLATE utf8mb4_unicode_ci = u.id COLLATE utf8mb4_unicode_ci
                        WHERE s.project_id IS NULL";
                
                if (!$includeArchived) {
                    $sql .= " AND s.is_archived = 0";
                }
                
                if ($hasRoleColumn) {
                    $sql .= " AND " . $this->getRoleFilterSQL($userRole, 's', $userId);
                }
                
                $sql .= " ORDER BY s.created_at DESC";
                
                $stmt = $this->conn->prepare($sql);
                $stmt->execute();
                $sheets = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                return [
                    'success' => true,
                    'sheets' => $sheets,
                    'count' => count($sheets)
                ];
            }
            
            // Check if project_id column exists
            $hasProjectColumn = false;
            try {
                $testStmt = $this->conn->query("SHOW COLUMNS FROM user_sheets WHERE Field = 'project_id'");
                $hasProjectColumn = $testStmt->rowCount() > 0;
            } catch (Exception $e) {
                try {
                    $testStmt = $this->conn->query("SELECT project_id FROM user_sheets LIMIT 1");
                    $hasProjectColumn = true;
                } catch (Exception $e2) {
                    $hasProjectColumn = false;
                }
            }
            
            if (!$hasProjectColumn) {
                // If no project column, return empty (can't filter by project)
                return [
                    'success' => true,
                    'sheets' => [],
                    'count' => 0
                ];
            }
            
            // Check if role column exists
            $hasRoleColumn = false;
            try {
                $testStmt = $this->conn->query("SHOW COLUMNS FROM user_sheets WHERE Field = 'role'");
                $hasRoleColumn = $testStmt->rowCount() > 0;
            } catch (Exception $e) {
                try {
                    $testStmt = $this->conn->query("SELECT role FROM user_sheets LIMIT 1");
                    $hasRoleColumn = true;
                } catch (Exception $e2) {
                    $hasRoleColumn = false;
                }
            }
            
            // Build placeholders for IN clause
            $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
            
            $sql = "SELECT 
                        s.id,
                        s.sheet_title,
                        s.google_sheet_id,
                        s.google_sheet_url,
                        s.sheet_type,
                        s.is_archived,
                        s.created_at,
                        s.updated_at,
                        s.last_accessed_at,
                        s.creator_user_id,
                        u.username as creator_name,
                        t.template_name,
                        s.project_id,
                        COALESCE(p.name, '') as project_name";
            
            if ($hasRoleColumn) {
                $sql .= ", s.role";
            }
            
            $sql .= " FROM user_sheets s
                    LEFT JOIN sheet_templates t ON s.template_id = t.id
                    LEFT JOIN users u ON s.creator_user_id COLLATE utf8mb4_unicode_ci = u.id COLLATE utf8mb4_unicode_ci
                    LEFT JOIN projects p ON s.project_id COLLATE utf8mb4_unicode_ci = p.id COLLATE utf8mb4_unicode_ci
                    WHERE 1=1";
            
            $params = [];
            
            // For developers: show ALL sheets that match their role (role='developers' or role='all')
            // regardless of project association. This ensures they see all accessible sheets.
            // Developers should see: 6 sheets with role='developers' + 7 sheets with role='all' = 13 total
            if ($userRole === 'developer' && $hasRoleColumn) {
                // Developers can see all sheets with role='developers' or role='all', regardless of project
                // This is the key fix: don't filter by project for developers, only by role
                $sql .= " AND " . $this->getRoleFilterSQL($userRole, 's', $userId);
            } else {
                // For other roles (testers, regular users): only show sheets from their projects OR sheets with no project
                // Support comma-separated project IDs: check if any of the user's projects match
                if (!empty($projectIds)) {
                    // Build conditions for each project ID to check if it's in the comma-separated list
                    $projectConditions = [];
                    foreach ($projectIds as $pid) {
                        $projectConditions[] = "(s.project_id = ? OR s.project_id LIKE ? OR s.project_id LIKE ? OR s.project_id LIKE ?)";
                        $params[] = $pid; // Exact match
                        $params[] = $pid . ',%'; // At start of list
                        $params[] = '%,' . $pid; // At end of list
                        $params[] = '%,' . $pid . ',%'; // In middle of list
                    }
                    $sql .= " AND ((" . implode(' OR ', $projectConditions) . ") OR s.project_id IS NULL)";
                } else {
                    // No projects, only show sheets with no project
                    $sql .= " AND s.project_id IS NULL";
                }
                
                // Add role-based filtering
                if ($hasRoleColumn) {
                    $sql .= " AND " . $this->getRoleFilterSQL($userRole, 's', $userId);
                }
            }
            
            if (!$includeArchived) {
                $sql .= " AND s.is_archived = 0";
            }
            
            $sql .= " ORDER BY s.created_at DESC";
            
            error_log("Shared sheets SQL: " . $sql);
            error_log("User role: " . $userRole);
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            $sheets = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Ensure project fields exist
            foreach ($sheets as &$sheet) {
                if (!isset($sheet['project_id'])) {
                    $sheet['project_id'] = null;
                }
                if (!isset($sheet['project_name'])) {
                    $sheet['project_name'] = null;
                }
                if (!isset($sheet['creator_name'])) {
                    $sheet['creator_name'] = null;
                }
                if (!isset($sheet['role'])) {
                    $sheet['role'] = 'all';
                }
            }
            unset($sheet);
            
            return [
                'success' => true,
                'sheets' => $sheets,
                'count' => count($sheets)
            ];
            
        } catch (Exception $e) {
            error_log("Error listing shared sheets: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get sheets for a specific project with access validation
     * 
     * @param string $projectId Project ID
     * @param string $userId User ID
     * @param bool $includeArchived Include archived sheets (default: false)
     * @return array List of sheets
     */
    public function getSheetsByProject($projectId, $userId, $includeArchived = false) {
        try {
            // Validate project access
            $memberController = new ProjectMemberController();
            $hasAccess = $memberController->hasProjectAccess($userId, $projectId);
            
            if (!$hasAccess) {
                throw new Exception('Access denied to this project');
            }
            
            // Check if project_id column exists
            $hasProjectColumn = false;
            try {
                $testStmt = $this->conn->query("SHOW COLUMNS FROM user_sheets WHERE Field = 'project_id'");
                $hasProjectColumn = $testStmt->rowCount() > 0;
            } catch (Exception $e) {
                try {
                    $testStmt = $this->conn->query("SELECT project_id FROM user_sheets LIMIT 1");
                    $hasProjectColumn = true;
                } catch (Exception $e2) {
                    $hasProjectColumn = false;
                }
            }
            
            if (!$hasProjectColumn) {
                return [
                    'success' => true,
                    'sheets' => [],
                    'count' => 0,
                    'project_id' => $projectId,
                    'project_name' => null
                ];
            }
            
            // Get project name
            $projectStmt = $this->conn->prepare("SELECT name FROM projects WHERE id = ?");
            $projectStmt->execute([$projectId]);
            $project = $projectStmt->fetch(PDO::FETCH_ASSOC);
            $projectName = $project ? $project['name'] : null;
            
            $sql = "SELECT 
                        s.id,
                        s.sheet_title,
                        s.google_sheet_id,
                        s.google_sheet_url,
                        s.sheet_type,
                        s.is_archived,
                        s.created_at,
                        s.updated_at,
                        s.last_accessed_at,
                        s.creator_user_id,
                        u.username as creator_name,
                        t.template_name,
                        s.project_id,
                        COALESCE(p.name, '') as project_name
                    FROM user_sheets s
                    LEFT JOIN sheet_templates t ON s.template_id = t.id
                    LEFT JOIN users u ON s.creator_user_id COLLATE utf8mb4_unicode_ci = u.id COLLATE utf8mb4_unicode_ci
                    LEFT JOIN projects p ON s.project_id COLLATE utf8mb4_unicode_ci = p.id COLLATE utf8mb4_unicode_ci
                    WHERE (s.project_id = ? OR s.project_id LIKE ? OR s.project_id LIKE ? OR s.project_id LIKE ?)";
            
            // Support comma-separated project IDs: check if the project ID is in the list
            $params = [
                $projectId, // Exact match
                $projectId . ',%', // At start of list
                '%,' . $projectId, // At end of list
                '%,' . $projectId . ',%' // In middle of list
            ];
            
            if (!$includeArchived) {
                $sql .= " AND s.is_archived = 0";
            }
            
            $sql .= " ORDER BY s.created_at DESC";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            $sheets = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Ensure all fields exist
            foreach ($sheets as &$sheet) {
                if (!isset($sheet['project_id'])) {
                    $sheet['project_id'] = null;
                }
                if (!isset($sheet['project_name'])) {
                    $sheet['project_name'] = $projectName;
                }
                if (!isset($sheet['creator_name'])) {
                    $sheet['creator_name'] = null;
                }
            }
            unset($sheet);
            
            return [
                'success' => true,
                'sheets' => $sheets,
                'count' => count($sheets),
                'project_id' => $projectId,
                'project_name' => $projectName
            ];
            
        } catch (Exception $e) {
            error_log("Error getting sheets by project: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get projects with sheet counts for card display
     * 
     * @param string $userId User ID
     * @return array Projects with sheet counts
     */
    public function getProjectsWithSheetCounts($userId) {
        try {
            $memberController = new ProjectMemberController();
            $userProjects = $memberController->getUserProjects($userId);
            
            // Get user role to determine if admin
            $userStmt = $this->conn->prepare("SELECT role FROM users WHERE id = ?");
            $userStmt->execute([$userId]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            $isAdmin = $user && strtolower($user['role']) === 'admin';
            
            // Check if project_id column exists
            $hasProjectColumn = false;
            try {
                $testStmt = $this->conn->query("SHOW COLUMNS FROM user_sheets WHERE Field = 'project_id'");
                $hasProjectColumn = $testStmt->rowCount() > 0;
            } catch (Exception $e) {
                try {
                    $testStmt = $this->conn->query("SELECT project_id FROM user_sheets LIMIT 1");
                    $hasProjectColumn = true;
                } catch (Exception $e2) {
                    $hasProjectColumn = false;
                }
            }
            
            if (!$hasProjectColumn) {
                // Return projects without counts
                $projectIds = array_column($userProjects, 'project_id');
                if (empty($projectIds) && !$isAdmin) {
                    return [
                        'success' => true,
                        'projects' => []
                    ];
                }
                
                if ($isAdmin) {
                    $projectStmt = $this->conn->query("SELECT id, name, description, status FROM projects");
                    $projects = $projectStmt->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
                    $projectStmt = $this->conn->prepare("SELECT id, name, description, status FROM projects WHERE id IN ($placeholders)");
                    $projectStmt->execute($projectIds);
                    $projects = $projectStmt->fetchAll(PDO::FETCH_ASSOC);
                }
                
                foreach ($projects as &$proj) {
                    $proj['sheet_count'] = 0;
                }
                unset($proj);
                
                return [
                    'success' => true,
                    'projects' => $projects
                ];
            }
            
            // Get projects
            if ($isAdmin) {
                $projectStmt = $this->conn->query("SELECT id, name, description, status FROM projects");
                $projects = $projectStmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $projectIds = array_column($userProjects, 'project_id');
                if (empty($projectIds)) {
                    return [
                        'success' => true,
                        'projects' => []
                    ];
                }
                
                $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
                $projectStmt = $this->conn->prepare("SELECT id, name, description, status FROM projects WHERE id IN ($placeholders)");
                $projectStmt->execute($projectIds);
                $projects = $projectStmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            // Get sheet counts for each project (support comma-separated project IDs)
            foreach ($projects as &$project) {
                $countStmt = $this->conn->prepare(
                    "SELECT COUNT(*) as count FROM user_sheets 
                     WHERE (project_id = ? OR project_id LIKE ? OR project_id LIKE ? OR project_id LIKE ?) 
                     AND is_archived = 0"
                );
                $projectId = $project['id'];
                $countStmt->execute([
                    $projectId, // Exact match
                    $projectId . ',%', // At start of list
                    '%,' . $projectId, // At end of list
                    '%,' . $projectId . ',%' // In middle of list
                ]);
                $countResult = $countStmt->fetch(PDO::FETCH_ASSOC);
                $project['sheet_count'] = (int)$countResult['count'];
            }
            unset($project);
            
            // Also get count for "No Project" if admin
            if ($isAdmin) {
                $noProjectStmt = $this->conn->query(
                    "SELECT COUNT(*) as count FROM user_sheets WHERE (project_id IS NULL OR project_id = '') AND is_archived = 0"
                );
                $noProjectCount = $noProjectStmt->fetch(PDO::FETCH_ASSOC);
                if ($noProjectCount && $noProjectCount['count'] > 0) {
                    $projects[] = [
                        'id' => 'no-project',
                        'name' => 'No Project',
                        'description' => 'Sheets not associated with any project',
                        'status' => 'active',
                        'sheet_count' => (int)$noProjectCount['count']
                    ];
                }
            }
            
            return [
                'success' => true,
                'projects' => $projects
            ];
            
        } catch (Exception $e) {
            error_log("Error getting projects with sheet counts: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Delete a general sheet
     * 
     * @param int $sheetId Sheet ID from user_sheets table
     * @param string $userId User ID (for authorization)
     * @return array Success status
     */
    public function deleteSheet($sheetId, $userId) {
        try {
            // Get sheet details and verify ownership
            $stmt = $this->conn->prepare(
                "SELECT google_sheet_id, creator_user_id, sheet_title 
                 FROM user_sheets 
                 WHERE id = ? AND creator_user_id = ?"
            );
            $stmt->execute([$sheetId, $userId]);
            $sheet = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$sheet) {
                throw new Exception('Sheet not found or access denied');
            }
            
            // Get authenticated client
            $client = $this->authService->getClientForUser($userId);
            $driveService = new Google\Service\Drive($client);
            
            // Delete from Google Drive
            try {
                $driveService->files->delete($sheet['google_sheet_id']);
                error_log("Deleted Google Sheet: {$sheet['google_sheet_id']}");
            } catch (Exception $e) {
                error_log("Warning: Failed to delete from Google Drive: " . $e->getMessage());
                // Continue to delete from database even if Google Drive deletion fails
            }
            
            // Delete from database
            $stmt = $this->conn->prepare("DELETE FROM user_sheets WHERE id = ?");
            $stmt->execute([$sheetId]);
            
            return [
                'success' => true,
                'message' => "Sheet '{$sheet['sheet_title']}' deleted successfully"
            ];
            
        } catch (Exception $e) {
            error_log("Error deleting sheet: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Update a sheet title, project, and template
     * 
     * @param int $sheetId Sheet ID from user_sheets table
     * @param string $userId User ID (for authorization)
     * @param string $newTitle New sheet title
     * @param bool $isAdmin Whether the user is an admin (admins can edit any sheet)
     * @param string|null $projectId New project ID (optional, null to remove project association)
     * @param int|null $templateId New template ID (optional, null to remove template association)
     * @param string $role Role access (default: 'all')
     * @return array Success status and updated sheet info
     */
    public function updateSheet($sheetId, $userId, $newTitle, $isAdmin = false, $projectId = null, $templateId = null, $role = 'all') {
        try {
            // Get sheet details - admins can edit any sheet, others can only edit their own
            if ($isAdmin) {
                $stmt = $this->conn->prepare(
                    "SELECT google_sheet_id, creator_user_id, sheet_title, project_id, template_id 
                     FROM user_sheets 
                     WHERE id = ?"
                );
                $stmt->execute([$sheetId]);
            } else {
                $stmt = $this->conn->prepare(
                    "SELECT google_sheet_id, creator_user_id, sheet_title, project_id, template_id 
                     FROM user_sheets 
                     WHERE id = ? AND creator_user_id = ?"
                );
                $stmt->execute([$sheetId, $userId]);
            }
            $sheet = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$sheet) {
                throw new Exception('Sheet not found or access denied');
            }
            
            // For admin editing someone else's sheet, use the sheet owner's Google account
            $googleAccountUserId = $isAdmin && $sheet['creator_user_id'] !== $userId 
                ? $sheet['creator_user_id'] 
                : $userId;
            
            // Get authenticated client - use sheet owner's account for Google Drive update
            // (admins editing other users' sheets will need the owner's Google account connected)
            try {
                $client = $this->authService->getClientForUser($googleAccountUserId);
                $driveService = new Google\Service\Drive($client);
                
                // Update Google Drive file name
                $file = new \Google\Service\Drive\DriveFile();
                $file->setName($newTitle);
                $driveService->files->update($sheet['google_sheet_id'], $file);
                error_log("Updated Google Sheet title: {$sheet['google_sheet_id']} to '{$newTitle}'");
            } catch (Exception $e) {
                // If admin is editing and Google Drive update fails (e.g., owner's account not connected),
                // we still update the database for consistency
                if ($isAdmin) {
                    error_log("Warning: Admin editing sheet - Failed to update Google Drive title (owner's account may not be connected): " . $e->getMessage());
                } else {
                    error_log("Warning: Failed to update Google Drive title: " . $e->getMessage());
                }
                // Continue to update database even if Google Drive update fails
            }
            
            // Prepare project_id - convert empty string to null
            $finalProjectId = ($projectId !== null && $projectId !== '' && $projectId !== 'none') ? $projectId : null;
            
            // Prepare template_id - convert empty string or '0' to null
            $finalTemplateId = ($templateId !== null && $templateId !== '' && $templateId !== '0' && $templateId !== 0) 
                ? (int)$templateId 
                : null;
            
            // Update database - update title, project_id, template_id, and role
            $updateFields = ['sheet_title = ?', 'updated_at = CURRENT_TIMESTAMP'];
            $updateParams = [$newTitle];
            
            // Check if project_id column exists before updating
            try {
                $columnCheck = $this->conn->query("SHOW COLUMNS FROM user_sheets LIKE 'project_id'");
                if ($columnCheck && $columnCheck->rowCount() > 0) {
                    $updateFields[] = 'project_id = ?';
                    $updateParams[] = $finalProjectId;
                }
            } catch (Exception $e) {
                error_log("Note: project_id column check: " . $e->getMessage());
            }
            
            // Check if template_id column exists before updating
            try {
                $columnCheck = $this->conn->query("SHOW COLUMNS FROM user_sheets LIKE 'template_id'");
                if ($columnCheck && $columnCheck->rowCount() > 0) {
                    $updateFields[] = 'template_id = ?';
                    $updateParams[] = $finalTemplateId;
                }
            } catch (Exception $e) {
                error_log("Note: template_id column check: " . $e->getMessage());
            }
            
            // Check if role column exists before updating
            try {
                $columnCheck = $this->conn->query("SHOW COLUMNS FROM user_sheets LIKE 'role'");
                if ($columnCheck && $columnCheck->rowCount() > 0) {
                    $updateFields[] = 'role = ?';
                    $updateParams[] = $role;
                }
            } catch (Exception $e) {
                error_log("Note: role column check: " . $e->getMessage());
            }
            
            $updateParams[] = $sheetId;
            
            $stmt = $this->conn->prepare(
                "UPDATE user_sheets 
                 SET " . implode(', ', $updateFields) . " 
                 WHERE id = ?"
            );
            $stmt->execute($updateParams);
            
            return [
                'success' => true,
                'message' => 'Sheet updated successfully',
                'data' => [
                    'id' => $sheetId,
                    'sheet_title' => $newTitle
                ]
            ];
            
        } catch (Exception $e) {
            error_log("Error updating sheet: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Archive/unarchive a document (soft delete)
     * 
     * @param int $documentId Document ID
     * @param string $userId User ID
     * @param bool $archive True to archive, false to unarchive
     * @return array Success status
     */
    public function archiveSheet($sheetId, $userId, $archive = true) {
        try {
            $stmt = $this->conn->prepare(
                "UPDATE user_sheets 
                 SET is_archived = ?, updated_at = CURRENT_TIMESTAMP 
                 WHERE id = ? AND creator_user_id = ?"
            );
            $stmt->execute([$archive ? 1 : 0, $sheetId, $userId]);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('Sheet not found or access denied');
            }
            
            return [
                'success' => true,
                'message' => $archive ? 'Sheet archived' : 'Sheet restored'
            ];
            
        } catch (Exception $e) {
            error_log("Error archiving sheet: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Update last accessed timestamp
     * 
     * @param int $sheetId Sheet ID
     * @param string $userId User ID
     */
    public function trackAccess($sheetId, $userId) {
        try {
            $stmt = $this->conn->prepare(
                "UPDATE user_sheets 
                 SET last_accessed_at = CURRENT_TIMESTAMP 
                 WHERE id = ? AND creator_user_id = ?"
            );
            $stmt->execute([$sheetId, $userId]);
        } catch (Exception $e) {
            error_log("Error tracking access: " . $e->getMessage());
            // Non-critical, don't throw
        }
    }
    
    // ========================================================================
    // Template Methods
    // ========================================================================
    
    /**
     * Get all active templates
     * 
     * @param string|null $category Filter by category (optional)
     * @return array List of templates
     */
    public function listTemplates($category = null) {
        try {
            // Try sheet_templates first, fallback to doc_templates if it doesn't exist
            $sql = "SELECT * FROM sheet_templates WHERE is_active = 1";
            $params = [];
            
            // Check if sheet_templates table exists, if not use doc_templates
            try {
                $testStmt = $this->conn->query("SHOW TABLES LIKE 'sheet_templates'");
                $tableExists = $testStmt->rowCount() > 0;
                if (!$tableExists) {
                    $sql = "SELECT * FROM doc_templates WHERE is_active = 1";
                }
            } catch (Exception $e) {
                // Fallback to doc_templates
                $sql = "SELECT * FROM doc_templates WHERE is_active = 1";
            }
            
            if ($category) {
                $sql .= " AND category = ?";
                $params[] = $category;
            }
            
            $sql .= " ORDER BY category, template_name";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Mark templates with placeholder IDs as not ready
            foreach ($templates as &$template) {
                $sheetId = $template['google_sheet_id'] ?? $template['google_doc_id'] ?? '';
                $template['is_configured'] = !$this->isPlaceholderTemplateId($sheetId);
            }
            
            return [
                'success' => true,
                'templates' => $templates,
                'count' => count($templates)
            ];
            
        } catch (Exception $e) {
            error_log("Error listing templates: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get template by name
     */
    private function getTemplate($templateName) {
        try {
            // Try sheet_templates first, fallback to doc_templates
            $sql = "SELECT * FROM sheet_templates WHERE template_name = ? AND is_active = 1 LIMIT 1";
            try {
                $testStmt = $this->conn->query("SHOW TABLES LIKE 'sheet_templates'");
                if ($testStmt->rowCount() === 0) {
                    $sql = "SELECT * FROM doc_templates WHERE template_name = ? AND is_active = 1 LIMIT 1";
                }
            } catch (Exception $e) {
                $sql = "SELECT * FROM doc_templates WHERE template_name = ? AND is_active = 1 LIMIT 1";
            }
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$templateName]);
            $template = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Check if template has a valid Google Sheet ID (not a placeholder)
            $sheetId = $template['google_sheet_id'] ?? $template['google_doc_id'] ?? '';
            if ($template && $this->isPlaceholderTemplateId($sheetId)) {
                error_log("Template {$template['template_name']} has placeholder ID, skipping template");
                return null;
            }
            
            return $template;
        } catch (Exception $e) {
            error_log("Error getting template: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get template by ID
     */
    private function getTemplateById($templateId) {
        try {
            // Try sheet_templates first, fallback to doc_templates
            $sql = "SELECT * FROM sheet_templates WHERE id = ? AND is_active = 1 LIMIT 1";
            try {
                $testStmt = $this->conn->query("SHOW TABLES LIKE 'sheet_templates'");
                if ($testStmt->rowCount() === 0) {
                    $sql = "SELECT * FROM doc_templates WHERE id = ? AND is_active = 1 LIMIT 1";
                }
            } catch (Exception $e) {
                $sql = "SELECT * FROM doc_templates WHERE id = ? AND is_active = 1 LIMIT 1";
            }
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$templateId]);
            $template = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Check if template has a valid Google Sheet ID (not a placeholder)
            $sheetId = $template['google_sheet_id'] ?? $template['google_doc_id'] ?? '';
            if ($template && $this->isPlaceholderTemplateId($sheetId)) {
                error_log("Template {$template['template_name']} has placeholder ID, skipping template");
                return null;
            }
            
            return $template;
        } catch (Exception $e) {
            error_log("Error getting template by ID: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Check if a template ID is a placeholder
     */
    private function isPlaceholderTemplateId($sheetId) {
        // Check for common placeholder patterns
        $placeholders = [
            'TEMPLATE_',
            'YOUR_DOC_ID',
            'YOUR_ACTUAL_DOC_ID',
            'PLACEHOLDER',
            'REPLACE_ME',
            'CHANGE_THIS'
        ];
        
        foreach ($placeholders as $placeholder) {
            if (stripos($docId, $placeholder) !== false) {
                return true;
            }
        }
        
        // Check if it's too short (real Google Doc IDs are typically 44 characters)
        if (strlen($docId) < 20) {
            return true;
        }
        
        return false;
    }
    
    // ========================================================================
    // Helper Methods
    // ========================================================================
    
    /**
     * Create sheet from template using Drive API copy
     */
    private function createFromTemplate($driveService, $sheetsService, $templateSheetId, $newTitle, $placeholders) {
        // Copy the template
        $copiedFile = new Google\Service\Drive\DriveFile();
        $copiedFile->setName($newTitle);
        
        $newFile = $driveService->files->copy($templateSheetId, $copiedFile);
        $newSheetId = $newFile->getId();
        
        // Set default sharing permissions (Anyone with link - Editor)
        $this->setDefaultSharingPermissions($driveService, $newSheetId);
        
        // Replace placeholders
        if (!empty($placeholders)) {
            $this->replacePlaceholders($sheetsService, $newSheetId, $placeholders);
        }
        
        return [
            'sheetId' => $newSheetId,
            'sheetUrl' => "https://docs.google.com/spreadsheets/d/{$newSheetId}/edit"
        ];
    }
    
    /**
     * Replace placeholders in a sheet
     */
    private function replacePlaceholders($sheetsService, $sheetId, $placeholders) {
        // For Google Sheets, we need to use findReplace requests
        $requests = [];
        
        foreach ($placeholders as $placeholder => $value) {
            $requests[] = new Google\Service\Sheets\Request([
                'findReplace' => [
                    'find' => $placeholder,
                    'replacement' => $value,
                    'matchCase' => false,
                    'matchEntireCell' => false,
                    'searchByRegex' => false,
                    'includeFormulas' => true,
                    'sheetId' => 0 // Default sheet
                ]
            ]);
        }
        
        if (!empty($requests)) {
            $batchUpdateRequest = new Google\Service\Sheets\BatchUpdateSpreadsheetRequest([
                'requests' => $requests
            ]);
            $sheetsService->spreadsheets->batchUpdate($sheetId, $batchUpdateRequest);
        }
    }
    
    /**
     * Get bug details from database
     */
    private function getBugDetails($bugId) {
        try {
            $stmt = $this->conn->prepare(
                "SELECT * FROM bugs WHERE id = ? LIMIT 1"
            );
            $stmt->execute([$bugId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting bug details: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get placeholders for bug documents
     */
    private function getBugPlaceholders($bugDetails) {
        return [
            '{{BUG_ID}}' => $bugDetails['id'],
            '{{BUG_TITLE}}' => $bugDetails['title'],
            '{{DESCRIPTION}}' => $this->cleanBugDescription($bugDetails['description'] ?? ''),
            '{{PRIORITY}}' => strtoupper($bugDetails['priority'] ?? 'MEDIUM'),
            '{{STATUS}}' => strtoupper($bugDetails['status'] ?? 'PENDING'),
            '{{CREATED_DATE}}' => date('F j, Y', strtotime($bugDetails['created_at'])),
            '{{CURRENT_DATE}}' => date('F j, Y')
        ];
    }
    
    /**
     * Get placeholders for general sheets
     */
    private function getGeneralPlaceholders($userId, $sheetTitle) {
        return [
            '{{TITLE}}' => $sheetTitle,
            '{{USER_ID}}' => $userId,
            '{{DATE}}' => date('F j, Y'),
            '{{CURRENT_DATE}}' => date('F j, Y'),
            '{{TIMESTAMP}}' => date('F j, Y g:i A')
        ];
    }
    
    /**
     * Clean bug description (remove debug data)
     */
    private function cleanBugDescription($description) {
        $description = preg_replace('/Voice note debug:.*?}/s', '', $description);
        $description = preg_replace('/Screenshot container:.*?}/s', '', $description);
        $description = preg_replace('/Duration loaded for:.*?\.webm/s', '', $description);
        $description = preg_replace('/\{[^}]*id[^}]*\}/', '', $description);
        $description = preg_replace('/apiBaseUrl[^,]*/', '', $description);
        $description = preg_replace('/audioUrl[^,]*/', '', $description);
        $description = preg_replace('/filePath[^,]*/', '', $description);
        $description = preg_replace('/\s+/', ' ', $description);
        $description = trim($description);
        
        if (empty($description) || strlen($description) < 10) {
            return "Bug reported through BugRicer system. Please add detailed description of the issue.";
        }
        
        return $description;
    }
    
    /**
     * Add content to bug sheet (for non-template creation)
     */
    private function addBugContent($sheetsService, $sheetId, $bugDetails) {
        $description = $this->cleanBugDescription($bugDetails['description'] ?? 'No description provided');
        
        // For Google Sheets, we add data as rows
        $values = [
            ['BUG REPORT & INVESTIGATION SHEET'],
            [''],
            ['ISSUE OVERVIEW'],
            ['Bug Reference:', $bugDetails['id']],
            ['Title:', $bugDetails['title']],
            ['Severity:', strtoupper($bugDetails['priority']) . ' PRIORITY'],
            ['Current Status:', strtoupper($bugDetails['status'])],
            ['Reported Date:', date('F j, Y', strtotime($bugDetails['created_at']))],
            [''],
            ['DESCRIPTION'],
            [$description]
        ];
        
        $range = 'Sheet1!A1';
        $body = new Google\Service\Sheets\ValueRange([
            'values' => $values
        ]);
        
        $params = [
            'valueInputOption' => 'RAW'
        ];
        
        $sheetsService->spreadsheets_values->update($sheetId, $range, $body, $params);
    }
    
    /**
     * Set default sharing permissions for a sheet
     * Sets "Anyone with the link" to "Editor" access
     * 
     * @param Google\Service\Drive $driveService Drive service instance
     * @param string $sheetId Google Sheet ID
     */
    private function setDefaultSharingPermissions($driveService, $sheetId) {
        try {
            // Create permission for "Anyone with the link" to have "Editor" access
            $permission = new Google\Service\Drive\Permission([
                'type' => 'anyone',
                'role' => 'writer', // 'writer' = Editor access
                'allowFileDiscovery' => false // Only accessible via link, not searchable
            ]);
            
            // Apply the permission
            $driveService->permissions->create($sheetId, $permission);
            
            error_log("Set default sharing permissions for sheet: {$sheetId}");
            
        } catch (Exception $e) {
            error_log("Warning: Failed to set sharing permissions for sheet {$sheetId}: " . $e->getMessage());
            // Don't throw - sheet creation should still succeed even if sharing fails
        }
    }
    
    /**
     * Check if an exception is a Google API exception
     * 
     * @param Exception $e Exception to check
     * @return bool True if it's a Google API exception
     */
    private function isGoogleApiException($e) {
        $className = get_class($e);
        $message = $e->getMessage();
        
        // Check class name
        if (strpos($className, 'Google') !== false || 
            strpos($className, 'Guzzle') !== false) {
            return true;
        }
        
        // Check message for Google API indicators
        if (strpos($message, 'Google') !== false ||
            strpos($message, 'googleapis.com') !== false ||
            strpos($message, 'SERVICE_DISABLED') !== false ||
            strpos($message, 'accessNotConfigured') !== false ||
            strpos($message, '403') !== false && strpos($message, 'API') !== false) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Format Google API exception into user-friendly error message
     * 
     * @param Exception $e Google API exception
     * @return string Formatted error message
     */
    private function formatGoogleApiError($e) {
        $message = $e->getMessage();
        $code = $e->getCode();
        
        // Check for specific Google API errors
        if (strpos($message, 'SERVICE_DISABLED') !== false || 
            strpos($message, 'accessNotConfigured') !== false ||
            strpos($message, 'Google Sheets API') !== false) {
            
            // Extract project ID if available
            $projectId = '';
            if (preg_match('/project\s+(\d+)/i', $message, $matches)) {
                $projectId = $matches[1];
            }
            
            $activationUrl = 'https://console.developers.google.com/apis/api/sheets.googleapis.com';
            if ($projectId) {
                $activationUrl .= '?project=' . $projectId;
            }
            
            return "Google Sheets API is not enabled for your Google Cloud project. " .
                   "Please enable it by visiting: {$activationUrl} " .
                   "Then wait a few minutes for the changes to propagate and try again.";
        }
        
        // Check for authentication errors
        if ($code == 401 || strpos($message, 'unauthorized') !== false || strpos($message, 'Invalid Credentials') !== false) {
            return "Google authentication failed. Please reconnect your Google account in the settings.";
        }
        
        // Check for permission errors
        if ($code == 403 || strpos($message, 'permission') !== false || strpos($message, 'forbidden') !== false) {
            return "You don't have permission to create Google Sheets. Please check your Google account permissions.";
        }
        
        // Generic error - return the original message but make it more user-friendly
        return "Failed to create Google Sheet: " . $message . 
               " If this problem persists, please check your Google account connection and API permissions.";
    }
}

