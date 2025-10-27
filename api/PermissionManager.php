<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/utils.php';

class PermissionManager {
    private $conn;
    private $utils;
    
    public function __construct() {
        try {
            $database = Database::getInstance();
            $this->conn = $database->getConnection();
            $this->utils = new Utils();
            
            if (!$this->conn) {
                throw new Exception("Database connection failed");
            }
        } catch (Exception $e) {
            error_log("PermissionManager initialization error: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Check if user has a specific permission
     * 
     * @param string $userId User ID
     * @param string $permissionKey Permission key (e.g., 'BUGS_CREATE')
     * @param string|null $projectId Project ID for project-specific permissions
     * @return bool True if user has permission
     */
    public function hasPermission($userId, $permissionKey, $projectId = null) {
        try {
            // First check if user has SUPER_ADMIN permission
            if ($this->isSuperAdmin($userId)) {
                return true;
            }
            
            // Get user's effective permissions
            $permissions = $this->getUserPermissions($userId, $projectId);
            
            // Check if the requested permission exists in the user's effective permissions
            return in_array($permissionKey, $permissions);
            
        } catch (Exception $e) {
            error_log("Permission check error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get all effective permissions for a user
     * 
     * @param string $userId User ID
     * @param string|null $projectId Project ID for project-specific permissions
     * @return array Array of permission keys
     */
    public function getUserPermissions($userId, $projectId = null) {
        try {
            // Get user's role
            $userRole = $this->getUserRole($userId);
            if (!$userRole) {
                return [];
            }
            
            // Get role's default permissions
            $rolePermissions = $this->getRolePermissions($userRole['role_id']);
            
            // Get user's permission overrides
            $userOverrides = $this->getUserPermissionOverrides($userId, $projectId);
            
            // Apply overrides to role permissions
            $effectivePermissions = $rolePermissions;
            
            foreach ($userOverrides as $override) {
                $permissionKey = $override['permission_key'];
                
                if ($override['granted']) {
                    // Grant permission (add if not already present)
                    if (!in_array($permissionKey, $effectivePermissions)) {
                        $effectivePermissions[] = $permissionKey;
                    }
                } else {
                    // Revoke permission (remove if present)
                    $key = array_search($permissionKey, $effectivePermissions);
                    if ($key !== false) {
                        unset($effectivePermissions[$key]);
                    }
                }
            }
            
            return array_values($effectivePermissions);
            
        } catch (Exception $e) {
            error_log("Get user permissions error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get default permissions for a role
     * 
     * @param int $roleId Role ID
     * @return array Array of permission keys
     */
    public function getRolePermissions($roleId) {
        try {
            $query = "
                SELECT p.permission_key 
                FROM permissions p
                INNER JOIN role_permissions rp ON p.id = rp.permission_id
                WHERE rp.role_id = ?
                ORDER BY p.category, p.permission_key
            ";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$roleId]);
            
            $permissions = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $permissions[] = $row['permission_key'];
            }
            
            return $permissions;
            
        } catch (Exception $e) {
            error_log("Get role permissions error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Grant a specific permission to a user
     * 
     * @param string $userId User ID
     * @param string $permissionKey Permission key
     * @param string|null $projectId Project ID for project-specific permissions
     * @return bool Success status
     */
    public function grantPermission($userId, $permissionKey, $projectId = null) {
        return $this->setUserPermission($userId, $permissionKey, $projectId, true);
    }
    
    /**
     * Revoke a specific permission from a user
     * 
     * @param string $userId User ID
     * @param string $permissionKey Permission key
     * @param string|null $projectId Project ID for project-specific permissions
     * @return bool Success status
     */
    public function revokePermission($userId, $permissionKey, $projectId = null) {
        return $this->setUserPermission($userId, $permissionKey, $projectId, false);
    }
    
    /**
     * Check if user has SUPER_ADMIN permission
     * 
     * @param string $userId User ID
     * @return bool True if user is super admin
     */
    public function isSuperAdmin($userId) {
        try {
            // Get user's role
            $userQuery = "SELECT role_id FROM users WHERE id = ?";
            $userStmt = $this->conn->prepare($userQuery);
            $userStmt->execute([$userId]);
            $user = $userStmt->fetch();
            
            if (!$user || !$user['role_id']) {
                return false;
            }
            
            // Check if role has SUPER_ADMIN permission
            $superAdminQuery = "
                SELECT COUNT(*) as count 
                FROM role_permissions rp 
                JOIN permissions p ON rp.permission_id = p.id 
                WHERE rp.role_id = ? AND p.permission_key = 'SUPER_ADMIN'
            ";
            $superAdminStmt = $this->conn->prepare($superAdminQuery);
            $superAdminStmt->execute([$user['role_id']]);
            $result = $superAdminStmt->fetch();
            
            return $result['count'] > 0;
            
        } catch (Exception $e) {
            error_log("Super admin check error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get all available permissions grouped by category
     * 
     * @return array Permissions grouped by category
     */
    public function getMasterPermissions() {
        try {
            $query = "
                SELECT id, permission_key, permission_name, permission_description, category, scope
                FROM permissions
                ORDER BY category, permission_key
            ";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            
            $permissions = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $category = $row['category'];
                if (!isset($permissions[$category])) {
                    $permissions[$category] = [];
                }
                $permissions[$category][] = $row;
            }
            
            return $permissions;
            
        } catch (Exception $e) {
            error_log("Get master permissions error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Save user permission overrides
     * 
     * @param string $userId User ID
     * @param array $permissions Array of permission overrides
     * @return bool Success status
     */
    public function saveUserPermissions($userId, $permissions) {
        try {
            $this->conn->beginTransaction();
            
            // Delete existing overrides for this user
            $deleteQuery = "DELETE FROM user_permissions WHERE user_id = ?";
            $deleteStmt = $this->conn->prepare($deleteQuery);
            $deleteStmt->execute([$userId]);
            
            // Insert new overrides
            if (!empty($permissions)) {
                $insertQuery = "
                    INSERT INTO user_permissions (user_id, permission_id, project_id, granted)
                    VALUES (?, ?, ?, ?)
                ";
                $insertStmt = $this->conn->prepare($insertQuery);
                
                foreach ($permissions as $permission) {
                    $projectId = isset($permission['project_id']) ? $permission['project_id'] : null;
                    $granted = isset($permission['granted']) ? (bool)$permission['granted'] : true;
                    
                    $insertStmt->execute([
                        $userId,
                        $permission['permission_id'],
                        $projectId,
                        $granted
                    ]);
                }
            }
            
            $this->conn->commit();
            return true;
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            error_log("Save user permissions error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get user's role information
     * 
     * @param string $userId User ID
     * @return array|null User role data
     */
    private function getUserRole($userId) {
        try {
            $query = "
                SELECT u.role_id, r.role_name, r.description, r.is_system_role
                FROM users u
                LEFT JOIN roles r ON u.role_id = r.id
                WHERE u.id = ?
            ";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$userId]);
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("Get user role error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get user's permission overrides
     * 
     * @param string $userId User ID
     * @param string|null $projectId Project ID for project-specific permissions
     * @return array User permission overrides
     */
    private function getUserPermissionOverrides($userId, $projectId = null) {
        try {
            $query = "
                SELECT p.permission_key, up.granted
                FROM user_permissions up
                INNER JOIN permissions p ON up.permission_id = p.id
                WHERE up.user_id = ?
            ";
            
            $params = [$userId];
            
            if ($projectId !== null) {
                $query .= " AND (up.project_id = ? OR up.project_id IS NULL)";
                $params[] = $projectId;
            }
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            
            $overrides = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $overrides[] = $row;
            }
            
            return $overrides;
            
        } catch (Exception $e) {
            error_log("Get user permission overrides error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Set user permission override
     * 
     * @param string $userId User ID
     * @param string $permissionKey Permission key
     * @param string|null $projectId Project ID
     * @param bool $granted Whether to grant or revoke
     * @return bool Success status
     */
    private function setUserPermission($userId, $permissionKey, $projectId, $granted) {
        try {
            // Get permission ID
            $permissionId = $this->getPermissionId($permissionKey);
            if (!$permissionId) {
                return false;
            }
            
            // Check if override already exists
            $checkQuery = "
                SELECT id FROM user_permissions 
                WHERE user_id = ? AND permission_id = ? AND (project_id = ? OR (project_id IS NULL AND ? IS NULL))
            ";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->execute([$userId, $permissionId, $projectId, $projectId]);
            
            if ($checkStmt->rowCount() > 0) {
                // Update existing override
                $updateQuery = "
                    UPDATE user_permissions 
                    SET granted = ? 
                    WHERE user_id = ? AND permission_id = ? AND (project_id = ? OR (project_id IS NULL AND ? IS NULL))
                ";
                $updateStmt = $this->conn->prepare($updateQuery);
                return $updateStmt->execute([$granted, $userId, $permissionId, $projectId, $projectId]);
            } else {
                // Insert new override
                $insertQuery = "
                    INSERT INTO user_permissions (user_id, permission_id, project_id, granted)
                    VALUES (?, ?, ?, ?)
                ";
                $insertStmt = $this->conn->prepare($insertQuery);
                return $insertStmt->execute([$userId, $permissionId, $projectId, $granted]);
            }
            
        } catch (Exception $e) {
            error_log("Set user permission error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get permission ID by permission key
     * 
     * @param string $permissionKey Permission key
     * @return int|null Permission ID
     */
    private function getPermissionId($permissionKey) {
        try {
            $query = "SELECT id FROM permissions WHERE permission_key = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$permissionKey]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? $result['id'] : null;
            
        } catch (Exception $e) {
            error_log("Get permission ID error: " . $e->getMessage());
            return null;
        }
    }
}
?>
