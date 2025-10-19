<?php
require_once __DIR__ . '/../BaseAPI.php';

class UpdateGroupPictureAPI extends BaseAPI {
    
    public function __construct() {
        parent::__construct();
    }
    
    public function handle() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }
        
        try {
            $decoded = $this->validateToken();
            $userId = $decoded->user_id;
            $userRole = $decoded->role;
            
            if (!isset($_FILES['group_picture']) || !isset($_POST['group_id'])) {
                $this->sendJsonResponse(400, "group_picture file and group_id are required");
                return;
            }
            
            $file = $_FILES['group_picture'];
            $groupId = $_POST['group_id'];
            
            // Check if user has admin access to the group
            if ($userRole !== 'admin') {
                // Check if user is group creator
                $groupStmt = $this->conn->prepare("
                    SELECT created_by FROM chat_groups WHERE id = ?
                ");
                $groupStmt->execute([$groupId]);
                $group = $groupStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$group || $group['created_by'] !== $userId) {
                    $this->sendJsonResponse(403, "Only admins or group creators can update group picture");
                    return;
                }
            }
            
            // Validate file
            $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
            if (!in_array($file['type'], $allowedTypes)) {
                $this->sendJsonResponse(400, "Invalid file type. Only images allowed.");
                return;
            }
            
            // Check file size (max 5MB)
            if ($file['size'] > 5 * 1024 * 1024) {
                $this->sendJsonResponse(400, "File too large. Maximum size is 5MB.");
                return;
            }
            
            // Create upload directory if it doesn't exist
            $uploadDir = __DIR__ . '/../../uploads/group_pictures/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            // Generate unique filename
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = $groupId . '_' . time() . '.' . $extension;
            $uploadPath = $uploadDir . $filename;
            
            // Move uploaded file
            if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
                $this->sendJsonResponse(500, "Failed to upload file");
                return;
            }
            
            // Get relative path for database
            $relativePath = '/BugRicer/backend/uploads/group_pictures/' . $filename;
            
            // Update group picture
            $stmt = $this->conn->prepare("
                UPDATE chat_groups 
                SET group_picture = ? 
                WHERE id = ?
            ");
            $stmt->execute([$relativePath, $groupId]);
            
            $this->sendJsonResponse(200, "Group picture updated successfully", [
                'group_picture_url' => $relativePath
            ]);
            
        } catch (Exception $e) {
            error_log("Error updating group picture: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to update group picture: " . $e->getMessage());
        }
    }
}

$api = new UpdateGroupPictureAPI();
$api->handle();

