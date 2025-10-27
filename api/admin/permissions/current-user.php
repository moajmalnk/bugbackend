<?php
require_once __DIR__ . '/../../BaseAPI.php';
require_once __DIR__ . '/../../../config/cors.php';

// Handle CORS
handleCORS();

class CurrentUserPermissionsAPI extends BaseAPI {
    public function handleRequest($callback = null) {
        try {
            // No specific permission required - any authenticated user can view their own permissions
            
            $userId = $this->getCurrentUserId();
            if (!$userId) {
                throw new Exception('User not authenticated', 401);
            }
            
            // Get user data
            $stmt = $this->conn->prepare("
                SELECT 
                    u.id, u.username, u.email, u.role_id,
                    r.role_name, r.description as role_description
                FROM users u
                LEFT JOIN roles r ON u.role_id = r.id
                WHERE u.id = ?
            ");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                throw new Exception('User not found', 404);
            }
            
            // Get effective permissions
            $stmt = $this->conn->prepare("
                SELECT DISTINCT p.permission_key
                FROM role_permissions rp
                JOIN permissions p ON rp.permission_id = p.id
                WHERE rp.role_id = ?
            ");
            $stmt->execute([$user['role_id']]);
            $permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Get user-specific permission overrides
            $stmt = $this->conn->prepare("
                SELECT p.permission_key, up.granted, up.project_id
                FROM user_permissions up
                JOIN permissions p ON up.permission_id = p.id
                WHERE up.user_id = ?
            ");
            $stmt->execute([$userId]);
            $overrides = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Apply overrides to permissions
            $effectivePermissions = $permissions;
            foreach ($overrides as $override) {
                if ($override['granted']) {
                    // Add permission if not already present
                    if (!in_array($override['permission_key'], $effectivePermissions)) {
                        $effectivePermissions[] = $override['permission_key'];
                    }
                } else {
                    // Remove permission
                    $effectivePermissions = array_filter($effectivePermissions, function($perm) use ($override) {
                        return $perm !== $override['permission_key'];
                    });
                }
            }
            
            $responseData = [
                'user' => $user,
                'role' => [
                    'id' => $user['role_id'],
                    'role_name' => $user['role_name'],
                    'description' => $user['role_description']
                ],
                'effective_permissions' => array_values($effectivePermissions),
                'role_permissions' => $permissions,
                'permission_overrides' => $overrides
            ];
            
            $this->sendJsonResponse(200, "Success", $responseData);
            
        } catch (Exception $e) {
            $this->sendJsonResponse($e->getCode() ?: 500, $e->getMessage());
        }
    }
}

$api = new CurrentUserPermissionsAPI();
$api->handleRequest();
?>
