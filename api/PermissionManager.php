<?php
require_once __DIR__ . '/../config/database.php';

class PermissionManager {
    private $conn;
    private static $instance = null;

    /**
     * Why: FE/API historically used keys that differ from the seeded catalog
     * (e.g. MESSAGING_CREATE vs MESSAGING_SEND). Checking either key must succeed.
     */
    private static $ALIASES = [
        'MESSAGING_CREATE' => ['MESSAGING_SEND'],
        'MESSAGING_SEND' => ['MESSAGING_CREATE'],
        'MESSAGING_MANAGE' => ['MESSAGING_MANAGE_GROUPS'],
        'MESSAGING_MANAGE_GROUPS' => ['MESSAGING_MANAGE'],
        'PROJECTS_VIEW' => ['PROJECTS_VIEW_ALL', 'PROJECTS_VIEW_ASSIGNED'],
    ];
    
    private function __construct() {
        $db = Database::getInstance();
        $this->conn = $db->getConnection();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new PermissionManager();
        }
        return self::$instance;
    }

    /**
     * Keys to evaluate for a requested permission (requested key + aliases).
     * @return string[]
     */
    private function resolvePermissionKeys($permissionKey) {
        $keys = [$permissionKey];
        if (isset(self::$ALIASES[$permissionKey])) {
            foreach (self::$ALIASES[$permissionKey] as $alias) {
                $keys[] = $alias;
            }
        }
        return array_values(array_unique($keys));
    }
    
    /**
     * Check if user has a specific permission
     * 
     * @param string $userId User ID
     * @param string $permissionKey Permission key (e.g., 'BUGS_CREATE')
     * @param string|null $projectId Optional project ID for project-scoped permissions
     * @return bool True if user has permission, false otherwise
     */
    public function hasPermission($userId, $permissionKey, $projectId = null) {
        try {
            // Step 1: Check for SUPER_ADMIN permission - bypass all checks
            if ($this->hasSuperAdmin($userId)) {
                return true;
            }

            $keys = $this->resolvePermissionKeys($permissionKey);
            $anyKeyExists = false;

            foreach ($keys as $key) {
                $stmt = $this->conn->prepare("SELECT id, scope FROM permissions WHERE permission_key = ?");
                $stmt->execute([$key]);
                $permission = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$permission) {
                    continue;
                }
                $anyKeyExists = true;

                $permissionId = $permission['id'];
                $scope = $permission['scope'];

                $roleHasPermission = $this->checkRolePermission($userId, $permissionId);
                $userOverride = $this->checkUserOverride($userId, $permissionId, $projectId, $scope);

                if ($userOverride !== null) {
                    if ($userOverride) {
                        return true;
                    }
                    // Explicit deny on this key — continue aliases only if deny; treat deny as definitive for that key
                    continue;
                }

                if ($roleHasPermission) {
                    return true;
                }
            }

            if (!$anyKeyExists) {
                error_log("Permission not found: $permissionKey");
            }
            return false;
            
        } catch (Exception $e) {
            error_log("Permission check error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Why: Bridge legacy JWT role ENUM with RBAC so admins without remapped role_id
     * still pass until all callers use permissions alone.
     *
     * @param string $userId
     * @param string $permissionKey
     * @param string|null $legacyRole From JWT / users.role
     * @param string|null $projectId
     * @return bool
     */
    public function hasPermissionOrAdmin($userId, $permissionKey, $legacyRole = null, $projectId = null) {
        if ($legacyRole !== null && strtolower((string)$legacyRole) === 'admin') {
            return true;
        }
        return $this->hasPermission($userId, $permissionKey, $projectId);
    }
    
    /**
     * Check if user has SUPER_ADMIN permission
     */
    private function hasSuperAdmin($userId) {
        try {
            // Check if user has SUPER_ADMIN permission directly via user_permissions
            $stmt = $this->conn->prepare(
                "SELECT 1 FROM user_permissions up 
                 INNER JOIN permissions p ON up.permission_id = p.id 
                 WHERE up.user_id = ? AND p.permission_key = 'SUPER_ADMIN' AND up.granted = 1"
            );
            $stmt->execute([$userId]);
            if ($stmt->rowCount() > 0) {
                return true;
            }
            
            // Check if user's role has SUPER_ADMIN permission
            $stmt = $this->conn->prepare(
                "SELECT 1 FROM role_permissions rp
                 INNER JOIN permissions p ON rp.permission_id = p.id
                 INNER JOIN users u ON rp.role_id = u.role_id
                 WHERE u.id = ? AND p.permission_key = 'SUPER_ADMIN'"
            );
            $stmt->execute([$userId]);
            return $stmt->rowCount() > 0;
            
        } catch (Exception $e) {
            error_log("Super admin check error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if user's role has the permission
     */
    private function checkRolePermission($userId, $permissionId) {
        try {
            $stmt = $this->conn->prepare(
                "SELECT 1 FROM role_permissions rp
                 INNER JOIN users u ON rp.role_id = u.role_id
                 WHERE u.id = ? AND rp.permission_id = ?"
            );
            $stmt->execute([$userId, $permissionId]);
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            error_log("Role permission check error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check user permission overrides
     * Returns true (grant), false (deny), or null (no override)
     */
    private function checkUserOverride($userId, $permissionId, $projectId, $scope) {
        try {
            // If it's a project-scoped permission and we have a project ID
            if ($scope === 'project' && $projectId) {
                // First check for project-specific override
                $stmt = $this->conn->prepare(
                    "SELECT granted FROM user_permissions 
                     WHERE user_id = ? AND permission_id = ? AND project_id = ?"
                );
                $stmt->execute([$userId, $permissionId, $projectId]);
                $projectOverride = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($projectOverride) {
                    return (bool)$projectOverride['granted'];
                }
            }
            
            // Check for global override (project_id is NULL)
            $stmt = $this->conn->prepare(
                "SELECT granted FROM user_permissions 
                 WHERE user_id = ? AND permission_id = ? AND project_id IS NULL"
            );
            $stmt->execute([$userId, $permissionId]);
            $globalOverride = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($globalOverride) {
                return (bool)$globalOverride['granted'];
            }
            
            return null; // No override exists
            
        } catch (Exception $e) {
            error_log("User override check error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get all effective permissions for a user
     * 
     * @param string $userId User ID
     * @param string|null $projectId Optional project ID for project-scoped permissions
     * @return array Array of permission keys that user has
     */
    public function getUserEffectivePermissions($userId, $projectId = null) {
        try {
            $permissions = [];
            
            // Check if user has SUPER_ADMIN
            if ($this->hasSuperAdmin($userId)) {
                // Return all permissions
                $stmt = $this->conn->query("SELECT permission_key FROM permissions");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $permissions[] = $row['permission_key'];
                }
                return $permissions;
            }
            
            // Get role-based permissions
            $stmt = $this->conn->prepare(
                "SELECT DISTINCT p.permission_key 
                 FROM role_permissions rp
                 INNER JOIN permissions p ON rp.permission_id = p.id
                 INNER JOIN users u ON rp.role_id = u.role_id
                 WHERE u.id = ?"
            );
            $stmt->execute([$userId]);
            $rolePermissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Get user permission overrides
            $stmt = $this->conn->prepare(
                "SELECT p.permission_key, up.granted, up.project_id
                 FROM user_permissions up
                 INNER JOIN permissions p ON up.permission_id = p.id
                 WHERE up.user_id = ?"
            );
            $stmt->execute([$userId]);
            $overrides = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Build effective permissions list
            // Start with role permissions
            $effective = array_fill_keys($rolePermissions, true);
            
            // Apply overrides
            foreach ($overrides as $override) {
                $key = $override['permission_key'];
                $granted = (bool)$override['granted'];
                $overrideProjectId = $override['project_id'];
                
                // Check if override applies to this context
                if ($overrideProjectId === null || $overrideProjectId === $projectId) {
                    if ($granted) {
                        $effective[$key] = true;
                    } else {
                        unset($effective[$key]);
                    }
                }
            }
            
            $keys = array_keys(array_filter($effective));

            // Why: Expand aliases so FE checks for either canonical key succeed
            // (e.g. role has MESSAGING_SEND → also expose MESSAGING_CREATE).
            $expanded = [];
            foreach ($keys as $key) {
                $expanded[$key] = true;
                if (isset(self::$ALIASES[$key])) {
                    foreach (self::$ALIASES[$key] as $alias) {
                        $expanded[$alias] = true;
                    }
                }
            }
            return array_keys($expanded);
            
        } catch (Exception $e) {
            error_log("Get effective permissions error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get role permissions
     * 
     * @param int $roleId Role ID
     * @return array Array of permission IDs
     */
    public function getRolePermissions($roleId) {
        try {
            $stmt = $this->conn->prepare(
                "SELECT permission_id FROM role_permissions WHERE role_id = ?"
            );
            $stmt->execute([$roleId]);
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            error_log("Get role permissions error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get user permission overrides
     * 
     * @param string $userId User ID
     * @return array Array of user_permissions entries
     */
    public function getUserPermissionOverrides($userId) {
        try {
            $stmt = $this->conn->prepare(
                "SELECT up.*, p.permission_key, p.permission_name, p.category
                 FROM user_permissions up
                 INNER JOIN permissions p ON up.permission_id = p.id
                 WHERE up.user_id = ?"
            );
            $stmt->execute([$userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Get user permission overrides error: " . $e->getMessage());
            return [];
        }
    }
}

