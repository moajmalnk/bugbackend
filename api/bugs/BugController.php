<?php

require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../ActivityLogger.php';

class BugController extends BaseAPI {
    private $baseUrl;

    public function __construct() {
        parent::__construct();
        $this->baseUrl = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
        $this->baseUrl .= $_SERVER['HTTP_HOST'];
        
        // Handle different environments
        $host = $_SERVER['HTTP_HOST'];
        if (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false) {
            // Local development
            $this->baseUrl .= '/BugRicer/backend';
        } elseif (strpos($host, 'bugbackend.moajmalnk.in') !== false) {
            // Production backend - no additional path needed
            // Base URL is already correct
        } else {
            // Other environments - try to detect if we're in a subdirectory
            $scriptPath = dirname($_SERVER['SCRIPT_NAME']);
            if ($scriptPath !== '/' && $scriptPath !== '') {
                $this->baseUrl .= $scriptPath;
            }
        }
    }

    private function getFullPath($path) {
        // Remove any leading slashes
        $path = ltrim($path, '/');
        
        // Check if this is an image file
        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        
        if (in_array($extension, $imageExtensions)) {
            // Use the image API endpoint for images to ensure CORS
            return $this->baseUrl . '/api/image.php?path=' . urlencode($path);
        } else {
            // Use direct path for non-image files
            return $this->baseUrl . '/' . $path;
        }
    }

