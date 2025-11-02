<?php
// Ensure no output before this point
if (ob_get_length()) ob_clean();
require_once '../BaseAPI.php';

class RolesController extends BaseAPI {
    public function __construct() {
        parent::__construct();
    }

    public function dispatch() {
        $method = $_SERVER['REQUEST_METHOD'];
        
        try {
            // Validate token and check permission first
            $decoded = $this->validateToken();
            if (!$decoded || !isset($decoded->user_id)) {
                $this->sendJsonResponse(401, "Authentication required");
                return;
            }
            
            $userId = $decoded->user_id;
            
            // Check user's role for permission-based access
            $stmt = $this->conn->prepare("SELECT role, role_id FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // GET /api/settings/roles - List all roles (allow all authenticated users)
            if ($method === 'GET') {
                $this->listRoles();
            }
            // POST /api/settings/roles - Create new role (requires admin)
            elseif ($method === 'POST') {
                if (!$user || $user['role'] !== 'admin') {
                    $this->sendJsonResponse(403, "Access denied. Admin access required.");
                    return;
                }
                $this->createRole();
            }
            // PUT /api/settings/roles/{roleId} - Update role (requires admin)
            elseif ($method === 'PUT') {
                if (!$user || $user['role'] !== 'admin') {
                    $this->sendJsonResponse(403, "Access denied. Admin access required.");
                    return;
                }
                $this->updateRole();
            }
            // DELETE /api/settings/roles/{roleId} - Delete role (requires admin)
            elseif ($method === 'DELETE') {
                if (!$user || $user['role'] !== 'admin') {
                    $this->sendJsonResponse(403, "Access denied. Admin access required.");
                    return;
                }
                $this->deleteRole();
            }
            else {
                $this->sendJsonResponse(405, "Method not allowed");
            }
        } catch (Exception $e) {
            error_log("Roles API error: " . $e->getMessage());
            $this->sendJsonResponse(500, "Error: " . $e->getMessage());
        }
    }

    private function listRoles() {
        try {
            $stmt = $this->conn->query(
                "SELECT r.*, 
                 COUNT(rp.permission_id) as permission_count
                 FROM roles r
                 LEFT JOIN role_permissions rp ON r.id = rp.role_id
                 GROUP BY r.id
                 ORDER BY r.is_system_role DESC, r.role_name ASC"
            );
            $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Fetch permissions for each role
            foreach ($roles as &$role) {
                $permStmt = $this->conn->prepare(
                    "SELECT p.id, p.permission_key, p.permission_name, p.category, p.scope
                     FROM role_permissions rp
                     INNER JOIN permissions p ON rp.permission_id = p.id
                     WHERE rp.role_id = ?"
                );
                $permStmt->execute([$role['id']]);
                $role['permissions'] = $permStmt->fetchAll(PDO::FETCH_ASSOC);
            }
            unset($role);
            
            $this->sendJsonResponse(200, "Roles retrieved successfully", $roles);
        } catch (Exception $e) {
            error_log("List roles error: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to retrieve roles");
        }
    }

    private function createRole() {
        try {
            $data = $this->getRequestData();
            
            if (!isset($data['role_name']) || empty($data['role_name'])) {
                $this->sendJsonResponse(400, "Role name is required");
                return;
            }
            
            $roleName = trim($data['role_name']);
            $description = isset($data['description']) ? trim($data['description']) : '';
            
            // Check if role already exists
            $stmt = $this->conn->prepare("SELECT id FROM roles WHERE role_name = ?");
            $stmt->execute([$roleName]);
            if ($stmt->rowCount() > 0) {
                $this->sendJsonResponse(409, "Role name already exists");
                return;
            }
            
            // Insert role
            $stmt = $this->conn->prepare(
                "INSERT INTO roles (role_name, description, is_system_role) VALUES (?, ?, 0)"
            );
            $stmt->execute([$roleName, $description]);
            
            $roleId = $this->conn->lastInsertId();
            
            // Add permissions if provided
            if (isset($data['permission_ids']) && is_array($data['permission_ids'])) {
                $this->updateRolePermissions($roleId, $data['permission_ids']);
            }
            
            $this->sendJsonResponse(201, "Role created successfully", ['id' => $roleId]);
            
        } catch (Exception $e) {
            error_log("Create role error: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to create role");
        }
    }

    private function updateRole() {
        try {
            $roleId = $_GET['roleId'] ?? null;
            if (!$roleId) {
                $this->sendJsonResponse(400, "Role ID is required");
                return;
            }
            
            $data = $this->getRequestData();
            
            // Update role details if provided
            if (isset($data['role_name']) || isset($data['description'])) {
                $fields = [];
                $params = [];
                
                if (isset($data['role_name'])) {
                    $fields[] = "role_name = ?";
                    $params[] = trim($data['role_name']);
                }
                
                if (isset($data['description'])) {
                    $fields[] = "description = ?";
                    $params[] = trim($data['description']);
                }
                
                $params[] = $roleId;
                $sql = "UPDATE roles SET " . implode(", ", $fields) . " WHERE id = ?";
                $stmt = $this->conn->prepare($sql);
                $stmt->execute($params);
            }
            
            // Update permissions if provided
            if (isset($data['permission_ids']) && is_array($data['permission_ids'])) {
                $this->updateRolePermissions($roleId, $data['permission_ids']);
            }
            
            $this->sendJsonResponse(200, "Role updated successfully");
            
        } catch (Exception $e) {
            error_log("Update role error: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to update role");
        }
    }

    private function deleteRole() {
        try {
            $roleId = $_GET['roleId'] ?? null;
            if (!$roleId) {
                $this->sendJsonResponse(400, "Role ID is required");
                return;
            }
            
            // Check if it's a system role
            $stmt = $this->conn->prepare("SELECT is_system_role FROM roles WHERE id = ?");
            $stmt->execute([$roleId]);
            $role = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$role) {
                $this->sendJsonResponse(404, "Role not found");
                return;
            }
            
            if ($role['is_system_role']) {
                $this->sendJsonResponse(403, "Cannot delete system role");
                return;
            }
            
            // Check if role is assigned to any users
            $stmt = $this->conn->prepare("SELECT COUNT(*) as count FROM users WHERE role_id = ?");
            $stmt->execute([$roleId]);
            $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            if ($count > 0) {
                $this->sendJsonResponse(409, "Cannot delete role. It is assigned to $count user(s)");
                return;
            }
            
            // Delete role (role_permissions will be cascade deleted)
            $stmt = $this->conn->prepare("DELETE FROM roles WHERE id = ?");
            $stmt->execute([$roleId]);
            
            $this->sendJsonResponse(200, "Role deleted successfully");
            
        } catch (Exception $e) {
            error_log("Delete role error: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to delete role");
        }
    }

    private function updateRolePermissions($roleId, $permissionIds) {
        // Delete existing permissions
        $stmt = $this->conn->prepare("DELETE FROM role_permissions WHERE role_id = ?");
        $stmt->execute([$roleId]);
        
        // Insert new permissions
        if (!empty($permissionIds)) {
            $stmt = $this->conn->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
            foreach ($permissionIds as $permissionId) {
                $stmt->execute([$roleId, $permissionId]);
            }
        }
    }
}

$controller = new RolesController();
$controller->dispatch();
?>
