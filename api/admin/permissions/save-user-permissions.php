<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../BaseAPI.php';
require_once __DIR__ . '/../../../config/cors.php';

// Handle CORS
handleCORS();

class PermissionController extends BaseAPI {
    public function saveUserPermissions() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }

        try {
            // Require USERS_MANAGE_PERMISSIONS permission
            $this->requirePermission('USERS_MANAGE_PERMISSIONS');

            // Get user ID from query parameter
            $userId = $_GET['user_id'] ?? null;
            
            if (empty($userId)) {
                $this->sendJsonResponse(400, "User ID is required");
                return;
            }

            $data = $this->getRequestData();
            
            // Validate permissions array
            if (!isset($data['permissions']) || !is_array($data['permissions'])) {
                $this->sendJsonResponse(400, "Permissions array is required");
                return;
            }

            // Check if user exists
            $userQuery = "SELECT id FROM users WHERE id = ?";
            $userStmt = $this->conn->prepare($userQuery);
            $userStmt->execute([$userId]);
            
            if ($userStmt->rowCount() === 0) {
                $this->sendJsonResponse(404, "User not found");
                return;
            }

            // Validate each permission override
            foreach ($data['permissions'] as $permission) {
                if (!isset($permission['permission_id']) || !is_numeric($permission['permission_id'])) {
                    $this->sendJsonResponse(400, "Invalid permission ID");
                    return;
                }
                
                if (isset($permission['project_id']) && !empty($permission['project_id'])) {
                    // Validate project exists
                    $projectQuery = "SELECT id FROM projects WHERE id = ?";
                    $projectStmt = $this->conn->prepare($projectQuery);
                    $projectStmt->execute([$permission['project_id']]);
                    
                    if ($projectStmt->rowCount() === 0) {
                        $this->sendJsonResponse(400, "Invalid project ID: " . $permission['project_id']);
                        return;
                    }
                }
            }

            // Save user permissions
            $success = $this->permissionManager->saveUserPermissions($userId, $data['permissions']);

            if ($success) {
                $this->sendJsonResponse(200, "User permissions saved successfully");
            } else {
                $this->sendJsonResponse(500, "Failed to save user permissions");
            }

        } catch (Exception $e) {
            error_log("Save user permissions error: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            $this->sendJsonResponse($e->getCode() ?: 500, $e->getMessage());
        }
    }
}

// Ensure no output before this point
if (ob_get_length()) ob_clean();

$controller = new PermissionController();
$controller->saveUserPermissions();
?>
