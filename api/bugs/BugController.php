<?php

require_once __DIR__ . '/../BaseAPI.php';

class BugController extends BaseAPI {
    private $logFile;
    private $baseUrl;

    public function __construct() {
        parent::__construct();
        $this->logFile = __DIR__ . '/../../logs/debug.log';
        $this->baseUrl = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
        $this->baseUrl .= $_SERVER['HTTP_HOST'];
    }

    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($this->logFile, "[$timestamp] $message\n", FILE_APPEND);
    }

    private function getFullPath($path) {
        // Remove any leading slashes
        $path = ltrim($path, '/');
        return $this->baseUrl . '/' . $path;
    }

    public function handleError($message, $code = 400) {
        $this->log("Error: $message");
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
            $this->log("Starting bug creation with data: " . json_encode($data));

            // Validate required fields
            $requiredFields = ['name', 'project_id', 'reporter_id'];
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Missing required field: $field");
                }
            }

            $this->conn->beginTransaction();
            $this->log("Started transaction");

            $bugId = $this->generateUUID();
            
            // Insert bug
            $stmt = $this->conn->prepare("
                INSERT INTO bugs (
                    id, title, description, project_id, reported_by,
                    priority, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            $this->log("Executing bug insert with values: " . json_encode([
                $bugId,
                $data['name'],
                $data['description'],
                $data['project_id'],
                $data['reporter_id'],
                $data['priority'],
                $data['status']
            ]));

            $result = $stmt->execute([
                $bugId,
                $data['name'],
                $data['description'],
                $data['project_id'],
                $data['reporter_id'],
                $data['priority'],
                $data['status']
            ]);

            if (!$result) {
                $error = $stmt->errorInfo();
                throw new PDOException("Failed to insert bug: " . $error[2]);
            }

            // Insert screenshots
            if (!empty($data['screenshots'])) {
                $this->log("Processing screenshots");
                $stmt = $this->conn->prepare("
                    INSERT INTO bug_attachments (
                        id, bug_id, file_name, file_path, file_type,
                        uploaded_by
                    ) VALUES (?, ?, ?, ?, ?, ?)
                ");

                $uploadedAttachments = [];
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
                $this->log("Processing files");
                $stmt = $this->conn->prepare("
                    INSERT INTO bug_attachments (
                        id, bug_id, file_name, file_path, file_type,
                        uploaded_by
                    ) VALUES (?, ?, ?, ?, ?, ?)
                ");

                $uploadedAttachments = [];
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
                $this->log("Processing dashboards");
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
            $this->log("Transaction committed successfully");

            $this->handleSuccess("Bug created successfully", [
                'bugId' => $bugId,
                'uploadedAttachments' => $uploadedAttachments
            ]);
        } catch (PDOException $e) {
            $this->log("PDO Error: " . $e->getMessage());
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
                $this->log("Transaction rolled back");
            }
            $this->handleError("Database error: " . $e->getMessage(), 500);
        } catch (Exception $e) {
            $this->log("General Error: " . $e->getMessage());
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
                $this->log("Transaction rolled back");
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
            $this->log("Error getting bugs: " . $e->getMessage());
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
                            u.username as reporter_name
                     FROM bugs b
                     LEFT JOIN projects p ON b.project_id = p.id
                     LEFT JOIN users u ON b.reported_by = u.id";
                     
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
            error_log("Error fetching bugs: " . $e->getMessage());
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }
    
    public function getById($id) {
        try {
            $stmt = $this->conn->prepare("
                SELECT b.*, 
                       p.name as project_name,
                       reporter.username as reporter_name,
                       updater.username as updated_by_name
                FROM bugs b
                LEFT JOIN projects p ON b.project_id = p.id
                LEFT JOIN users reporter ON b.reported_by = reporter.id
                LEFT JOIN users updater ON b.updated_by = updater.id
                WHERE b.id = ?
            ");
            
            $stmt->execute([$id]);
            $bug = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$bug) {
                $this->handleError("Bug not found", 404);
                return;
            }

            // Get attachments
            $attachStmt = $this->conn->prepare("
                SELECT id, file_name, file_path, file_type
                FROM bug_attachments
                WHERE bug_id = ?
            ");
            $attachStmt->execute([$id]);
            $attachments = $attachStmt->fetchAll(PDO::FETCH_ASSOC);

            // Separate screenshots and other files
            $bug['screenshots'] = [];
            $bug['files'] = [];
            foreach ($attachments as $attachment) {
                // Ensure path has the correct prefix
                $path = $attachment['file_path'];
                // Remove any unnecessary prefixing
                $fullPath = $this->getFullPath($path);
                
                if (strpos($attachment['file_type'], 'image/') === 0) {
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
            $stmt = $this->conn->prepare(
                "INSERT INTO bugs (id, title, description, project_id, reported_by, priority, status) 
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            
            $priority = isset($data['priority']) ? $data['priority'] : 'medium';
            $status = 'pending';
            
            $stmt->execute([
                $id,
                $data['title'],
                $data['description'],
                $data['project_id'],
                $decoded->user_id,
                $priority,
                $status
            ]);

            // Initialize array to collect all uploaded file paths
            $uploadedAttachments = [];

            // Handle screenshots
            if (!empty($_FILES['screenshots'])) {
                $uploadDir = __DIR__ . '/../../uploads/screenshots/';
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                foreach ($_FILES['screenshots']['tmp_name'] as $key => $tmp_name) {
                    $fileName = $_FILES['screenshots']['name'][$key];
                    $fileType = $_FILES['screenshots']['type'][$key];
                    $filePath = $uploadDir . uniqid() . '_' . $fileName;
                    
                    if (move_uploaded_file($tmp_name, $filePath)) {
                        $attachmentId = Utils::generateUUID();
                        // Store path relative to the 'uploads' directory
                        $relativePath = str_replace(__DIR__ . '/../../uploads/', 'uploads/', $filePath);
                        $stmt = $this->conn->prepare(
                            "INSERT INTO bug_attachments (id, bug_id, file_name, file_path, file_type, uploaded_by) 
                             VALUES (?, ?, ?, ?, ?, ?)"
                        );
                        $stmt->execute([
                            $attachmentId,
                            $id,
                            $fileName,
                            $relativePath,
                            $fileType,
                            $decoded->user_id
                        ]);
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
                    $filePath = $uploadDir . uniqid() . '_' . $fileName;
                    
                    if (move_uploaded_file($tmp_name, $filePath)) {
                        $attachmentId = Utils::generateUUID();
                        // Store path relative to the 'uploads' directory
                        $relativePath = str_replace(__DIR__ . '/../../uploads/', 'uploads/', $filePath);
                        $stmt = $this->conn->prepare(
                            "INSERT INTO bug_attachments (id, bug_id, file_name, file_path, file_type, uploaded_by) 
                             VALUES (?, ?, ?, ?, ?, ?)"
                        );
                        $stmt->execute([
                            $attachmentId,
                            $id,
                            $fileName,
                            $relativePath,
                            $fileType,
                            $decoded->user_id
                        ]);
                        // Add the relative path to the list
                        $uploadedAttachments[] = $relativePath;
                        @unlink($tmp_name);
                    }
                }
            }
            
            $this->conn->commit();
            
            $bug = [
                'id' => $id,
                'title' => $data['title'],
                'description' => $data['description'],
                'project_id' => $data['project_id'],
                'reported_by' => $decoded->user_id,
                'priority' => $priority,
                'status' => $status,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            $this->log("Starting bug creation with data: " . json_encode($data));
            $this->log("Starting bug creation with data: " . json_encode($uploadedAttachments));
            $this->handleSuccess("Bug created successfully", [
                'bug' => $bug,
                'uploadedAttachments' => $uploadedAttachments
            ]);
            
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log("Error creating bug: " . $e->getMessage());
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
            
            if (empty($updateFields)) {
                $this->sendJsonResponse(400, "No fields to update");
                return;
            }
            
            $updateFields[] = "updated_at = CURRENT_TIMESTAMP()";
            
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
            error_log("Error updating bug: " . $e->getMessage());
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }

    public function delete($id) {
        try {
            $decoded = $this->validateToken();
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
                if (file_exists($filePath)) {
                    @unlink($filePath);
                }
            }

            // Delete bug (cascading will handle attachments and dashboard relations)
            $query = "DELETE FROM bugs WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $id);

            if ($stmt->execute()) {
                $this->conn->commit();
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

    public function getAllBugs($projectId = null, $page = 1, $limit = 10) {
        try {
            // Validate token
            $this->validateToken();

            // Validate connection
            if (!$this->conn) {
                error_log("Database connection failed in BugController");
                $this->sendJsonResponse(500, "Database connection failed");
                return;
            }

            // Base query
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

            // Add sorting
            $query .= " ORDER BY b.created_at DESC";

            // Add pagination
            $offset = ($page - 1) * $limit;
            $query .= " LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;

            // Get total count
            $countStmt = $this->conn->prepare($countQuery);
            if ($projectId) {
                $countStmt->execute([$projectId]);
            } else {
                $countStmt->execute();
            }
            $totalBugs = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

            // Execute main query
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            $bugs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get attachments for each bug
            foreach ($bugs as &$bug) {
                $attachmentQuery = "SELECT id, file_name, file_path, file_type FROM bug_attachments WHERE bug_id = ?";
                $attachmentStmt = $this->conn->prepare($attachmentQuery);
                $attachmentStmt->execute([$bug['id']]);
                $bug['attachments'] = $attachmentStmt->fetchAll(PDO::FETCH_ASSOC);
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

            $this->sendJsonResponse(200, "Bugs retrieved successfully", $response);
        } catch (PDOException $e) {
            error_log("Database error in getAllBugs: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to retrieve bugs");
        } catch (Exception $e) {
            error_log("Error in getAllBugs: " . $e->getMessage());
            $this->sendJsonResponse(500, "An unexpected error occurred");
        }
    }

    public function updateBug($data) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }
        try {
            $this->log("Starting bug update with data: " . json_encode($data));

            if (empty($data['id'])) {
                throw new Exception("Bug ID is required");
            }

            $this->conn->beginTransaction();

            // Check if bug exists
            $checkStmt = $this->conn->prepare("SELECT id FROM bugs WHERE id = ?");
            $checkStmt->execute([$data['id']]);
            if (!$checkStmt->fetch()) {
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
            if (isset($data['priority'])) {
                $updateFields[] = "priority = ?";
                $params[] = $data['priority'];
            }
            if (isset($data['status'])) {
                $updateFields[] = "status = ?";
                $params[] = $data['status'];
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
            
            $this->log("Executing query: " . $query);
            $this->log("Parameters: " . json_encode($params));

            if (!$stmt->execute($params)) {
                $error = $stmt->errorInfo();
                throw new Exception("Failed to update bug: " . implode(", ", $error));
            }

            // Get updated bug data with updated_by_name
            $stmt = $this->conn->prepare("
                SELECT b.*, 
                       p.name as project_name, 
                       reporter.username as reporter_name,
                       updater.username as updated_by_name
                FROM bugs b
                LEFT JOIN projects p ON b.project_id = p.id
                LEFT JOIN users reporter ON b.reported_by = reporter.id
                LEFT JOIN users updater ON b.updated_by = updater.id
                WHERE b.id = ?
            ");
            $stmt->execute([$data['id']]);
            $updatedBug = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$updatedBug) {
                throw new Exception("Failed to fetch updated bug data");
            }

            $this->conn->commit();
            $this->log("Bug update successful");

            return $updatedBug;

        } catch (Exception $e) {
            $this->log("Error in updateBug: " . $e->getMessage());
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
}