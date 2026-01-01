<?php
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../ActivityLogger.php';
require_once __DIR__ . '/../projects/ProjectMemberController.php';
class UpdateController extends BaseAPI
{
    public function create()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }
        try {
            // CRITICAL: Check for files FIRST, before any other processing
            error_log("UpdateController::create - ===== REQUEST START =====");
            error_log("UpdateController::create - REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD']);
            error_log("UpdateController::create - CONTENT_TYPE: " . ($_SERVER['CONTENT_TYPE'] ?? 'NOT SET'));
            error_log("UpdateController::create - Has \$_FILES: " . (!empty($_FILES) ? 'YES (' . count($_FILES) . ' keys)' : 'NO'));
            error_log("UpdateController::create - Has \$_POST: " . (!empty($_POST) ? 'YES (' . count($_POST) . ' keys)' : 'NO'));
            if (!empty($_FILES)) {
                error_log("UpdateController::create - \$_FILES keys: " . json_encode(array_keys($_FILES)));
                foreach ($_FILES as $key => $file) {
                    if (is_array($file['name'])) {
                        error_log("UpdateController::create -   \$_FILES['$key']: " . count($file['name']) . " files");
                    } else {
                        error_log("UpdateController::create -   \$_FILES['$key']: single file");
                    }
                }
            }
            
            $decoded = $this->validateToken();
            if (!$decoded || !isset($decoded->user_id)) {
                $this->sendJsonResponse(401, "Unauthorized: Invalid or missing token");
                return;
            }
            // Handle both JSON and FormData requests
            $contentType = isset($_SERVER["CONTENT_TYPE"]) ? trim($_SERVER["CONTENT_TYPE"]) : '';
            
            // For multipart/form-data (FormData), use $_POST directly
            // FormData sends as multipart/form-data with boundary
            if (stripos($contentType, 'multipart/form-data') !== false || !empty($_FILES)) {
                $data = $_POST;
            } else {
                $data = $this->getRequestData();
            }
            
            // Fallback: if data is empty but $_POST has data, use $_POST
            if (empty($data) && !empty($_POST)) {
                $data = $_POST;
            }
            
            // Debug logging
            error_log("UpdateController::create - Content-Type: " . $contentType);
            error_log("UpdateController::create - Has FILES: " . (!empty($_FILES) ? 'yes' : 'no'));
            error_log("UpdateController::create - POST keys: " . json_encode(array_keys($_POST ?? [])));
            error_log("UpdateController::create - Data keys: " . json_encode(array_keys($data ?? [])));
            error_log("UpdateController::create - FILES keys: " . json_encode(array_keys($_FILES ?? [])));
            if (!empty($_FILES)) {
                foreach ($_FILES as $key => $file) {
                    if (is_array($file['name'])) {
                        error_log("UpdateController::create - $key: " . count($file['name']) . " files");
                    } else {
                        error_log("UpdateController::create - $key: single file - " . ($file['name'] ?? 'no name'));
                    }
                }
            }

            if (
                !isset($data['title'], $data['type'], $data['description'], $data['project_id'])
                || empty($data['title']) || empty($data['type']) || empty($data['description']) || empty($data['project_id'])
            ) {
                error_log("UpdateController::create - Missing required fields. Data keys: " . json_encode(array_keys($data ?? [])));
                $this->sendJsonResponse(400, "All fields are required: title, type, description, project_id");
                return;
            }

            $userId = $decoded->user_id;
            $projectId = $data['project_id'];
            $expectedDate = $data['expected_date'] ?? null;
            $expectedTime = $data['expected_time'] ?? null;

            // Use ProjectMemberController for access check (admins, testers, developers assigned)
            $pmc = new ProjectMemberController();
            if (!$pmc->hasProjectAccess($userId, $projectId)) {
                $this->sendJsonResponse(403, 'You are not a member of this project');
                return;
            }

            // Check if Utils class is available
            if (!class_exists('Utils') || !method_exists('Utils', 'generateUUID')) {
                error_log("UpdateController::create - Utils class or generateUUID method not found");
                $this->sendJsonResponse(500, "System error: Utils class not available");
                return;
            }
            
            // Start transaction for atomicity (like BugController)
            $this->conn->beginTransaction();
            
            $id = Utils::generateUUID();
            
            // Build INSERT query with optional fields
            $fields = ['id', 'project_id', 'title', 'type', 'description', 'created_by'];
            $values = [$id, $projectId, $data['title'], $data['type'], $data['description'], $userId];
            $placeholders = ['?', '?', '?', '?', '?', '?'];
            
            if ($expectedDate) {
                $fields[] = 'expected_date';
                $values[] = $expectedDate;
                $placeholders[] = '?';
            }
            
            if ($expectedTime) {
                $fields[] = 'expected_time';
                $values[] = $expectedTime;
                $placeholders[] = '?';
            }
            
            $sql = "INSERT INTO updates (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
            error_log("UpdateController::create - SQL: " . $sql);
            error_log("UpdateController::create - Values: " . json_encode($values));
            
            try {
                $stmt = $this->conn->prepare($sql);
                if (!$stmt) {
                    $errorInfo = $this->conn->errorInfo();
                    error_log("UpdateController::create - Prepare failed: " . json_encode($errorInfo));
                    $this->conn->rollBack();
                    $this->sendJsonResponse(500, "Database prepare error: " . ($errorInfo[2] ?? 'Unknown error'));
                    return;
                }
                $success = $stmt->execute($values);
                if (!$success) {
                    $errorInfo = $stmt->errorInfo();
                    error_log("UpdateController::create - Execute failed: " . json_encode($errorInfo));
                    $this->conn->rollBack();
                    $this->sendJsonResponse(500, "Database execute error: " . ($errorInfo[2] ?? 'Unknown error'));
                    return;
                }
            } catch (PDOException $e) {
                error_log("UpdateController::create - PDO Exception: " . $e->getMessage());
                error_log("UpdateController::create - SQL: " . $sql);
                $this->conn->rollBack();
                $this->sendJsonResponse(500, "Database error: " . $e->getMessage());
                return;
            }
            
            // Initialize uploaded attachments array
            $uploadedAttachments = [];

            // CRITICAL: Process files BEFORE committing transaction
            error_log("UpdateController::create - ===== FILE UPLOAD PROCESSING START =====");
            error_log("UpdateController::create - Update ID: $id");
            error_log("UpdateController::create - REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD']);
            error_log("UpdateController::create - CONTENT_TYPE: " . ($_SERVER['CONTENT_TYPE'] ?? 'NOT SET'));
            error_log("UpdateController::create - Has \$_FILES: " . (!empty($_FILES) ? 'YES (' . count($_FILES) . ' keys)' : 'NO'));
            
            if (!empty($_FILES)) {
                error_log("UpdateController::create - ✅ FILES RECEIVED! Keys: " . json_encode(array_keys($_FILES)));
                foreach ($_FILES as $key => $file) {
                    if (is_array($file['name'])) {
                        error_log("UpdateController::create -   $key: " . count($file['name']) . " files");
                    } else {
                        error_log("UpdateController::create -   $key: single file - " . ($file['name'] ?? 'no name'));
                    }
                }
            } else {
                error_log("UpdateController::create - ❌ NO FILES IN \$_FILES!");
            }

            // Process files regardless - they should be saved if update was created
            if ($success) {
                // Handle file uploads (screenshots, files, voice notes)
                // Utils class is already available via BaseAPI (from config/utils.php)
                error_log("UpdateController::create - Starting file upload processing...");
                
                // Handle screenshots
                error_log("UpdateController::create - Checking screenshots: " . (empty($_FILES['screenshots']) ? 'EMPTY' : 'NOT EMPTY'));
                if (!empty($_FILES['screenshots'])) {
                    error_log("UpdateController::create - Processing screenshots");
                    $uploadDir = __DIR__ . '/../../uploads/screenshots/';
                    if (!file_exists($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }

                    error_log("UpdateController::create - Screenshots count: " . (is_array($_FILES['screenshots']['tmp_name']) ? count($_FILES['screenshots']['tmp_name']) : 'NOT ARRAY'));

                    foreach ($_FILES['screenshots']['tmp_name'] as $key => $tmp_name) {
                        $fileName = $_FILES['screenshots']['name'][$key] ?? 'unknown';
                        $fileType = $_FILES['screenshots']['type'][$key] ?? 'image/jpeg';
                        $fileError = $_FILES['screenshots']['error'][$key] ?? UPLOAD_ERR_NO_FILE;
                        
                        error_log("UpdateController::create - Processing screenshot [$key]: Name=$fileName, Type=$fileType, Error=$fileError, Tmp=$tmp_name");
                        
                        // Check for upload errors
                        if ($fileError !== UPLOAD_ERR_OK) {
                            error_log("UpdateController::create - Upload error for screenshot $fileName: Code=$fileError");
                            continue;
                        }
                        
                        if (empty($tmp_name) || !is_uploaded_file($tmp_name)) {
                            error_log("UpdateController::create - Invalid temp file for screenshot $fileName: $tmp_name");
                            continue;
                        }
                        
                        $filePath = $uploadDir . uniqid() . '_' . $fileName;
                        
                        if (move_uploaded_file($tmp_name, $filePath)) {
                            error_log("UpdateController::create - ✅ File moved successfully: $filePath");
                            $attachmentId = Utils::generateUUID();
                            $relativePath = str_replace(__DIR__ . '/../../uploads/', 'uploads/', $filePath);
                            
                            // Verify file exists before inserting
                            if (!file_exists($filePath)) {
                                error_log("UpdateController::create - ❌ File doesn't exist after move: $filePath");
                                continue;
                            }
                            
                            error_log("UpdateController::create - Inserting screenshot: ID=$attachmentId, Update=$id, File=$fileName, Path=$relativePath, User=$userId");
                            $stmt = $this->conn->prepare(
                                "INSERT INTO update_attachments (id, update_id, file_name, file_path, file_type, uploaded_by) 
                                 VALUES (?, ?, ?, ?, 'screenshot', ?)"
                            );
                            
                            if (!$stmt) {
                                $errorInfo = $this->conn->errorInfo();
                                error_log("UpdateController::create - ❌ Prepare failed: " . json_encode($errorInfo));
                                continue;
                            }
                            
                            $result = $stmt->execute([$attachmentId, $id, $fileName, $relativePath, $userId]);
                            if ($result) {
                                $insertedId = $this->conn->lastInsertId();
                                error_log("UpdateController::create - ✅ Screenshot saved to DB: $fileName (Attachment ID: $attachmentId, Insert ID: $insertedId, Update ID: $id)");
                                $uploadedAttachments[] = $relativePath;
                            } else {
                                $errorInfo = $stmt->errorInfo();
                                error_log("UpdateController::create - ❌ Execute failed: " . json_encode($errorInfo));
                                error_log("UpdateController::create - SQL State: " . ($errorInfo[0] ?? 'unknown'));
                                error_log("UpdateController::create - Error Code: " . ($errorInfo[1] ?? 'unknown'));
                                error_log("UpdateController::create - Error Message: " . ($errorInfo[2] ?? 'unknown'));
                            }
                        } else {
                            error_log("UpdateController::create - ❌ Failed to move uploaded screenshot: $tmp_name to $filePath");
                            error_log("UpdateController::create - Temp file exists: " . (file_exists($tmp_name) ? 'YES' : 'NO'));
                            error_log("UpdateController::create - Upload dir writable: " . (is_writable($uploadDir) ? 'YES' : 'NO'));
                        }
                    }
                } else {
                    error_log("UpdateController::create - No screenshots in \$_FILES");
                }

                // Handle files/attachments
                error_log("UpdateController::create - Checking files: " . (empty($_FILES['files']) ? 'EMPTY' : 'NOT EMPTY'));
                if (!empty($_FILES['files'])) {
                    error_log("UpdateController::create - Processing files");
                    $uploadDir = __DIR__ . '/../../uploads/files/';
                    if (!file_exists($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }

                    error_log("UpdateController::create - Files count: " . (is_array($_FILES['files']['tmp_name']) ? count($_FILES['files']['tmp_name']) : 'NOT ARRAY'));

                    foreach ($_FILES['files']['tmp_name'] as $key => $tmp_name) {
                        $fileName = $_FILES['files']['name'][$key] ?? 'unknown';
                        $fileType = $_FILES['files']['type'][$key] ?? 'application/octet-stream';
                        $fileError = $_FILES['files']['error'][$key] ?? UPLOAD_ERR_NO_FILE;
                        $fileSize = $_FILES['files']['size'][$key] ?? 0;
                        
                        error_log("UpdateController::create - Processing file [$key]: Name=$fileName, Type=$fileType, Error=$fileError, Size=$fileSize, Tmp=$tmp_name");
                        
                        // Check for upload errors
                        if ($fileError !== UPLOAD_ERR_OK) {
                            error_log("UpdateController::create - Upload error for file $fileName: Code=$fileError");
                            continue;
                        }
                        
                        if (empty($tmp_name) || !is_uploaded_file($tmp_name)) {
                            error_log("UpdateController::create - Invalid temp file for file $fileName: $tmp_name");
                            continue;
                        }
                        
                        $filePath = $uploadDir . uniqid() . '_' . $fileName;
                        
                        if (move_uploaded_file($tmp_name, $filePath)) {
                            error_log("UpdateController::create - ✅ File moved successfully: $filePath");
                            $attachmentId = Utils::generateUUID();
                            $relativePath = str_replace(__DIR__ . '/../../uploads/', 'uploads/', $filePath);
                            
                            // Verify file exists before inserting
                            if (!file_exists($filePath)) {
                                error_log("UpdateController::create - ❌ File doesn't exist after move: $filePath");
                                continue;
                            }
                            
                            error_log("UpdateController::create - Inserting file: ID=$attachmentId, Update=$id, File=$fileName, Size=$fileSize, Path=$relativePath, User=$userId");
                            $stmt = $this->conn->prepare(
                                "INSERT INTO update_attachments (id, update_id, file_name, file_path, file_type, file_size, uploaded_by) 
                                 VALUES (?, ?, ?, ?, 'attachment', ?, ?)"
                            );
                            
                            if (!$stmt) {
                                $errorInfo = $this->conn->errorInfo();
                                error_log("UpdateController::create - ❌ Prepare failed: " . json_encode($errorInfo));
                                continue;
                            }
                            
                            $result = $stmt->execute([$attachmentId, $id, $fileName, $relativePath, $fileSize, $userId]);
                            if ($result) {
                                $insertedId = $this->conn->lastInsertId();
                                error_log("UpdateController::create - ✅ File saved to DB: $fileName (Attachment ID: $attachmentId, Insert ID: $insertedId, Update ID: $id)");
                                $uploadedAttachments[] = $relativePath;
                            } else {
                                $errorInfo = $stmt->errorInfo();
                                error_log("UpdateController::create - ❌ Execute failed: " . json_encode($errorInfo));
                                error_log("UpdateController::create - SQL State: " . ($errorInfo[0] ?? 'unknown'));
                                error_log("UpdateController::create - Error Code: " . ($errorInfo[1] ?? 'unknown'));
                                error_log("UpdateController::create - Error Message: " . ($errorInfo[2] ?? 'unknown'));
                            }
                        } else {
                            error_log("UpdateController::create - ❌ Failed to move uploaded file: $tmp_name to $filePath");
                            error_log("UpdateController::create - Temp file exists: " . (file_exists($tmp_name) ? 'YES' : 'NO'));
                            error_log("UpdateController::create - Upload dir writable: " . (is_writable($uploadDir) ? 'YES' : 'NO'));
                        }
                    }
                } else {
                    error_log("UpdateController::create - No files in \$_FILES");
                }

                // Handle voice notes
                error_log("UpdateController::create - Checking voice_notes: " . (empty($_FILES['voice_notes']) ? 'EMPTY' : 'NOT EMPTY'));
                if (!empty($_FILES['voice_notes'])) {
                    error_log("UpdateController::create - Processing voice notes");
                    $uploadDir = __DIR__ . '/../../uploads/voice_notes/';
                    if (!file_exists($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }

                    error_log("UpdateController::create - Voice notes count: " . (is_array($_FILES['voice_notes']['tmp_name']) ? count($_FILES['voice_notes']['tmp_name']) : 'NOT ARRAY'));

                    foreach ($_FILES['voice_notes']['tmp_name'] as $key => $tmp_name) {
                        $fileName = $_FILES['voice_notes']['name'][$key] ?? 'unknown';
                        $fileType = $_FILES['voice_notes']['type'][$key] ?? 'audio/webm';
                        $fileError = $_FILES['voice_notes']['error'][$key] ?? UPLOAD_ERR_NO_FILE;
                        $fileSize = $_FILES['voice_notes']['size'][$key] ?? 0;
                        
                        error_log("UpdateController::create - Processing voice note [$key]: Name=$fileName, Type=$fileType, Error=$fileError, Size=$fileSize, Tmp=$tmp_name");
                        
                        // Check for upload errors
                        if ($fileError !== UPLOAD_ERR_OK) {
                            error_log("UpdateController::create - Upload error for voice note $fileName: Code=$fileError (UPLOAD_ERR_OK=" . UPLOAD_ERR_OK . ")");
                            continue;
                        }
                        
                        if (empty($tmp_name) || !is_uploaded_file($tmp_name)) {
                            error_log("UpdateController::create - Invalid temp file for voice note $fileName: $tmp_name");
                            continue;
                        }
                        
                        // Duration can be a float (e.g., 5.1435 seconds), convert to int for storage (seconds)
                        $duration = isset($data["voice_note_duration_$key"]) ? (int)round((float)$data["voice_note_duration_$key"]) : null;
                        $filePath = $uploadDir . uniqid() . '_' . $fileName;
                        
                        if (move_uploaded_file($tmp_name, $filePath)) {
                            error_log("UpdateController::create - ✅ Voice note file moved successfully: $filePath");
                            $attachmentId = Utils::generateUUID();
                            $relativePath = str_replace(__DIR__ . '/../../uploads/', 'uploads/', $filePath);
                            
                            // Verify file exists before inserting
                            if (!file_exists($filePath)) {
                                error_log("UpdateController::create - ❌ File doesn't exist after move: $filePath");
                                continue;
                            }
                            
                            error_log("UpdateController::create - Inserting voice note: ID=$attachmentId, Update=$id, File=$fileName, Size=$fileSize, Duration=$duration, Path=$relativePath, User=$userId");
                            $stmt = $this->conn->prepare(
                                "INSERT INTO update_attachments (id, update_id, file_name, file_path, file_type, file_size, duration, uploaded_by) 
                                 VALUES (?, ?, ?, ?, 'voice_note', ?, ?, ?)"
                            );
                            
                            if (!$stmt) {
                                $errorInfo = $this->conn->errorInfo();
                                error_log("UpdateController::create - ❌ Prepare failed: " . json_encode($errorInfo));
                                continue;
                            }
                            
                            $result = $stmt->execute([$attachmentId, $id, $fileName, $relativePath, $fileSize, $duration, $userId]);
                            if ($result) {
                                $insertedId = $this->conn->lastInsertId();
                                error_log("UpdateController::create - ✅ Voice note saved to DB: $fileName (Attachment ID: $attachmentId, Insert ID: $insertedId, Update ID: $id, Duration: $duration)");
                                $uploadedAttachments[] = $relativePath;
                            } else {
                                $errorInfo = $stmt->errorInfo();
                                error_log("UpdateController::create - ❌ Execute failed: " . json_encode($errorInfo));
                                error_log("UpdateController::create - SQL State: " . ($errorInfo[0] ?? 'unknown'));
                                error_log("UpdateController::create - Error Code: " . ($errorInfo[1] ?? 'unknown'));
                                error_log("UpdateController::create - Error Message: " . ($errorInfo[2] ?? 'unknown'));
                            }
                        } else {
                            error_log("UpdateController::create - ❌ Failed to move uploaded voice note: $tmp_name to $filePath");
                            error_log("UpdateController::create - Temp file exists: " . (file_exists($tmp_name) ? 'YES' : 'NO'));
                            error_log("UpdateController::create - Upload dir writable: " . (is_writable($uploadDir) ? 'YES' : 'NO'));
                        }
                    }
                } else {
                    error_log("UpdateController::create - No voice notes in \$_FILES");
                }
                
                // Log update creation activity
                try {
                    $logger = ActivityLogger::getInstance();
                    $logger->logUpdateCreated(
                        $userId,
                        $projectId,
                        $id,
                        $data['title'],
                        [
                            'type' => $data['type'],
                            'description' => substr($data['description'], 0, 100) . '...',
                            'attachments_count' => count($uploadedAttachments)
                        ]
                    );
                } catch (Exception $e) {
                    error_log("Failed to log update creation activity: " . $e->getMessage());
                }
                
                // Also fetch creator username for convenience
                $username = null;
                try {
                    $stmtUser = $this->conn->prepare("SELECT username FROM users WHERE id = ?");
                    $stmtUser->execute([$userId]);
                    $username = $stmtUser->fetchColumn();
                } catch (Exception $e) {}

                // Commit transaction AFTER all files are processed
                try {
                    $this->conn->commit();
                    error_log("UpdateController::create - ✅ Transaction committed successfully");
                } catch (PDOException $e) {
                    error_log("UpdateController::create - ❌ Transaction commit failed: " . $e->getMessage());
                    $this->conn->rollBack();
                    $this->sendJsonResponse(500, "Failed to save update: " . $e->getMessage());
                    return;
                }
                
                // Log final attachment status
                error_log("UpdateController::create - ===== FILE UPLOAD PROCESSING END =====");
                error_log("UpdateController::create - Total attachments saved: " . count($uploadedAttachments));
                if (count($uploadedAttachments) > 0) {
                    error_log("UpdateController::create - ✅ SUCCESS: " . count($uploadedAttachments) . " attachments saved");
                } else {
                    error_log("UpdateController::create - ⚠️ WARNING: NO ATTACHMENTS SAVED!");
                    if (!empty($_FILES)) {
                        error_log("UpdateController::create - ⚠️ Files were received but not saved - check processing code above!");
                    }
                }
                
                // Send response immediately (non-blocking) for faster user experience
                $this->sendJsonResponse(201, "Update created successfully", [
                    'id' => $id,
                    'title' => $data['title'],
                    'type' => $data['type'],
                    'description' => $data['description'],
                    'expected_date' => $expectedDate,
                    'expected_time' => $expectedTime,
                    'created_by_id' => $userId,
                    'created_by' => $username ?? $userId,
                    'created_at' => date('Y-m-d H:i:s'),
                    'attachments_count' => count($uploadedAttachments)
                ]);
                
                // Send notifications asynchronously (non-blocking) after response is sent
                // This makes the submission feel instant while notifications happen in background
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request(); // Flush response to client immediately
                }
                
                // Now send notifications in background (won't block user)
                // Send notifications to admins + testers + developers of project
                error_log("📢 NOTIFICATION: Starting async notification process for update creation");
                try {
                    require_once __DIR__ . '/../NotificationManager.php';
                    $notificationManager = NotificationManager::getInstance();
                    $notificationManager->notifyUpdateCreated(
                        $id,
                        $data['title'],
                        $projectId,
                        $userId
                    );
                    error_log("✅ Update creation notifications sent successfully");
                } catch (Exception $e) {
                    error_log("⚠️ Failed to send update creation notification: " . $e->getMessage());
                }
                
                // Send WhatsApp notifications to project developers and admins
                try {
                    $whatsappPath = __DIR__ . '/../../utils/whatsapp.php';
                    require_once $whatsappPath;
                    
                    error_log("📱 Sending WhatsApp notifications for update creation to developers and admins");
                    
                    sendUpdateCreationWhatsApp(
                        $this->conn,
                        $id,
                        $data['title'],
                        $data['type'],
                        $projectId,
                        $userId
                    );
                    error_log("✅ Update creation WhatsApp notifications sent successfully");
                } catch (Exception $e) {
                    // Don't fail update creation if WhatsApp fails
                    error_log("⚠️ Failed to send update creation WhatsApp notification: " . $e->getMessage());
                    error_log("⚠️ Exception trace: " . $e->getTraceAsString());
                }
            } else {
                error_log("UpdateController::create - Failed to insert update into database");
                if ($this->conn->inTransaction()) {
                    $this->conn->rollBack();
                }
                $this->sendJsonResponse(500, "Failed to create update");
            }
        } catch (Exception $e) {
            error_log("UpdateController::create - Exception: " . $e->getMessage());
            error_log("UpdateController::create - Stack trace: " . $e->getTraceAsString());
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }

    public function getById($id)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }
        try {
            $decoded = $this->validateToken();
            if (!$decoded || !isset($decoded->user_id)) {
                $this->sendJsonResponse(401, "Unauthorized: Invalid or missing token");
                return;
            }
            $userId = $decoded->user_id;
            $userRole = $decoded->role;
            // Fetch update to get project_id
            $stmt = $this->conn->prepare(
                "SELECT u.*, us.username as created_by_name, p.name as project_name
                 FROM updates u
                 LEFT JOIN users us ON u.created_by = us.id
                 LEFT JOIN projects p ON u.project_id = p.id
                 WHERE u.id = ?"
            );
            $stmt->execute([$id]);
            $update = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$update) {
                $this->sendJsonResponse(404, "Update not found");
                return;
            }
            $projectId = $update['project_id'];
            $pmc = new ProjectMemberController();
            if (!$pmc->hasProjectAccess($userId, $projectId)) {
                $this->sendJsonResponse(403, 'You are not a member of this project');
                return;
            }
            // Fetch attachments
            $attachStmt = $this->conn->prepare("
                SELECT id, file_name, file_path, file_type, file_size, duration, uploaded_by, created_at
                FROM update_attachments
                WHERE update_id = ?
                ORDER BY created_at ASC
            ");
            $attachStmt->execute([$id]);
            $attachments = $attachStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Process attachments and add full URLs
            $processedAttachments = [];
            $screenshots = [];
            $files = [];
            $voiceNotes = [];
            
            foreach ($attachments as $attachment) {
                $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . 
                          '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . 
                          '/' . dirname(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')));
                $fullUrl = $baseUrl . '/' . $attachment['file_path'];
                
                $attachmentObj = [
                    'id' => $attachment['id'],
                    'file_name' => $attachment['file_name'],
                    'file_path' => $attachment['file_path'],
                    'file_type' => $attachment['file_type'],
                    'file_size' => $attachment['file_size'],
                    'duration' => $attachment['duration'],
                    'uploaded_by' => $attachment['uploaded_by'],
                    'created_at' => $attachment['created_at'],
                    'full_url' => $fullUrl
                ];
                
                $processedAttachments[] = $attachmentObj;
                
                if ($attachment['file_type'] === 'screenshot') {
                    $screenshots[] = $attachmentObj;
                } elseif ($attachment['file_type'] === 'attachment') {
                    $files[] = $attachmentObj;
                } elseif ($attachment['file_type'] === 'voice_note') {
                    $voiceNotes[] = $attachmentObj;
                }
            }
            
            $this->sendJsonResponse(200, "Update retrieved successfully", [
                'id' => $update['id'],
                'title' => $update['title'],
                'type' => $update['type'],
                'description' => $update['description'],
                'created_by_id' => $update['created_by'],
                'created_by' => $update['created_by_name'] ?? $update['created_by'],
                'created_at' => $update['created_at'],
                'updated_at' => $update['updated_at'],
                'status' => $update['status'],
                'project_id' => $update['project_id'],
                'project_name' => $update['project_name'] ?? null,
                'expected_date' => $update['expected_date'] ?? null,
                'expected_time' => $update['expected_time'] ?? null,
                'attachments' => $processedAttachments,
                'screenshots' => $screenshots,
                'files' => $files,
                'voice_notes' => $voiceNotes,
                'attachments_count' => count($processedAttachments)
            ]);
        } catch (Exception $e) {
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }

    public function getAll()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }
        try {
            $decoded = $this->validateToken();
            if (!$decoded || !isset($decoded->user_id)) {
                $this->sendJsonResponse(401, "Unauthorized: Invalid or missing token");
                return;
            }
            $userId = $decoded->user_id;
            $userRole = $decoded->role;
            $pmc = new ProjectMemberController();
            // Admin: get all updates
            if ($userRole === 'admin') {
                $stmt = $this->conn->prepare("SELECT u.*, p.name as project_name, us.username as created_by_name FROM updates u JOIN projects p ON u.project_id = p.id LEFT JOIN users us ON u.created_by = us.id ORDER BY u.created_at DESC");
                $stmt->execute();
                $updates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                // Get all project IDs the user is a member of
                $stmt = $this->conn->prepare("SELECT project_id FROM project_members WHERE user_id = ?");
                $stmt->execute([$userId]);
                $projectIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
                if (empty($projectIds)) {
                    $this->sendJsonResponse(200, "No updates found", []);
                    return;
                }
                $in = str_repeat('?,', count($projectIds) - 1) . '?';
                $stmt = $this->conn->prepare("SELECT u.*, p.name as project_name, us.username as created_by_name FROM updates u JOIN projects p ON u.project_id = p.id LEFT JOIN users us ON u.created_by = us.id WHERE u.project_id IN ($in) ORDER BY u.created_at DESC");
                $stmt->execute($projectIds);
                $updates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            $result = array_map(function ($update) {
                return [
                    'id' => $update['id'],
                    'title' => $update['title'],
                    'type' => $update['type'],
                    'description' => $update['description'],
                    'created_by_id' => $update['created_by'],
                    'created_by' => $update['created_by_name'] ?? $update['created_by'],
                    'created_at' => $update['created_at'],
                    'updated_at' => $update['updated_at'],
                    'status' => $update['status'],
                    'project_id' => $update['project_id'],
                    'project_name' => $update['project_name']
                ];
            }, $updates);
            $this->sendJsonResponse(200, "Updates retrieved successfully", $result);
        } catch (Exception $e) {
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }

    public function getByProject($projectId)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }
        try {
            $decoded = $this->validateToken();
            if (!$decoded || !isset($decoded->user_id)) {
                $this->sendJsonResponse(401, "Unauthorized: Invalid or missing token");
                return;
            }
            $userId = $decoded->user_id;
            $userRole = $decoded->role;
            
            // Check if user has access to this project
            $pmc = new ProjectMemberController();
            if (!$pmc->hasProjectAccess($userId, $projectId)) {
                $this->sendJsonResponse(403, 'You are not a member of this project');
                return;
            }

            // Fetch updates for the specific project
            $stmt = $this->conn->prepare("
                SELECT u.*, p.name as project_name, us.username as created_by_name 
                FROM updates u 
                JOIN projects p ON u.project_id = p.id 
                LEFT JOIN users us ON u.created_by = us.id 
                WHERE u.project_id = ? 
                ORDER BY u.created_at DESC
            ");
            $stmt->execute([$projectId]);
            $updates = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $result = array_map(function ($update) {
                return [
                    'id' => $update['id'],
                    'title' => $update['title'],
                    'type' => $update['type'],
                    'description' => $update['description'],
                    'created_by_id' => $update['created_by'],
                    'created_by' => $update['created_by_name'] ?? $update['created_by'],
                    'created_at' => $update['created_at'],
                    'updated_at' => $update['updated_at'],
                    'status' => $update['status'],
                    'project_id' => $update['project_id'],
                    'project_name' => $update['project_name']
                ];
            }, $updates);

            $this->sendJsonResponse(200, "Project updates retrieved successfully", $result);
        } catch (Exception $e) {
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }

    public function update($id)
    {
        // Support both PUT (JSON) and POST (FormData) methods
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }
        try {
            $decoded = $this->validateToken();
            if (!$decoded || !isset($decoded->user_id)) {
                $this->sendJsonResponse(401, "Unauthorized: Invalid or missing token");
                return;
            }
            $userId = $decoded->user_id;
            $userRole = $decoded->role;
            
            // Handle both JSON and FormData requests
            $contentType = isset($_SERVER["CONTENT_TYPE"]) ? trim($_SERVER["CONTENT_TYPE"]) : '';
            
            // Debug logging
            error_log("UpdateController::update - Content-Type: " . $contentType);
            error_log("UpdateController::update - Has FILES: " . (!empty($_FILES) ? 'yes' : 'no'));
            error_log("UpdateController::update - POST keys: " . json_encode(array_keys($_POST ?? [])));
            error_log("UpdateController::update - FILES keys: " . json_encode(array_keys($_FILES ?? [])));
            if (!empty($_FILES)) {
                foreach ($_FILES as $key => $file) {
                    if (is_array($file['name'])) {
                        error_log("UpdateController::update - $key: " . count($file['name']) . " files");
                    } else {
                        error_log("UpdateController::update - $key: single file - " . ($file['name'] ?? 'no name'));
                    }
                }
            }
            
            // For multipart/form-data (FormData), use $_POST directly
            if (stripos($contentType, 'multipart/form-data') !== false || !empty($_FILES)) {
                $data = $_POST;
            } else {
                $data = $this->getRequestData();
            }
            
            // Fallback: if data is empty but $_POST has data, use $_POST
            if (empty($data) && !empty($_POST)) {
                $data = $_POST;
            }
            
            error_log("UpdateController::update - Data keys: " . json_encode(array_keys($data ?? [])));
            
            // Log all FILES data for debugging
            if (!empty($_FILES)) {
                error_log("UpdateController::update - FILES structure: " . json_encode(array_map(function($file) {
                    if (is_array($file['name'])) {
                        return [
                            'count' => count($file['name']),
                            'names' => $file['name'],
                            'errors' => $file['error'] ?? []
                        ];
                    }
                    return ['single_file' => $file['name'] ?? 'no name'];
                }, $_FILES)));
            } else {
                error_log("UpdateController::update - No FILES received");
            }
            
            // Fetch update to get project_id and created_by
            $stmt = $this->conn->prepare("SELECT * FROM updates WHERE id = ?");
            $stmt->execute([$id]);
            $update = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$update) {
                $this->sendJsonResponse(404, "Update not found");
                return;
            }
            $projectId = $update['project_id'];
            $pmc = new ProjectMemberController();
            if (!$pmc->hasProjectAccess($userId, $projectId)) {
                $this->sendJsonResponse(403, 'You are not a member of this project');
                return;
            }
            // Only allow admin or creator to update
            if ($userRole !== 'admin' && $update['created_by'] != $userId) {
                $this->sendJsonResponse(403, 'You do not have permission to update this update');
                return;
            }
            
            $fields = [];
            $values = [];
            if (isset($data['title'])) {
                $fields[] = "title = ?";
                $values[] = $data['title'];
            }
            if (isset($data['type'])) {
                $fields[] = "type = ?";
                $values[] = $data['type'];
            }
            if (isset($data['description'])) {
                $fields[] = "description = ?";
                $values[] = $data['description'];
            }
            if (isset($data['status'])) {
                $fields[] = "status = ?";
                $values[] = $data['status'];
            }
            if (isset($data['expected_date'])) {
                $fields[] = "expected_date = ?";
                $values[] = !empty($data['expected_date']) ? $data['expected_date'] : null;
            }
            if (isset($data['expected_time'])) {
                $fields[] = "expected_time = ?";
                $values[] = !empty($data['expected_time']) ? $data['expected_time'] : null;
            }
            
            // Handle attachment deletions
            if (isset($data['attachments_to_delete']) && !empty($data['attachments_to_delete'])) {
                $attachmentsToDelete = is_string($data['attachments_to_delete']) 
                    ? json_decode($data['attachments_to_delete'], true) 
                    : $data['attachments_to_delete'];
                if (is_array($attachmentsToDelete)) {
                    foreach ($attachmentsToDelete as $attachmentId) {
                        $stmt = $this->conn->prepare("SELECT file_path FROM update_attachments WHERE id = ?");
                        $stmt->execute([$attachmentId]);
                        $attachment = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($attachment) {
                            $deleteStmt = $this->conn->prepare("DELETE FROM update_attachments WHERE id = ?");
                            $deleteStmt->execute([$attachmentId]);
                            
                            $filePath = __DIR__ . '/../../' . $attachment['file_path'];
                            if (file_exists($filePath)) {
                                @unlink($filePath);
                            }
                        }
                    }
                }
            }
            
            // Handle new file uploads
            // Utils class is already available via BaseAPI (from config/utils.php)
            
            // Check if Utils class is available
            if (!class_exists('Utils') || !method_exists('Utils', 'generateUUID')) {
                error_log("UpdateController::update - Utils class or generateUUID method not found");
                $this->sendJsonResponse(500, "System error: Utils class not available");
                return;
            }
            
            // Handle new screenshots
            if (!empty($_FILES['screenshots'])) {
                $screenshotCount = is_array($_FILES['screenshots']['tmp_name']) ? count($_FILES['screenshots']['tmp_name']) : 1;
                error_log("UpdateController::update - Processing screenshots: " . $screenshotCount);
                $uploadDir = __DIR__ . '/../../uploads/screenshots/';
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                $screenshotFiles = is_array($_FILES['screenshots']['tmp_name']) ? $_FILES['screenshots']['tmp_name'] : [$_FILES['screenshots']['tmp_name']];
                $screenshotNames = is_array($_FILES['screenshots']['name']) ? $_FILES['screenshots']['name'] : [$_FILES['screenshots']['name']];
                $screenshotTypes = is_array($_FILES['screenshots']['type']) ? $_FILES['screenshots']['type'] : [$_FILES['screenshots']['type']];
                $screenshotErrors = is_array($_FILES['screenshots']['error']) ? $_FILES['screenshots']['error'] : [$_FILES['screenshots']['error']];
                
                foreach ($screenshotFiles as $key => $tmp_name) {
                    if (empty($tmp_name) || $screenshotErrors[$key] !== UPLOAD_ERR_OK) {
                        error_log("UpdateController::update - Skipping screenshot $key: empty or error " . ($screenshotErrors[$key] ?? 'unknown'));
                        continue;
                    }
                    
                    $fileName = $screenshotNames[$key];
                    $fileType = $screenshotTypes[$key];
                    $filePath = $uploadDir . uniqid() . '_' . $fileName;
                    
                    if (move_uploaded_file($tmp_name, $filePath)) {
                        $attachmentId = Utils::generateUUID();
                        $relativePath = str_replace(__DIR__ . '/../../uploads/', 'uploads/', $filePath);
                        $stmt = $this->conn->prepare(
                            "INSERT INTO update_attachments (id, update_id, file_name, file_path, file_type, uploaded_by) 
                             VALUES (?, ?, ?, ?, 'screenshot', ?)"
                        );
                        $result = $stmt->execute([$attachmentId, $id, $fileName, $relativePath, $userId]);
                        if ($result) {
                            error_log("UpdateController::update - Screenshot saved: $fileName (ID: $attachmentId)");
                        } else {
                            $errorInfo = $stmt->errorInfo();
                            error_log("UpdateController::update - Failed to save screenshot: " . json_encode($errorInfo));
                        }
                    } else {
                        error_log("UpdateController::update - Failed to move uploaded screenshot: $tmp_name to $filePath");
                    }
                }
            }

            // Handle new files/attachments
            if (!empty($_FILES['files'])) {
                $fileCount = is_array($_FILES['files']['tmp_name']) ? count($_FILES['files']['tmp_name']) : 1;
                error_log("UpdateController::update - Processing files: " . $fileCount);
                $uploadDir = __DIR__ . '/../../uploads/files/';
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                $fileTmpNames = is_array($_FILES['files']['tmp_name']) ? $_FILES['files']['tmp_name'] : [$_FILES['files']['tmp_name']];
                $fileNames = is_array($_FILES['files']['name']) ? $_FILES['files']['name'] : [$_FILES['files']['name']];
                $fileTypes = is_array($_FILES['files']['type']) ? $_FILES['files']['type'] : [$_FILES['files']['type']];
                $fileSizes = is_array($_FILES['files']['size']) ? $_FILES['files']['size'] : [$_FILES['files']['size']];
                $fileErrors = is_array($_FILES['files']['error']) ? $_FILES['files']['error'] : [$_FILES['files']['error']];
                
                foreach ($fileTmpNames as $key => $tmp_name) {
                    if (empty($tmp_name) || $fileErrors[$key] !== UPLOAD_ERR_OK) {
                        error_log("UpdateController::update - Skipping file $key: empty or error " . ($fileErrors[$key] ?? 'unknown'));
                        continue;
                    }
                    
                    $fileName = $fileNames[$key];
                    $fileType = $fileTypes[$key];
                    $fileSize = $fileSizes[$key] ?? 0;
                    $filePath = $uploadDir . uniqid() . '_' . $fileName;
                    
                    if (move_uploaded_file($tmp_name, $filePath)) {
                        $attachmentId = Utils::generateUUID();
                        $relativePath = str_replace(__DIR__ . '/../../uploads/', 'uploads/', $filePath);
                        $stmt = $this->conn->prepare(
                            "INSERT INTO update_attachments (id, update_id, file_name, file_path, file_type, file_size, uploaded_by) 
                             VALUES (?, ?, ?, ?, 'attachment', ?, ?)"
                        );
                        $result = $stmt->execute([$attachmentId, $id, $fileName, $relativePath, $fileSize, $userId]);
                        if ($result) {
                            error_log("UpdateController::update - ✅ File saved successfully: $fileName (ID: $attachmentId, Update ID: $id)");
                        } else {
                            $errorInfo = $stmt->errorInfo();
                            error_log("UpdateController::update - ❌ Failed to save file: " . json_encode($errorInfo));
                            error_log("UpdateController::update - SQL State: " . ($errorInfo[0] ?? 'unknown'));
                            error_log("UpdateController::update - Error Code: " . ($errorInfo[1] ?? 'unknown'));
                            error_log("UpdateController::update - Error Message: " . ($errorInfo[2] ?? 'unknown'));
                        }
                    } else {
                        error_log("UpdateController::update - Failed to move uploaded file: $tmp_name to $filePath");
                    }
                }
            }

            // Handle new voice notes
            if (!empty($_FILES['voice_notes'])) {
                $voiceNoteCount = is_array($_FILES['voice_notes']['tmp_name']) ? count($_FILES['voice_notes']['tmp_name']) : 1;
                error_log("UpdateController::update - Processing voice notes: " . $voiceNoteCount);
                $uploadDir = __DIR__ . '/../../uploads/voice_notes/';
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                $voiceNoteTmpNames = is_array($_FILES['voice_notes']['tmp_name']) ? $_FILES['voice_notes']['tmp_name'] : [$_FILES['voice_notes']['tmp_name']];
                $voiceNoteNames = is_array($_FILES['voice_notes']['name']) ? $_FILES['voice_notes']['name'] : [$_FILES['voice_notes']['name']];
                $voiceNoteTypes = is_array($_FILES['voice_notes']['type']) ? $_FILES['voice_notes']['type'] : [$_FILES['voice_notes']['type']];
                $voiceNoteSizes = is_array($_FILES['voice_notes']['size']) ? $_FILES['voice_notes']['size'] : [$_FILES['voice_notes']['size']];
                $voiceNoteErrors = is_array($_FILES['voice_notes']['error']) ? $_FILES['voice_notes']['error'] : [$_FILES['voice_notes']['error']];
                
                foreach ($voiceNoteTmpNames as $key => $tmp_name) {
                    if (empty($tmp_name) || $voiceNoteErrors[$key] !== UPLOAD_ERR_OK) {
                        error_log("UpdateController::update - Skipping voice note $key: empty or error " . ($voiceNoteErrors[$key] ?? 'unknown'));
                        continue;
                    }
                    
                    $fileName = $voiceNoteNames[$key];
                    $fileType = $voiceNoteTypes[$key];
                    $fileSize = $voiceNoteSizes[$key] ?? 0;
                    // Duration can be a float (e.g., 5.1435 seconds), convert to int for storage (seconds)
                    $duration = isset($data["voice_note_duration_$key"]) ? (int)round((float)$data["voice_note_duration_$key"]) : null;
                    $filePath = $uploadDir . uniqid() . '_' . $fileName;
                    
                    if (move_uploaded_file($tmp_name, $filePath)) {
                        $attachmentId = Utils::generateUUID();
                        $relativePath = str_replace(__DIR__ . '/../../uploads/', 'uploads/', $filePath);
                        $stmt = $this->conn->prepare(
                            "INSERT INTO update_attachments (id, update_id, file_name, file_path, file_type, file_size, duration, uploaded_by) 
                             VALUES (?, ?, ?, ?, 'voice_note', ?, ?, ?)"
                        );
                        $result = $stmt->execute([$attachmentId, $id, $fileName, $relativePath, $fileSize, $duration, $userId]);
                        if ($result) {
                            error_log("UpdateController::update - ✅ Voice note saved successfully: $fileName (ID: $attachmentId, Update ID: $id, Duration: $duration)");
                        } else {
                            $errorInfo = $stmt->errorInfo();
                            error_log("UpdateController::update - ❌ Failed to save voice note: " . json_encode($errorInfo));
                            error_log("UpdateController::update - SQL State: " . ($errorInfo[0] ?? 'unknown'));
                            error_log("UpdateController::update - Error Code: " . ($errorInfo[1] ?? 'unknown'));
                            error_log("UpdateController::update - Error Message: " . ($errorInfo[2] ?? 'unknown'));
                        }
                    } else {
                        error_log("UpdateController::update - Failed to move uploaded voice note: $tmp_name to $filePath");
                    }
                }
            }
            
            if (empty($fields) && empty($_FILES) && empty($data['attachments_to_delete'])) {
                $this->sendJsonResponse(400, "No fields to update");
                return;
            }
            
            if (!empty($fields)) {
                $fields[] = "updated_at = NOW()";
                $values[] = $id;
                $query = "UPDATE updates SET " . implode(", ", $fields) . " WHERE id = ?";
                error_log("UpdateController::update - SQL: " . $query);
                error_log("UpdateController::update - Values: " . json_encode($values));
                
                try {
                    $stmt = $this->conn->prepare($query);
                    if (!$stmt) {
                        $errorInfo = $this->conn->errorInfo();
                        error_log("UpdateController::update - Prepare failed: " . json_encode($errorInfo));
                        $this->sendJsonResponse(500, "Database prepare error: " . ($errorInfo[2] ?? 'Unknown error'));
                        return;
                    }
                    $success = $stmt->execute($values);
                    if (!$success) {
                        $errorInfo = $stmt->errorInfo();
                        error_log("UpdateController::update - Execute failed: " . json_encode($errorInfo));
                        $this->sendJsonResponse(500, "Database execute error: " . ($errorInfo[2] ?? 'Unknown error'));
                        return;
                    }
                    if ($stmt->rowCount() === 0 && empty($_FILES) && empty($data['attachments_to_delete'])) {
                        $this->sendJsonResponse(404, "Update not found or no changes made");
                        return;
                    }
                } catch (PDOException $e) {
                    error_log("UpdateController::update - PDO Exception: " . $e->getMessage());
                    error_log("UpdateController::update - SQL: " . $query);
                    $this->sendJsonResponse(500, "Database error: " . $e->getMessage());
                    return;
                }
            }
            
            $this->sendJsonResponse(200, "Update updated successfully");
        } catch (Exception $e) {
            error_log("UpdateController::update - Exception: " . $e->getMessage());
            error_log("UpdateController::update - Stack trace: " . $e->getTraceAsString());
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }

    public function delete($id)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }
        try {
            $decoded = $this->validateToken();
            if (!$decoded || !isset($decoded->user_id)) {
                $this->sendJsonResponse(401, "Unauthorized: Invalid or missing token");
                return;
            }
            $userId = $decoded->user_id;
            $userRole = $decoded->role;
            // Fetch update to get project_id and created_by
            $stmt = $this->conn->prepare("SELECT * FROM updates WHERE id = ?");
            $stmt->execute([$id]);
            $update = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$update) {
                $this->sendJsonResponse(404, "Update not found");
                return;
            }
            $projectId = $update['project_id'];
            $pmc = new ProjectMemberController();
            if (!$pmc->hasProjectAccess($userId, $projectId)) {
                $this->sendJsonResponse(403, 'You are not a member of this project');
                return;
            }
            // Only allow admin or creator to delete
            if ($userRole !== 'admin' && $update['created_by'] != $userId) {
                $this->sendJsonResponse(403, 'You do not have permission to delete this update');
                return;
            }
            $stmt = $this->conn->prepare("DELETE FROM updates WHERE id = ?");
            $stmt->execute([$id]);
            if ($stmt->rowCount() === 0) {
                $this->sendJsonResponse(404, "Update not found");
                return;
            }
            $this->sendJsonResponse(200, "Update deleted successfully");
        } catch (Exception $e) {
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }

    public function approve($id)
    {
        $this->changeStatus($id, 'approved');
    }
    public function decline($id)
    {
        $this->changeStatus($id, 'declined');
    }
    public function complete($id)
    {
        try {
            $decoded = $this->validateToken();
            if (!$decoded || !isset($decoded->user_id)) {
                $this->sendJsonResponse(401, "Unauthorized: Invalid or missing token");
                return;
            }
            $userId = $decoded->user_id;
            $userRole = $decoded->role;
            
            // Validate ID
            if (empty($id)) {
                $this->sendJsonResponse(400, "Update ID is required");
                return;
            }
            
            // Auto-migrate: Add 'completed' to status ENUM if it doesn't exist
            try {
                $enumCheck = $this->conn->query("SHOW COLUMNS FROM updates WHERE Field = 'status'");
                $enumData = $enumCheck->fetch(PDO::FETCH_ASSOC);
                if ($enumData && isset($enumData['Type'])) {
                    $enumType = $enumData['Type'];
                    // Check if 'completed' is not in the ENUM
                    if (stripos($enumType, 'completed') === false) {
                        error_log("UpdateController::complete - Adding 'completed' to status ENUM");
                        $this->conn->exec("ALTER TABLE updates MODIFY COLUMN status ENUM('pending', 'approved', 'declined', 'completed') DEFAULT 'pending'");
                        error_log("UpdateController::complete - Successfully added 'completed' to status ENUM");
                    }
                }
            } catch (Exception $e) {
                error_log("UpdateController::complete - Warning: Could not check/update status ENUM: " . $e->getMessage());
                // Continue anyway - might already be updated
            }
            
            // Fetch update to get project_id and status
            $stmt = $this->conn->prepare("SELECT * FROM updates WHERE id = ?");
            $stmt->execute([$id]);
            $update = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$update) {
                $this->sendJsonResponse(404, "Update not found");
                return;
            }
            
            // Only allow completing approved updates
            if ($update['status'] !== 'approved') {
                $this->sendJsonResponse(400, "Only approved updates can be marked as completed");
                return;
            }
            
            $projectId = $update['project_id'];
            $pmc = new ProjectMemberController();
            if (!$pmc->hasProjectAccess($userId, $projectId)) {
                $this->sendJsonResponse(403, 'You are not a member of this project');
                return;
            }
            
            // Check if updated_at column exists, if not just update status
            $columnsCheck = $this->conn->query("SHOW COLUMNS FROM updates LIKE 'updated_at'");
            $hasUpdatedAt = $columnsCheck->rowCount() > 0;
            
            // Allow project members (admin, developer, tester) to mark approved updates as completed
            if ($hasUpdatedAt) {
                $stmt = $this->conn->prepare("UPDATE updates SET status = 'completed', updated_at = NOW() WHERE id = ?");
            } else {
                $stmt = $this->conn->prepare("UPDATE updates SET status = 'completed' WHERE id = ?");
            }
            $stmt->execute([$id]);
            
            if ($stmt->rowCount() === 0) {
                $this->sendJsonResponse(404, "Update not found or no changes made");
                return;
            }
            
            $this->sendJsonResponse(200, "Update marked as completed successfully");
        } catch (PDOException $e) {
            error_log("UpdateController::complete - PDO Error: " . $e->getMessage());
            error_log("UpdateController::complete - SQL State: " . $e->getCode());
            error_log("UpdateController::complete - Error Info: " . json_encode($e->errorInfo()));
            $this->sendJsonResponse(500, "Database error: " . $e->getMessage());
        } catch (Exception $e) {
            error_log("UpdateController::complete - Exception: " . $e->getMessage());
            error_log("UpdateController::complete - Trace: " . $e->getTraceAsString());
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }
    private function changeStatus($id, $status)
    {
        try {
            $decoded = $this->validateToken();
            if (!$decoded || !isset($decoded->user_id)) {
                $this->sendJsonResponse(401, "Unauthorized: Invalid or missing token");
                return;
            }
            $userId = $decoded->user_id;
            $userRole = $decoded->role;
            // Fetch update to get project_id
            $stmt = $this->conn->prepare("SELECT * FROM updates WHERE id = ?");
            $stmt->execute([$id]);
            $update = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$update) {
                $this->sendJsonResponse(404, "Update not found");
                return;
            }
            $projectId = $update['project_id'];
            $pmc = new ProjectMemberController();
            if (!$pmc->hasProjectAccess($userId, $projectId)) {
                $this->sendJsonResponse(403, 'You are not a member of this project');
                return;
            }
            // Only admin can approve/decline
            if ($userRole !== 'admin') {
                $this->sendJsonResponse(403, "Only admin can approve or decline updates");
                return;
            }
            $stmt = $this->conn->prepare("UPDATE updates SET status = ? WHERE id = ?");
            $stmt->execute([$status, $id]);
            $this->sendJsonResponse(200, "Update $status successfully");
        } catch (Exception $e) {
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }
}
