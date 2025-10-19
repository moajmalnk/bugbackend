<?php
require_once __DIR__ . '/../BaseAPI.php';

class UpdateProfilePictureAPI extends BaseAPI {
    
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
            
            if (!isset($_FILES['profile_picture'])) {
                $this->sendJsonResponse(400, "profile_picture file is required");
                return;
            }
            
            $file = $_FILES['profile_picture'];
            
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
            $uploadDir = __DIR__ . '/../../uploads/profile_pictures/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            // Generate unique filename
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = $userId . '_' . time() . '.' . $extension;
            $uploadPath = $uploadDir . $filename;
            
            // Move uploaded file
            if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
                $this->sendJsonResponse(500, "Failed to upload file");
                return;
            }
            
            // Get relative path for database
            $relativePath = '/BugRicer/backend/uploads/profile_pictures/' . $filename;
            
            // Update user profile picture
            $stmt = $this->conn->prepare("
                UPDATE users 
                SET avatar = ? 
                WHERE id = ?
            ");
            $stmt->execute([$relativePath, $userId]);
            
            $this->sendJsonResponse(200, "Profile picture updated successfully", [
                'profile_picture_url' => $relativePath
            ]);
            
        } catch (Exception $e) {
            error_log("Error updating profile picture: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to update profile picture: " . $e->getMessage());
        }
    }
}

$api = new UpdateProfilePictureAPI();
$api->handle();