    public function handleError($message, $code = 400) {
        header('Content-Type: application/json');
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'message' => $message
        ]);
        exit;
    }

    private function handleSuccess($message, $data = []) {
        header('Content-Type: application/json');
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => $message,
            'data' => $data
        ]);
        exit;
    }

    private function generateUUID() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    public function createBug($data) {
        try {
            // Validate required fields
            $requiredFields = ['name', 'project_id', 'reporter_id'];
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Missing required field: $field");
                }
            }

            $this->conn->beginTransaction();

            $bugId = $this->generateUUID();
            
            // Insert bug
            $stmt = $this->conn->prepare("
                INSERT INTO bugs (
                    id, title, description, expected_result, actual_result, project_id, reported_by,
                    priority, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $result = $stmt->execute([
                $bugId,
                $data['name'],
                $data['description'],
                isset($data['expected_result']) ? $data['expected_result'] : null,
                isset($data['actual_result']) ? $data['actual_result'] : null,
                $data['project_id'],
                $data['reporter_id'],
                $data['priority'],
                $data['status']
            ]);

            if (!$result) {
                $error = $stmt->errorInfo();
                throw new PDOException("Failed to insert bug: " . $error[2]);
            }

            // Initialize uploadedAttachments array
            $uploadedAttachments = [];

            // Insert screenshots
            if (!empty($data['screenshots'])) {
                $stmt = $this->conn->prepare("
                    INSERT INTO bug_attachments (
                        id, bug_id, file_name, file_path, file_type,
                        uploaded_by
                    ) VALUES (?, ?, ?, ?, ?, ?)
                ");

                foreach ($data['screenshots'] as $screenshot) {
                    $attachmentId = $this->generateUUID();
                    $result = $stmt->execute([
                        $attachmentId,
                        $bugId,
                        $screenshot['file_name'],
                        $screenshot['file_path'],
                        $screenshot['file_type'],
                        $data['reporter_id']
                    ]);

                    if (!$result) {
                        $error = $stmt->errorInfo();
                        throw new PDOException("Failed to insert screenshot: " . $error[2]);
                    }
                    $uploadedAttachments[] = $screenshot['file_path'];
                }
            }

            // Insert other files
            if (!empty($data['files'])) {
                $stmt = $this->conn->prepare("
                    INSERT INTO bug_attachments (
                        id, bug_id, file_name, file_path, file_type,
                        uploaded_by
                    ) VALUES (?, ?, ?, ?, ?, ?)
                ");

                foreach ($data['files'] as $file) {
                    $attachmentId = $this->generateUUID();
                    $result = $stmt->execute([
                        $attachmentId,
                        $bugId,
                        $file['file_name'],
                        $file['file_path'],
                        $file['file_type'],
                        $data['reporter_id']
                    ]);

                    if (!$result) {
                        $error = $stmt->errorInfo();
                        throw new PDOException("Failed to insert file: " . $error[2]);
                    }
                    $uploadedAttachments[] = $file['file_path'];
                }
            }

            // Insert affected dashboards
            if (!empty($data['affected_dashboards'])) {
                $stmt = $this->conn->prepare("
                    INSERT INTO bug_dashboards (
                        bug_id, dashboard_id
                    ) VALUES (?, ?)
                ");

                foreach ($data['affected_dashboards'] as $dashboardId) {
                    $result = $stmt->execute([$bugId, $dashboardId]);
                    if (!$result) {
                        $error = $stmt->errorInfo();
                        throw new PDOException("Failed to insert dashboard: " . $error[2]);
                    }
                }
            }

            $this->conn->commit();

            // Log activity
            try {
                $logger = ActivityLogger::getInstance();
                $logger->logBugCreated(
                    $data['reporter_id'],
                    $data['project_id'],
                    $bugId,
                    $data['name'],
                    [
                        'priority' => $data['priority'],
                        'status' => $data['status'],
                        'attachments_count' => count($uploadedAttachments)
                    ]
                );
            } catch (Exception $e) {
                error_log("Failed to log bug creation activity: " . $e->getMessage());
            }

            $this->handleSuccess("Bug created successfully", [
                'bugId' => $bugId,
                'uploadedAttachments' => $uploadedAttachments
            ]);
        } catch (PDOException $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            $this->handleError("Database error: " . $e->getMessage(), 500);
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            $this->handleError("Server error: " . $e->getMessage(), 500);
        }
    }

    public function getBugs() {
        try {
            $stmt = $this->conn->prepare("
                SELECT b.*, 
                       GROUP_CONCAT(DISTINCT ba.file_path) as screenshots,
                       GROUP_CONCAT(DISTINCT bd.dashboard_id) as dashboards
                FROM bugs b
                LEFT JOIN bug_attachments ba ON b.id = ba.bug_id
                LEFT JOIN bug_dashboards bd ON b.id = bd.bug_id
                GROUP BY b.id
                ORDER BY b.created_at DESC
            ");

            $stmt->execute();
            $bugs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $this->handleSuccess("Bugs retrieved successfully", [
                'bugs' => $bugs
            ]);
        } catch (Exception $e) {
            $this->handleError("Failed to retrieve bugs: " . $e->getMessage(), 500);
        }
    }

    public function getAll() {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }

        try {
            $decoded = $this->validateToken();
            
            $projectId = isset($_GET['project_id']) ? $_GET['project_id'] : null;
            
            $query = "SELECT b.*, 
                            p.name as project_name,
                            reporter.username as reporter_name,
                            updater.username as updated_by_name
                     FROM bugs b
                     LEFT JOIN projects p ON b.project_id = p.id
                     LEFT JOIN users reporter ON b.reported_by = reporter.id
                     LEFT JOIN users updater ON b.updated_by = updater.id";
                     
            if ($projectId) {
                $query .= " WHERE b.project_id = ?";
            }
            
            $query .= " ORDER BY b.created_at DESC";
            
            $stmt = $this->conn->prepare($query);
            
            if ($projectId) {
                $stmt->execute([$projectId]);
            } else {
                $stmt->execute();
            }
            
            $bugs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $this->sendJsonResponse(200, "Bugs retrieved successfully", $bugs);
            
        } catch (Exception $e) {
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }
    
    public function getById($id) {
        try {
            // Ensure ID is string for consistent comparison
            $id = (string)$id;
            
            $stmt = $this->conn->prepare("
                SELECT b.*, 
                       p.name as project_name,
                       reporter.username as reporter_name,
                       updater.username as updated_by_name,
                       fixer.username as fixed_by_name
                FROM bugs b
                LEFT JOIN projects p ON b.project_id = p.id
                LEFT JOIN users reporter ON b.reported_by = reporter.id
                LEFT JOIN users updater ON b.updated_by = updater.id
                LEFT JOIN users fixer ON b.fixed_by = fixer.id
                WHERE b.id = ?
            ");
            
            $stmt->execute([$id]);
            $bug = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$bug) {
                $this->handleError("Bug not found", 404);
                return;
            }

            // Get attachments - use CAST to handle type mismatches
            $attachStmt = $this->conn->prepare("
                SELECT id, file_name, file_path, file_type, uploaded_by, created_at
                FROM bug_attachments
                WHERE CAST(bug_id AS CHAR) = CAST(? AS CHAR)
                ORDER BY created_at ASC
            ");
            $attachStmt->execute([$id]);
            $attachments = $attachStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Debug logging
            error_log("BugController::getById - Bug ID: $id (type: " . gettype($id) . "), Attachments found: " . count($attachments));
            if (count($attachments) > 0) {
                error_log("BugController::getById - Sample attachment: " . json_encode($attachments[0]));
            } else {
                // Check if attachments exist at all - try multiple query methods
                $checkStmt1 = $this->conn->prepare("SELECT COUNT(*) as count FROM bug_attachments WHERE bug_id = ?");
                $checkStmt1->execute([$id]);
                $checkResult1 = $checkStmt1->fetch(PDO::FETCH_ASSOC);
                
                $checkStmt2 = $this->conn->prepare("SELECT COUNT(*) as count FROM bug_attachments WHERE CAST(bug_id AS CHAR) = CAST(? AS CHAR)");
                $checkStmt2->execute([$id]);
                $checkResult2 = $checkStmt2->fetch(PDO::FETCH_ASSOC);
                
                // Also check raw bug_id values in database
                $checkStmt3 = $this->conn->prepare("SELECT DISTINCT bug_id FROM bug_attachments LIMIT 10");
                $checkStmt3->execute();
                $sampleBugIds = $checkStmt3->fetchAll(PDO::FETCH_COLUMN);
                
                error_log("BugController::getById - Direct query count: " . ($checkResult1['count'] ?? 0));
                error_log("BugController::getById - CAST query count: " . ($checkResult2['count'] ?? 0));
                error_log("BugController::getById - Sample bug_ids in attachments table: " . json_encode($sampleBugIds));
                error_log("BugController::getById - Looking for bug_id: " . $id);
            }

            // Process attachments and add them to the bug object
            $bug['attachments'] = [];
            $bug['screenshots'] = [];
            $bug['files'] = [];
            
            foreach ($attachments as $attachment) {
                // Ensure path has the correct prefix
                $path = $attachment['file_path'];
                $fullPath = $this->getFullPath($path);
                
                // Create attachment object for frontend
                $attachmentObj = [
                    'id' => $attachment['id'],
                    'file_name' => $attachment['file_name'],
                    'file_path' => $attachment['file_path'], // Keep original path for frontend
                    'file_type' => $attachment['file_type'],
                    'uploaded_by' => $attachment['uploaded_by'],
                    'created_at' => $attachment['created_at']
                ];
                
                $bug['attachments'][] = $attachmentObj;
                
                // Also categorize them for backward compatibility
                if (strpos($attachment['file_type'], 'image/') === 0 || 
                    preg_match('/\.(jpg|jpeg|png|gif|webp|bmp|svg)$/i', $attachment['file_name'])) {
                    $bug['screenshots'][] = [
                        'id' => $attachment['id'],
                        'name' => $attachment['file_name'],
                        'path' => $fullPath,
                        'type' => $attachment['file_type']
                    ];
                } else {
                    $bug['files'][] = [
                        'id' => $attachment['id'],
                        'name' => $attachment['file_name'],
                        'path' => $fullPath,
                        'type' => $attachment['file_type']
                    ];
                }
            }
            
            // Add debug info to response in development
            if (isset($_SERVER['HTTP_HOST']) && (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false || strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false)) {
                $bug['_debug'] = [
                    'bug_id' => $id,
                    'bug_id_type' => gettype($id),
                    'attachments_count' => count($attachments),
                    'query_used' => 'CAST(bug_id AS CHAR) = CAST(? AS CHAR)'
                ];
            }
            
            $this->handleSuccess("Bug details retrieved successfully", $bug);
        } catch (Exception $e) {
            $this->handleError("Failed to retrieve bug details: " . $e->getMessage(), 500);
        }
    }
    
    public function create() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }

        try {
            $decoded = $this->validateToken();
            $data = $this->getRequestData();
            
            if (!isset($data['title']) || !isset($data['description']) || !isset($data['project_id'])) {
                $this->sendJsonResponse(400, "Title, description and project_id are required");
                return;
            }
            
            $this->conn->beginTransaction();
            
            $id = Utils::generateUUID();
            
            // Get IST time for insertion
            $istTime = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
            $istTimeStr = $istTime->format('Y-m-d H:i:s');
            
            $stmt = $this->conn->prepare(
                "INSERT INTO bugs (id, title, description, expected_result, actual_result, project_id, reported_by, priority, status, created_at, updated_at) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            
            $priority = isset($data['priority']) ? $data['priority'] : 'medium';
            $status = 'pending';
            $expectedResult = isset($data['expected_result']) ? $data['expected_result'] : null;
            $actualResult = isset($data['actual_result']) ? $data['actual_result'] : null;
            
            $stmt->execute([
                $id,
                $data['title'],
                $data['description'],
                $expectedResult,
                $actualResult,
                $data['project_id'],
                $decoded->user_id,
                $priority,
                $status,
                $istTimeStr,
                $istTimeStr
            ]);

            // Initialize array to collect all uploaded file paths
            $uploadedAttachments = [];

            // Debug: Log received files
            error_log("BugController::create - Received FILES keys: " . json_encode(array_keys($_FILES)));
            if (!empty($_FILES)) {
                foreach ($_FILES as $key => $file) {
                    if (is_array($file) && isset($file['name'])) {
                        // Single file
                        error_log("BugController::create - File key: $key, Name: " . ($file['name'] ?? 'N/A') . ", Error: " . ($file['error'] ?? 'N/A') . ", Size: " . ($file['size'] ?? 'N/A'));
                    } else if (is_array($file) && isset($file[0])) {
                        // Multiple files
                        error_log("BugController::create - File key: $key, Count: " . count($file));
                        foreach ($file as $idx => $f) {
                            if (is_array($f)) {
                                error_log("BugController::create -   [$idx] Name: " . ($f['name'] ?? 'N/A') . ", Error: " . ($f['error'] ?? 'N/A') . ", Size: " . ($f['size'] ?? 'N/A'));
                            }
                        }
                    }
                }
            } else {
                error_log("BugController::create - WARNING: \$_FILES is empty!");
            }

            // Handle screenshots
            if (!empty($_FILES['screenshots'])) {
                $uploadDir = __DIR__ . '/../../uploads/screenshots/';
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                foreach ($_FILES['screenshots']['tmp_name'] as $key => $tmp_name) {
                    $fileName = $_FILES['screenshots']['name'][$key];
                    $fileType = $_FILES['screenshots']['type'][$key];
                    $fileError = $_FILES['screenshots']['error'][$key];
                    
                    // Check for upload errors
                    if ($fileError !== UPLOAD_ERR_OK || empty($tmp_name)) {
                        error_log("BugController::create - Skipping screenshot $fileName (Error: $fileError)");
                        continue;
                    }
                    
                    $filePath = $uploadDir . uniqid() . '_' . $fileName;
                    
                    if (move_uploaded_file($tmp_name, $filePath)) {
                        error_log("BugController::create - Saved screenshot: $filePath");
                        $attachmentId = Utils::generateUUID();
                        // Store path relative to the 'uploads' directory
                        $relativePath = str_replace(__DIR__ . '/../../uploads/', 'uploads/', $filePath);
                        $stmt = $this->conn->prepare(
                            "INSERT INTO bug_attachments (id, bug_id, file_name, file_path, file_type, uploaded_by) 
                             VALUES (?, ?, ?, ?, ?, ?)"
                        );
                        $bugIdForAttachment = (string)$id; // Ensure string type
                        $stmt->execute([
                            $attachmentId,
                            $bugIdForAttachment,
                            $fileName,
                            $relativePath,
                            $fileType,
                            $decoded->user_id
                        ]);
                        error_log("BugController::create - Saved attachment: bug_id=$bugIdForAttachment, file=$fileName");
                        // Add the relative path to the list
                        $uploadedAttachments[] = $relativePath;
                        @unlink($tmp_name);
                    }
                }
            }

            // Handle other files
            if (!empty($_FILES['files'])) {
                $uploadDir = __DIR__ . '/../../uploads/files/';
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                foreach ($_FILES['files']['tmp_name'] as $key => $tmp_name) {
                    $fileName = $_FILES['files']['name'][$key];
                    $fileType = $_FILES['files']['type'][$key];
                    $fileError = $_FILES['files']['error'][$key];
                    
                    // Check for upload errors
                    if ($fileError !== UPLOAD_ERR_OK || empty($tmp_name)) {
                        error_log("BugController::create - Skipping file $fileName (Error: $fileError)");
                        continue;
                    }
                    
                    $filePath = $uploadDir . uniqid() . '_' . $fileName;
                    
                    if (move_uploaded_file($tmp_name, $filePath)) {
                        error_log("BugController::create - Saved file: $filePath");
                        $attachmentId = Utils::generateUUID();
                        // Store path relative to the 'uploads' directory
                        $relativePath = str_replace(__DIR__ . '/../../uploads/', 'uploads/', $filePath);
                        $stmt = $this->conn->prepare(
                            "INSERT INTO bug_attachments (id, bug_id, file_name, file_path, file_type, uploaded_by) 
                             VALUES (?, ?, ?, ?, ?, ?)"
                        );
                        $bugIdForAttachment = (string)$id; // Ensure string type
                        $stmt->execute([
                            $attachmentId,
                            $bugIdForAttachment,
                            $fileName,
                            $relativePath,
                            $fileType,
                            $decoded->user_id
                        ]);
                        error_log("BugController::create - Saved attachment: bug_id=$bugIdForAttachment, file=$fileName");
                        // Add the relative path to the list
                        $uploadedAttachments[] = $relativePath;
                        @unlink($tmp_name);
                    }
                }
            }

            // Handle voice notes
            error_log("BugController::create - Checking voice_notes: " . (empty($_FILES['voice_notes']) ? 'EMPTY' : 'NOT EMPTY'));
            if (!empty($_FILES['voice_notes'])) {
                $uploadDir = __DIR__ . '/../../uploads/voice_notes/';
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                
                error_log("BugController::create - Voice notes count: " . (is_array($_FILES['voice_notes']['tmp_name']) ? count($_FILES['voice_notes']['tmp_name']) : 'NOT ARRAY'));

                foreach ($_FILES['voice_notes']['tmp_name'] as $key => $tmp_name) {
                    $fileName = $_FILES['voice_notes']['name'][$key];
                    $fileType = $_FILES['voice_notes']['type'][$key];
                    $fileError = $_FILES['voice_notes']['error'][$key];
                    $fileSize = $_FILES['voice_notes']['size'][$key] ?? 0;
                    
                    error_log("BugController::create - Processing voice note [$key]: Name=$fileName, Type=$fileType, Error=$fileError, Size=$fileSize, Tmp=$tmp_name");
                    
                    // Check for upload errors
                    if ($fileError !== UPLOAD_ERR_OK) {
                        error_log("BugController::create - Upload error for voice note $fileName: Code=$fileError (UPLOAD_ERR_OK=" . UPLOAD_ERR_OK . ")");
                        continue;
                    }
                    
                    if (empty($tmp_name) || !is_uploaded_file($tmp_name)) {
                        error_log("BugController::create - Invalid temp file for voice note $fileName: $tmp_name");
                        continue;
                    }
                    
                    $filePath = $uploadDir . uniqid() . '_' . $fileName;
                    
                    if (move_uploaded_file($tmp_name, $filePath)) {
                        error_log("BugController::create - Saved voice note: $filePath");
                        $attachmentId = Utils::generateUUID();
                        // Store path relative to the 'uploads' directory
                        $relativePath = str_replace(__DIR__ . '/../../uploads/', 'uploads/', $filePath);
                        $stmt = $this->conn->prepare(
                            "INSERT INTO bug_attachments (id, bug_id, file_name, file_path, file_type, uploaded_by) 
                             VALUES (?, ?, ?, ?, ?, ?)"
                        );
                        $bugIdForAttachment = (string)$id; // Ensure string type
                        $stmt->execute([
                            $attachmentId,
                            $bugIdForAttachment,
                            $fileName,
                            $relativePath,
                            $fileType,
                            $decoded->user_id
                        ]);
                        error_log("BugController::create - Saved attachment: bug_id=$bugIdForAttachment, file=$fileName");
                        // Add the relative path to the list
                        $uploadedAttachments[] = $relativePath;
                        @unlink($tmp_name);
                    }
                }
            }
            
            $this->conn->commit();
            
            // Fetch attachments from database to include in response
            $attachStmt = $this->conn->prepare("
                SELECT id, file_name, file_path, file_type, uploaded_by, created_at
                FROM bug_attachments
                WHERE bug_id = ?
                ORDER BY created_at ASC
            ");
            $attachStmt->execute([$id]);
            $attachments = $attachStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Process attachments to include full URLs
            $processedAttachments = [];
            foreach ($attachments as $attachment) {
                $path = $attachment['file_path'];
                $fullPath = $this->getFullPath($path);
                $processedAttachments[] = [
                    'id' => $attachment['id'],
                    'file_name' => $attachment['file_name'],
                    'file_path' => $attachment['file_path'],
                    'file_type' => $attachment['file_type'],
                    'uploaded_by' => $attachment['uploaded_by'],
                    'created_at' => $attachment['created_at'],
                    'full_url' => $fullPath
                ];
            }
            
            error_log("BugController::create - Bug created successfully with " . count($processedAttachments) . " attachments: " . json_encode(array_column($processedAttachments, 'file_name')));
            
            // Use the IST time we already calculated for the response
            $bug = [
                'id' => $id,
                'title' => $data['title'],
                'description' => $data['description'],
                'project_id' => $data['project_id'],
                'reported_by' => $decoded->user_id,
                'priority' => $priority,
                'status' => $status,
                'created_at' => $istTimeStr,
                'updated_at' => $istTimeStr,
                'attachments' => $processedAttachments
            ];
            
            // Send response immediately (non-blocking) for faster user experience
            $this->sendJsonResponse(200, "Bug created successfully", [
                'bug' => $bug,
                'uploadedAttachments' => $uploadedAttachments
            ]);
            
            // Send notifications asynchronously (non-blocking) after response is sent
            // This makes the submission feel instant while notifications happen in background
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request(); // Flush response to client immediately
            }
            
            // Now send notifications in background (won't block user)
            // Send notifications to project developers + admins
            error_log("📢 NOTIFICATION: Starting async notification process for bug creation");
            try {
                require_once __DIR__ . '/../NotificationManager.php';
                $notificationManager = NotificationManager::getInstance();
                $notificationManager->notifyBugCreated(
                    $id,
                    $data['title'],
                    $data['project_id'],
                    $decoded->user_id
                );
                error_log("✅ Bug creation notifications sent successfully");
            } catch (Exception $e) {
                error_log("⚠️ Failed to send bug creation notification: " . $e->getMessage());
            }
            
            // Load WhatsApp utils for BugRicer Notify API
            $whatsappPath = __DIR__ . '/../../utils/whatsapp.php';
            require_once $whatsappPath;
            $projectName = getProjectName($this->conn, $data['project_id']);
            $creatorName = null;
            try {
                $stmt = $this->conn->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
                $stmt->execute([$decoded->user_id]);
                $u = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($u) $creatorName = $u['username'];
            } catch (Exception $e) { /* ignore */ }
            $bugUrl = getFrontendBaseUrl() . '/bugs/' . $id;

            // Send email notifications to project developers and admins (if enabled)
            try {
                $emailEnabled = true;
                $settingsStmt = $this->conn->prepare("SELECT value FROM settings WHERE key_name = 'email_notifications_enabled' LIMIT 1");
                if ($settingsStmt && $settingsStmt->execute()) {
                    $row = $settingsStmt->fetch(PDO::FETCH_ASSOC);
                    if ($row && $row['value'] === '0') $emailEnabled = false;
                }
                if ($emailEnabled) {
                    require_once __DIR__ . '/../../utils/send_email.php';
                    $developers = getProjectDevelopers($this->conn, $data['project_id']);
                $admins = getAllAdmins($this->conn);
                $userIds = array_unique(array_merge($developers, $admins));
                $userIds = array_filter($userIds, function($uid) use ($decoded) { return (string)$uid !== (string)$decoded->user_id; });
                if (empty($userIds)) {
                    $userIds = array_values(array_filter(getAllAdmins($this->conn), function($uid) use ($decoded) { return (string)$uid !== (string)$decoded->user_id; }));
                }
                if (empty($userIds)) {
                    $userIds = [$decoded->user_id];
                }
                $placeholders = str_repeat('?,', count($userIds) - 1) . '?';
                $stmt = $this->conn->prepare("SELECT email FROM users WHERE id IN ($placeholders) AND email IS NOT NULL AND email != ''");
                $stmt->execute(array_values($userIds));
                $emails = $stmt->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($emails)) {
                    sendBugCreatedEmail($emails, $id, $data['title'], $projectName, $creatorName ?: 'BugRicer', $priority, $data['description'] ?? null, $expectedResult, $actualResult, $bugUrl);
                    error_log("✅ Bug creation email notifications sent to " . count($emails) . " recipients");
                }
                }
            } catch (Exception $e) {
                error_log("⚠️ Failed to send bug creation email: " . $e->getMessage());
            }

            // Send WhatsApp via BugRicer Notify API to project developers and admins
            try {
                $developers = getProjectDevelopers($this->conn, $data['project_id']);
                $admins = getAllAdmins($this->conn);
                $userIds = array_unique(array_merge($developers, $admins));
                $userIds = array_filter($userIds, function($userId) use ($decoded) {
                    return (string)$userId !== (string)$decoded->user_id;
                });
                if (empty($userIds)) {
                    $userIds = array_filter(getAllAdmins($this->conn), function($userId) use ($decoded) { return (string)$userId !== (string)$decoded->user_id; });
                    if (empty($userIds)) {
                        $userIds = [$decoded->user_id];
                    }
                }
                if (!empty($userIds)) {
                    error_log("📱 Sending WhatsApp (Notify API) for bug creation to " . count($userIds) . " users");
                    sendBugAssignmentWhatsApp(
                        $this->conn,
                        array_values($userIds),
                        $id,
                        $data['title'],
                        $priority,
                        $projectName,
                        $decoded->user_id,
                        $data['description'] ?? null,
                        $expectedResult,
                        $actualResult
                    );
                    error_log("✅ Bug creation WhatsApp notifications sent successfully");
                }
                // Also send to configured admin numbers (ensures admins always receive)
                sendNewBugToAdminNumbers($id, $data['title'], $priority, $projectName, $creatorName, $data['description'] ?? null, $expectedResult, $actualResult);
            } catch (Exception $e) {
                error_log("⚠️ Failed to send bug creation WhatsApp notification: " . $e->getMessage());
                error_log("⚠️ Exception trace: " . $e->getTraceAsString());
            }
            
        } catch (Exception $e) {
            error_log("BugController::create - Exception: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }
    
    public function update($id) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }

        try {
            $decoded = $this->validateToken();
            $data = $this->getRequestData();
            
            $updateFields = [];
            $values = [];
            
            if (isset($data['title'])) {
                $updateFields[] = "title = ?";
                $values[] = $data['title'];
            }
            
            if (isset($data['description'])) {
                $updateFields[] = "description = ?";
                $values[] = $data['description'];
            }
            
            if (isset($data['priority'])) {
                $updateFields[] = "priority = ?";
                $values[] = $data['priority'];
            }
            
            if (isset($data['status'])) {
                $updateFields[] = "status = ?";
                $values[] = $data['status'];
            }
            
            if (isset($data['fix_description'])) {
                $updateFields[] = "fix_description = ?";
                $values[] = $data['fix_description'];
            }
            
            if (isset($data['fixed_by'])) {
                $updateFields[] = "fixed_by = ?";
                $values[] = $data['fixed_by'];
            }
            
            if (empty($updateFields)) {
                $this->sendJsonResponse(400, "No fields to update");
                return;
            }
            
            // Always set updated_at and updated_by when a bug is updated
            $updateFields[] = "updated_at = CURRENT_TIMESTAMP()";
            $updateFields[] = "updated_by = ?";
            $values[] = $decoded->user_id; // Set the current user as the one who updated the bug
            
            $query = "UPDATE bugs SET " . implode(", ", $updateFields) . " WHERE id = ?";
            $values[] = $id;
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute($values);
            
            if ($stmt->rowCount() === 0) {
                $this->sendJsonResponse(404, "Bug not found");
                return;
            }
            
            $this->sendJsonResponse(200, "Bug updated successfully");
            
        } catch (Exception $e) {
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }

    public function delete($id) {
        try {
            $decoded = $this->validateToken();
            
            // First, get the bug to check who reported it and get details for logging
            $checkQuery = "SELECT reported_by, title, project_id FROM bugs WHERE id = :id";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->bindParam(':id', $id);
            $checkStmt->execute();
            $bug = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$bug) {
                $this->sendJsonResponse(404, "Bug not found");
                return;
            }
            
            // Check if user has permission to delete
            // Only admins and the original reporter can delete
            if ($decoded->role !== 'admin' && $decoded->user_id !== $bug['reported_by']) {
                $this->sendJsonResponse(403, "You don't have permission to delete this bug. Only the reporter and admins can delete bugs.");
                return;
            }
            
            $this->conn->beginTransaction();

            // Fetch all attachment file paths for this bug
            $attachmentQuery = "SELECT file_path FROM bug_attachments WHERE bug_id = :id";
            $attachmentStmt = $this->conn->prepare($attachmentQuery);
            $attachmentStmt->bindParam(':id', $id);
            $attachmentStmt->execute();
            $attachments = $attachmentStmt->fetchAll(PDO::FETCH_ASSOC);

            // Delete files from filesystem
            foreach ($attachments as $attachment) {
                $filePath = __DIR__ . '/../../' . $attachment['file_path'];
                if (file_exists(filename: $filePath)) {
                    @unlink($filePath);
                }
            }

            // Delete bug (cascading will handle attachments and dashboard relations)
            $query = "DELETE FROM bugs WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $id);

            if ($stmt->execute()) {
                $this->conn->commit();

                // Log activity
                try {
                    $logger = ActivityLogger::getInstance();
                    $logger->logBugDeleted(
                        $decoded->user_id,
                        $bug['project_id'],
                        $id,
                        $bug['title'],
                        [
                            'attachments_count' => count($attachments),
                            'deleted_by_role' => $decoded->role
                        ]
                    );
                } catch (Exception $e) {
                    error_log("Failed to log bug deletion activity: " . $e->getMessage());
                }

                $this->sendJsonResponse(200, "Bug and attachments deleted successfully");
                return;
            }

            $this->conn->rollBack();
            $this->sendJsonResponse(500, "Failed to delete bug");
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }

    public function getAllBugs($projectId = null, $page = 1, $limit = 10, $status = null, $userId = null) {
        try {
            // Validate token
            $this->validateToken();

            // Validate connection
            if (!$this->conn) {
                throw new Exception("Database connection failed");
            }

            // Create cache key for this query
            $cacheKey = 'bugs_' . ($projectId ?? 'all') . '_' . $page . '_' . $limit . '_' . ($status ?? 'all') . '_' . ($userId ?? 'all');
            $cachedResult = $this->getCache($cacheKey);
            
            if ($cachedResult !== null) {
                return $cachedResult;
            }

            // Optimized query using JOINs to get everything in fewer queries
            $query = "SELECT b.*, 
                     u.username as reporter_name,
                     p.name as project_name
                     FROM bugs b
                     LEFT JOIN users u ON b.reported_by = u.id
                     LEFT JOIN projects p ON b.project_id = p.id";
            
            $countQuery = "SELECT COUNT(*) as total FROM bugs b";
            $params = [];

            // Add project filter if specified
            if ($projectId) {
                $query .= " WHERE b.project_id = ?";
                $countQuery .= " WHERE b.project_id = ?";
                $params[] = $projectId;
            }

            // Add status filter if specified
            if ($status) {
                $query .= " AND b.status = ?";
                $countQuery .= " AND b.status = ?";
                $params[] = $status;
            }

            // Add user filter if specified
            if ($userId) {
                $query .= " AND b.reported_by = ?";
                $countQuery .= " AND b.reported_by = ?";
                $params[] = $userId;
            }

            // Add sorting
            $query .= " ORDER BY b.created_at DESC";

            // Add pagination
            $offset = ($page - 1) * $limit;
            $query .= " LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;

            // Execute both queries using prepared statements with caching
            $countParams = $projectId ? [$projectId] : [];
            $totalBugs = $this->fetchSingleCached($countQuery, $countParams, 'bug_count_' . ($projectId ?? 'all') . '_' . ($status ?? 'all') . '_' . ($userId ?? 'all'), 600)['total'];

            // Execute main query
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            $bugs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Optimized attachment fetching - get all attachments in one query
            if (!empty($bugs)) {
                $bugIds = array_column($bugs, 'id');
                $placeholders = str_repeat('?,', count($bugIds) - 1) . '?';
                
                $attachmentQuery = "SELECT bug_id, id, file_name, file_path, file_type 
                                  FROM bug_attachments 
                                  WHERE bug_id IN ($placeholders)
                                  ORDER BY bug_id, id";
                
                $attachmentStmt = $this->conn->prepare($attachmentQuery);
                $attachmentStmt->execute($bugIds);
                $allAttachments = $attachmentStmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Group attachments by bug_id
                $attachmentsByBug = [];
                foreach ($allAttachments as $attachment) {
                    $attachmentsByBug[$attachment['bug_id']][] = $attachment;
                }
                
                // Assign attachments to bugs
                foreach ($bugs as &$bug) {
                    $bug['attachments'] = $attachmentsByBug[$bug['id']] ?? [];
                }
            }

            $response = [
                'bugs' => $bugs,
                'pagination' => [
                    'currentPage' => $page,
                    'totalPages' => ceil($totalBugs / $limit),
                    'totalBugs' => $totalBugs,
                    'limit' => $limit
                ]
            ];

            // Cache the result for 5 minutes
            $this->setCache($cacheKey, $response, 300);

            return $response;
            
        } catch (PDOException $e) {
            throw new Exception("Failed to retrieve bugs");
        } catch (Exception $e) {
            throw new Exception("An unexpected error occurred");
        }
    }

    public function updateBug($data) {
        try {
            if (empty($data['id'])) {
                throw new Exception("Bug ID is required");
            }

            $this->conn->beginTransaction();

            // Check if bug exists
            $checkStmt = $this->conn->prepare("SELECT id FROM bugs WHERE id = ?");
            $checkStmt->execute([$data['id']]);
            $bugExists = $checkStmt->fetch();
            
            if (!$bugExists) {
                throw new Exception("Bug not found");
            }

            // Build update query
            $updateFields = [];
            $params = [];
            
            if (isset($data['title'])) {
                $updateFields[] = "title = ?";
                $params[] = $data['title'];
            }
            if (isset($data['description'])) {
                $updateFields[] = "description = ?";
                $params[] = $data['description'];
            }
            // Allow updating expected_result if provided (including empty string => NULL)
            if (array_key_exists('expected_result', $data)) {
                $updateFields[] = "expected_result = ?";
                $params[] = ($data['expected_result'] === '' ? null : $data['expected_result']);
            }
            // Allow updating actual_result if provided (including empty string => NULL)
            if (array_key_exists('actual_result', $data)) {
                $updateFields[] = "actual_result = ?";
                $params[] = ($data['actual_result'] === '' ? null : $data['actual_result']);
            }
            if (isset($data['priority'])) {
                $updateFields[] = "priority = ?";
                $params[] = $data['priority'];
            }
            if (isset($data['status'])) {
                $updateFields[] = "status = ?";
                $params[] = $data['status'];
            }
            if (isset($data['fix_description'])) {
                $updateFields[] = "fix_description = ?";
                $params[] = $data['fix_description'];
            }
            if (isset($data['fixed_by'])) {
                $updateFields[] = "fixed_by = ?";
                $params[] = $data['fixed_by'];
            }
            
            // Always include updated_by if it's provided
            if (isset($data['updated_by'])) {
                $updateFields[] = "updated_by = ?";
                $params[] = $data['updated_by'];
            }

            if (empty($updateFields)) {
                throw new Exception("No fields to update");
            }

            // Add updated_at field
            $updateFields[] = "updated_at = CURRENT_TIMESTAMP";

            // Add bug ID to params
            $params[] = $data['id'];

            // Update bug
            $query = "UPDATE bugs SET " . implode(", ", $updateFields) . " WHERE id = ?";
            $stmt = $this->conn->prepare($query);

            if (!$stmt->execute($params)) {
                $error = $stmt->errorInfo();
                throw new Exception("Failed to update bug: " . implode(", ", $error));
            }

            // Get updated bug data with updated_by_name
            // Try the complex JOIN query first, fallback to simple query if it fails
            $updatedBug = null;
            
            try {
                $fetchQuery = "
                    SELECT b.*, 
                           p.name as project_name, 
                           reporter.username as reporter_name,
                           updater.username as updated_by_name,
                           fixer.username as fixed_by_name
                    FROM bugs b
                    LEFT JOIN projects p ON b.project_id COLLATE utf8mb4_unicode_ci = p.id COLLATE utf8mb4_unicode_ci
                    LEFT JOIN users reporter ON b.reported_by COLLATE utf8mb4_unicode_ci = reporter.id COLLATE utf8mb4_unicode_ci
                    LEFT JOIN users updater ON b.updated_by COLLATE utf8mb4_unicode_ci = updater.id COLLATE utf8mb4_unicode_ci
                    LEFT JOIN users fixer ON b.fixed_by COLLATE utf8mb4_unicode_ci = fixer.id COLLATE utf8mb4_unicode_ci
                    WHERE b.id = ?
                ";
                
                $stmt = $this->conn->prepare($fetchQuery);
                $stmt->execute([$data['id']]);
                $updatedBug = $stmt->fetch(PDO::FETCH_ASSOC);
                
            } catch (Exception $joinError) {
                // Fallback to simple query without JOINs
                try {
                    $fallbackQuery = "SELECT * FROM bugs WHERE id = ?";
                    $stmt = $this->conn->prepare($fallbackQuery);
                    $stmt->execute([$data['id']]);
                    $updatedBug = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($updatedBug) {
                        // Add empty values for the missing JOIN fields
                        $updatedBug['project_name'] = null;
                        $updatedBug['reporter_name'] = null;
                        $updatedBug['updated_by_name'] = null;
                        $updatedBug['fixed_by_name'] = null;
                    }
                } catch (Exception $fallbackError) {
                    error_log("Both JOIN and fallback queries failed: " . $fallbackError->getMessage());
                }
            }

            if (!$updatedBug) {
                throw new Exception("Failed to fetch updated bug data");
            }

            $this->conn->commit();

            // Log activity (non-blocking, but synchronous for now)
            try {
                $logger = ActivityLogger::getInstance();
                $logger->logBugUpdated(
                    $data['updated_by'],
                    $data['project_id'] ?? $updatedBug['project_id'],
                    $data['id'],
                    $updatedBug['title'],
                    [
                        'priority' => $data['priority'] ?? $updatedBug['priority'],
                        'status' => $data['status'] ?? $updatedBug['status'],
                        'updated_fields' => array_keys($data)
                    ]
                );
            } catch (Exception $e) {
                error_log("Failed to log bug update activity: " . $e->getMessage());
            }

            // Store notification data for async sending (will be sent after response)
            $updatedBug['_notification_data'] = [
                'status' => $data['status'] ?? null,
                'bug_id' => $data['id'],
                'bug_title' => $updatedBug['title'],
                'project_id' => $updatedBug['project_id'],
                'updated_by' => $data['updated_by'] ?? null
            ];

            return $updatedBug;

        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            throw $e;
        }
    }

    public function updateBugWithAttachments($data, $userId) {
        try {
            // Don't start a new transaction if one is already active
            $startedTransaction = false;
            if (!$this->conn->inTransaction()) {
                $this->conn->beginTransaction();
                $startedTransaction = true;
            }

            // Update the bug fields (without transaction since we're managing it here)
            if (empty($data['id'])) {
                throw new Exception("Bug ID is required");
            }

            // Build update query for bug fields only
            $updateFields = [];
            $params = [];
            
            if (isset($data['title'])) {
                $updateFields[] = "title = ?";
                $params[] = $data['title'];
            }
            if (isset($data['description'])) {
                $updateFields[] = "description = ?";
                $params[] = $data['description'];
            }
            if (array_key_exists('expected_result', $data)) {
                $updateFields[] = "expected_result = ?";
                $params[] = ($data['expected_result'] === '' ? null : $data['expected_result']);
            }
            if (array_key_exists('actual_result', $data)) {
                $updateFields[] = "actual_result = ?";
                $params[] = ($data['actual_result'] === '' ? null : $data['actual_result']);
            }
            if (isset($data['priority'])) {
                $updateFields[] = "priority = ?";
                $params[] = $data['priority'];
            }
            if (isset($data['status'])) {
                $updateFields[] = "status = ?";
                $params[] = $data['status'];
            }
            if (isset($data['updated_by'])) {
                $updateFields[] = "updated_by = ?";
                $params[] = $data['updated_by'];
            }

            if (!empty($updateFields)) {
                $updateFields[] = "updated_at = CURRENT_TIMESTAMP";
                $params[] = $data['id'];
                
                $query = "UPDATE bugs SET " . implode(", ", $updateFields) . " WHERE id = ?";
                $stmt = $this->conn->prepare($query);
                
                if (!$stmt->execute($params)) {
                    throw new Exception("Failed to update bug");
                }
            }

            // Handle attachment deletions
            if (isset($data['attachments_to_delete']) && !empty($data['attachments_to_delete'])) {
                $attachmentsToDelete = json_decode($data['attachments_to_delete'], true);
                if (is_array($attachmentsToDelete)) {
                    foreach ($attachmentsToDelete as $attachmentId) {
                        $stmt = $this->conn->prepare("SELECT file_path FROM bug_attachments WHERE id = ?");
                        $stmt->execute([$attachmentId]);
                        $attachment = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($attachment) {
                            $deleteStmt = $this->conn->prepare("DELETE FROM bug_attachments WHERE id = ?");
                            $deleteStmt->execute([$attachmentId]);
                            
                            $filePath = __DIR__ . '/../../' . $attachment['file_path'];
                            if (file_exists($filePath)) {
                                @unlink($filePath);
                            }
                        }
                    }
                }
            }

            // Handle new screenshots
            if (!empty($_FILES['screenshots'])) {
                error_log("BugController::updateBugWithAttachments - Processing screenshots, count: " . count($_FILES['screenshots']['tmp_name'] ?? []));
                $uploadDir = __DIR__ . '/../../uploads/screenshots/';
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                foreach ($_FILES['screenshots']['tmp_name'] as $key => $tmp_name) {
                    $fileName = $_FILES['screenshots']['name'][$key] ?? 'unknown';
                    $fileType = $_FILES['screenshots']['type'][$key] ?? 'image/jpeg';
                    $fileError = $_FILES['screenshots']['error'][$key] ?? UPLOAD_ERR_NO_FILE;
                    
                    error_log("BugController::updateBugWithAttachments - Screenshot [$key]: name=$fileName, type=$fileType, error=$fileError, tmp=$tmp_name");
                    
                    if ($fileError !== UPLOAD_ERR_OK) {
                        error_log("BugController::updateBugWithAttachments - Upload error for screenshot $fileName: $fileError");
                        continue;
                    }
                    
                    if (empty($tmp_name) || !is_uploaded_file($tmp_name)) {
                        error_log("BugController::updateBugWithAttachments - Invalid temp file for screenshot $fileName");
                        continue;
                    }
                    
                    $filePath = $uploadDir . uniqid() . '_' . $fileName;
                    
                    if (move_uploaded_file($tmp_name, $filePath)) {
                        $attachmentId = $this->generateUUID();
                        $relativePath = str_replace(__DIR__ . '/../../uploads/', 'uploads/', $filePath);
                        $bugIdForAttachment = (string)$data['id']; // Ensure string type
                        $stmt = $this->conn->prepare(
                            "INSERT INTO bug_attachments (id, bug_id, file_name, file_path, file_type, uploaded_by) 
                             VALUES (?, ?, ?, ?, ?, ?)"
                        );
                        $result = $stmt->execute([
                            $attachmentId,
                            $bugIdForAttachment,
                            $fileName,
                            $relativePath,
                            $fileType,
                            $userId
                        ]);
                        $insertId = $this->conn->lastInsertId();
                        error_log("BugController::updateBugWithAttachments - Saved screenshot: bug_id=$bugIdForAttachment, file=$fileName, attachment_id=$attachmentId, insert_id=$insertId, success=" . ($result ? 'yes' : 'no'));
                        if (!$result) {
                            $errorInfo = $stmt->errorInfo();
                            error_log("BugController::updateBugWithAttachments - SQL Error: " . json_encode($errorInfo));
                        }
                    } else {
                        error_log("BugController::updateBugWithAttachments - Failed to move uploaded file: $tmp_name to $filePath");
                    }
                }
            } else {
                error_log("BugController::updateBugWithAttachments - No screenshots in \$_FILES");
            }

            // Handle new files
            if (!empty($_FILES['files'])) {
                $uploadDir = __DIR__ . '/../../uploads/files/';
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                foreach ($_FILES['files']['tmp_name'] as $key => $tmp_name) {
                    $fileName = $_FILES['files']['name'][$key];
                    $fileType = $_FILES['files']['type'][$key];
                    $filePath = $uploadDir . uniqid() . '_' . $fileName;
                    
                    if (move_uploaded_file($tmp_name, $filePath)) {
                        $attachmentId = $this->generateUUID();
                        $relativePath = str_replace(__DIR__ . '/../../uploads/', 'uploads/', $filePath);
                        $bugIdForAttachment = (string)$data['id']; // Ensure string type
                        $stmt = $this->conn->prepare(
                            "INSERT INTO bug_attachments (id, bug_id, file_name, file_path, file_type, uploaded_by) 
                             VALUES (?, ?, ?, ?, ?, ?)"
                        );
                        $result = $stmt->execute([
                            $attachmentId,
                            $bugIdForAttachment,
                            $fileName,
                            $relativePath,
                            $fileType,
                            $userId
                        ]);
                        error_log("BugController::updateBugWithAttachments - Saved file: bug_id=$bugIdForAttachment, file=$fileName, success=" . ($result ? 'yes' : 'no'));
                    }
                }
            }

            // Handle new voice notes
            if (!empty($_FILES['voice_notes'])) {
                error_log("BugController::updateBugWithAttachments - Processing voice notes, count: " . count($_FILES['voice_notes']['tmp_name'] ?? []));
                $uploadDir = __DIR__ . '/../../uploads/voice_notes/';
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                foreach ($_FILES['voice_notes']['tmp_name'] as $key => $tmp_name) {
                    $fileName = $_FILES['voice_notes']['name'][$key] ?? 'unknown';
                    $fileType = $_FILES['voice_notes']['type'][$key] ?? 'audio/webm';
                    $fileError = $_FILES['voice_notes']['error'][$key] ?? UPLOAD_ERR_NO_FILE;
                    
                    error_log("BugController::updateBugWithAttachments - Voice note [$key]: name=$fileName, type=$fileType, error=$fileError, tmp=$tmp_name");
                    
                    if ($fileError !== UPLOAD_ERR_OK) {
                        error_log("BugController::updateBugWithAttachments - Upload error for voice note $fileName: $fileError");
                        continue;
                    }
                    
                    if (empty($tmp_name) || !is_uploaded_file($tmp_name)) {
                        error_log("BugController::updateBugWithAttachments - Invalid temp file for voice note $fileName");
                        continue;
                    }
                    
                    $filePath = $uploadDir . uniqid() . '_' . $fileName;
                    
                    if (move_uploaded_file($tmp_name, $filePath)) {
                        $attachmentId = $this->generateUUID();
                        $relativePath = str_replace(__DIR__ . '/../../uploads/', 'uploads/', $filePath);
                        $bugIdForAttachment = (string)$data['id']; // Ensure string type
                        $stmt = $this->conn->prepare(
                            "INSERT INTO bug_attachments (id, bug_id, file_name, file_path, file_type, uploaded_by) 
                             VALUES (?, ?, ?, ?, ?, ?)"
                        );
                        $result = $stmt->execute([
                            $attachmentId,
                            $bugIdForAttachment,
                            $fileName,
                            $relativePath,
                            $fileType,
                            $userId
                        ]);
                        $insertId = $this->conn->lastInsertId();
                        error_log("BugController::updateBugWithAttachments - Saved voice note: bug_id=$bugIdForAttachment, file=$fileName, attachment_id=$attachmentId, insert_id=$insertId, success=" . ($result ? 'yes' : 'no'));
                        if (!$result) {
                            $errorInfo = $stmt->errorInfo();
                            error_log("BugController::updateBugWithAttachments - SQL Error: " . json_encode($errorInfo));
                        }
                    } else {
                        error_log("BugController::updateBugWithAttachments - Failed to move uploaded file: $tmp_name to $filePath");
                    }
                }
            } else {
                error_log("BugController::updateBugWithAttachments - No voice notes in \$_FILES");
            }

            // Get updated bug data with attachments (same format as getById)
            $bugIdForQuery = (string)$data['id']; // Ensure string type for consistent querying
            $stmt = $this->conn->prepare("
                SELECT b.*, 
                       p.name as project_name,
                       reporter.username as reporter_name,
                       updater.username as updated_by_name,
                       fixer.username as fixed_by_name
                FROM bugs b
                LEFT JOIN projects p ON b.project_id = p.id
                LEFT JOIN users reporter ON b.reported_by = reporter.id
                LEFT JOIN users updater ON b.updated_by = updater.id
                LEFT JOIN users fixer ON b.fixed_by = fixer.id
                WHERE CAST(b.id AS CHAR) = CAST(? AS CHAR)
            ");
            $stmt->execute([$bugIdForQuery]);
            $updatedBug = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$updatedBug) {
                throw new Exception("Failed to retrieve updated bug");
            }

            // Get attachments (same logic as getById) - use CAST for type safety
            $attachStmt = $this->conn->prepare("
                SELECT id, file_name, file_path, file_type, uploaded_by, created_at
                FROM bug_attachments
                WHERE CAST(bug_id AS CHAR) = CAST(? AS CHAR)
                ORDER BY created_at ASC
            ");
            $attachStmt->execute([$bugIdForQuery]);
            $attachments = $attachStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Debug logging
            error_log("BugController::updateBugWithAttachments - After update, Bug ID: $bugIdForQuery, Attachments found: " . count($attachments));
            if (count($attachments) > 0) {
                error_log("BugController::updateBugWithAttachments - Sample attachment: " . json_encode($attachments[0]));
            }

            // Process attachments and add them to the bug object (same format as getById)
            $updatedBug['attachments'] = [];
            $updatedBug['screenshots'] = [];
            $updatedBug['files'] = [];
            
            foreach ($attachments as $attachment) {
                $path = $attachment['file_path'];
                $fullPath = $this->getFullPath($path);
                
                // Create attachment object for frontend
                $attachmentObj = [
                    'id' => $attachment['id'],
                    'file_name' => $attachment['file_name'],
                    'file_path' => $attachment['file_path'],
                    'file_type' => $attachment['file_type'],
                    'uploaded_by' => $attachment['uploaded_by'],
                    'created_at' => $attachment['created_at']
                ];
                
                $updatedBug['attachments'][] = $attachmentObj;
                
                // Also categorize them for backward compatibility
                if (strpos($attachment['file_type'], 'image/') === 0 || 
                    preg_match('/\.(jpg|jpeg|png|gif|webp|bmp|svg)$/i', $attachment['file_name'])) {
                    $updatedBug['screenshots'][] = [
                        'id' => $attachment['id'],
                        'name' => $attachment['file_name'],
                        'path' => $fullPath,
                        'type' => $attachment['file_type']
                    ];
                } else if (strpos($attachment['file_type'], 'audio/') === 0 || 
                          preg_match('/\.(wav|mp3|m4a|ogg|webm)$/i', $attachment['file_name'])) {
                    // Voice notes go into files array
                    $updatedBug['files'][] = [
                        'id' => $attachment['id'],
                        'name' => $attachment['file_name'],
                        'path' => $fullPath,
                        'type' => $attachment['file_type']
                    ];
                } else {
                    $updatedBug['files'][] = [
                        'id' => $attachment['id'],
                        'name' => $attachment['file_name'],
                        'path' => $fullPath,
                        'type' => $attachment['file_type']
                    ];
                }
            }

            // Commit if we started the transaction
            if ($startedTransaction) {
                $this->conn->commit();
            }

            // Log activity (non-blocking, but synchronous for now)
            try {
                $logger = ActivityLogger::getInstance();
                $logger->logBugUpdated(
                    $data['updated_by'] ?? $userId,
                    $data['project_id'],
                    $data['id'],
                    $data['title'] ?? $updatedBug['title'] ?? 'Bug',
                    [
                        'priority' => $data['priority'] ?? null,
                        'status' => $data['status'] ?? null,
                    ]
                );
            } catch (Exception $e) {
                error_log("Failed to log bug update activity: " . $e->getMessage());
            }

            // Store notification data for async sending (will be sent after response)
            $updatedBug['_notification_data'] = [
                'status' => $data['status'] ?? null,
                'bug_id' => $data['id'],
                'bug_title' => $updatedBug['title'],
                'project_id' => $updatedBug['project_id'],
                'updated_by' => $data['updated_by'] ?? $userId
            ];

            return $updatedBug;

        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            throw $e;
        }
    }

    function convertToWebP($sourcePath, $destinationPath, $quality = 80) {
        $info = getimagesize($sourcePath);
        if (!$info) return false;

        switch ($info['mime']) {
            case 'image/jpeg':
                $image = imagecreatefromjpeg($sourcePath);
                break;
            case 'image/png':
                $image = imagecreatefrompng($sourcePath);
                // For PNG, preserve transparency
                imagepalettetotruecolor($image);
                imagealphablending($image, true);
                imagesavealpha($image, true);
                break;
            case 'image/gif':
                $image = imagecreatefromgif($sourcePath);
                break;
            default:
                return false; // Not a supported image
        }

        // Convert and compress to WebP
        $result = imagewebp($image, $destinationPath, $quality);
        imagedestroy($image);
        return $result;
    }

    /**
     * Get basic bug information
     * 
     * @param string $id Bug ID
     * @return array|false Basic bug info or false if not found
     */
    public function getBugBasicInfo($id) {
        try {
            $stmt = $this->conn->prepare("SELECT id, project_id FROM bugs WHERE id = ?");
            $stmt->execute([$id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return false;
        }
    }
}