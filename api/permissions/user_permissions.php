<?php
// Ensure no output before this point
if (ob_get_length()) ob_clean();
require_once '../BaseAPI.php';

class UserPermissionsController extends BaseAPI {
    public function __construct() {
        parent::__construct();
    }

    public function dispatch() {
        $method = $_SERVER['REQUEST_METHOD'];
        $userId = $_GET['userId'] ?? null;
        
        if (!$userId) {
            $this->sendJsonResponse(400, "User ID is required");
            return;
        }

        try {
            // Validate token
            $decoded = $this->validateToken();
            if (!$decoded || !isset($decoded->user_id)) {
                $this->sendJsonResponse(401, "Authentication required");
                return;
            }
            
            if ($method === 'GET') {
                $this->getUserPermissions($userId);
            } elseif ($method === 'POST') {
                // Check permission for managing user permissions
                $currentUserId = $decoded->user_id;
                $pm = PermissionManager::getInstance();
                if (!$pm->hasPermission($currentUserId, 'USERS_MANAGE_PERMISSIONS')) {
                    $this->sendJsonResponse(403, "Access denied. You do not have permission to manage user permissions.");
                    return;
                }
                $this->saveUserPermissions($userId);
            } else {
                $this->sendJsonResponse(405, "Method not allowed");
            }
        } catch (Exception $e) {
            error_log("User permissions API error: " . $e->getMessage());
            $this->sendJsonResponse(500, "Error: " . $e->getMessage());
        }
    }

    private function getUserPermissions($userId) {
        try {
            $pm = PermissionManager::getInstance();
            
            // Get user's effective permissions
            $effectivePermissions = $pm->getUserEffectivePermissions($userId);
            
            // Get user's role permissions
            $stmt = $this->conn->prepare(
                "SELECT p.permission_key
                 FROM role_permissions rp
                 INNER JOIN permissions p ON rp.permission_id = p.id
                 INNER JOIN users u ON rp.role_id = u.role_id
                 WHERE u.id = ?"
            );
            $stmt->execute([$userId]);
            $rolePermissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Get user permission overrides (granted=true)
            $stmt = $this->conn->prepare(
                "SELECT p.permission_key
                 FROM user_permissions up
                 INNER JOIN permissions p ON up.permission_id = p.id
                 WHERE up.user_id = ? AND up.granted = 1"
            );
            $stmt->execute([$userId]);
            $grantedOverrides = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Get user permission revokes (granted=false)
            $stmt = $this->conn->prepare(
                "SELECT p.permission_key
                 FROM user_permissions up
                 INNER JOIN permissions p ON up.permission_id = p.id
                 WHERE up.user_id = ? AND up.granted = 0"
            );
            $stmt->execute([$userId]);
            $revokedOverrides = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $response = [
                'effective_permissions' => $effectivePermissions,
                'role_permissions' => $rolePermissions,
                'overrides' => $grantedOverrides, // Permissions explicitly granted
                'revoked' => $revokedOverrides // Permissions explicitly revoked
            ];
            
            $this->sendJsonResponse(200, "User permissions retrieved successfully", $response);
            
        } catch (Exception $e) {
            error_log("Get user permissions error: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to retrieve user permissions");
        }
    }

    private function saveUserPermissions($userId) {
        try {
            $data = $this->getRequestData();
            
            if (!isset($data['overrides']) || !is_array($data['overrides'])) {
                $this->sendJsonResponse(400, "Invalid overrides format");
                return;
            }
            
            $this->conn->beginTransaction();
            
            try {
                // Delete existing user permissions
                $stmt = $this->conn->prepare("DELETE FROM user_permissions WHERE user_id = ?");
                $stmt->execute([$userId]);
                
                // Insert new overrides
                if (!empty($data['overrides'])) {
                    $stmt = $this->conn->prepare(
                        "INSERT INTO user_permissions (user_id, permission_id, project_id, granted) 
                         VALUES (?, ?, ?, ?)"
                    );
                    
                    foreach ($data['overrides'] as $override) {
                        if (!isset($override['permission_id']) || !isset($override['granted'])) {
                            continue;
                        }
                        
                        $permissionId = $override['permission_id'];
                        $granted = (bool)$override['granted'];
                        $projectId = isset($override['project_id']) && $override['project_id'] ? $override['project_id'] : null;
                        
                        $stmt->execute([$userId, $permissionId, $projectId, $granted ? 1 : 0]);
                    }
                }
                
                $this->conn->commit();
                $this->sendJsonResponse(200, "User permissions updated successfully");
                
            } catch (Exception $e) {
                $this->conn->rollBack();
                throw $e;
            }
            
        } catch (Exception $e) {
            error_log("Save user permissions error: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to save user permissions");
        }
    }
}

$controller = new UserPermissionsController();
$controller->dispatch();
?>

