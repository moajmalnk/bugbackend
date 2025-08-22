<?php
require_once __DIR__ . '/../BaseAPI.php';

class ChatGroupController extends BaseAPI {
    
    public function __construct() {
        parent::__construct();
    }
    
    /**
     * Create a new chat group
     */
    public function create() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }
        
        try {
            $decoded = $this->validateToken();
            $userId = $decoded->user_id;
            $userRole = $decoded->role;
            
            // Only admins can create groups
            if ($userRole !== 'admin') {
                $this->sendJsonResponse(403, "Only admins can create chat groups");
                return;
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($input['name']) || !isset($input['project_id'])) {
                $this->sendJsonResponse(400, "Name and project_id are required");
                return;
            }
            
            $name = trim($input['name']);
            $description = trim($input['description'] ?? '');
            $projectId = $input['project_id'];
            
            if (empty($name)) {
                $this->sendJsonResponse(400, "Group name cannot be empty");
                return;
            }
            
            // Validate project exists and user has access
            if (!$this->validateProjectAccess($userId, $userRole, $projectId)) {
                $this->sendJsonResponse(403, "Access denied to this project");
                return;
            }
            
            // Check if group name already exists in this project
            $checkStmt = $this->conn->prepare("SELECT id FROM chat_groups WHERE project_id = ? AND name = ? AND is_active = 1");
            $checkStmt->execute([$projectId, $name]);
            if ($checkStmt->fetch()) {
                $this->sendJsonResponse(409, "A group with this name already exists in this project");
                return;
            }
            
            $groupId = $this->utils->generateUUID();
            
            $this->conn->beginTransaction();
            
            // Create the chat group
            $stmt = $this->conn->prepare("
                INSERT INTO chat_groups (id, name, description, project_id, created_by) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$groupId, $name, $description, $projectId, $userId]);
            
            // Add all project members to the group
            $memberStmt = $this->conn->prepare("
                INSERT INTO chat_group_members (group_id, user_id)
                SELECT ?, user_id FROM project_members WHERE project_id = ?
            ");
            $memberStmt->execute([$groupId, $projectId]);
            
            // Also add the project creator if not already a member
            $creatorStmt = $this->conn->prepare("
                INSERT IGNORE INTO chat_group_members (group_id, user_id)
                SELECT ?, created_by FROM projects WHERE id = ?
            ");
            $creatorStmt->execute([$groupId, $projectId]);
            
            // Always add the admin (group creator) as a member of the group
            $addAdminStmt = $this->conn->prepare("
                INSERT IGNORE INTO chat_group_members (group_id, user_id)
                VALUES (?, ?)
            ");
            $addAdminStmt->execute([$groupId, $userId]);
            
            $this->conn->commit();
            
            // Get the created group with member count
            $group = $this->getGroupWithDetails($groupId);
            
            $this->sendJsonResponse(201, "Chat group created successfully", $group);
            
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollback();
            }
            error_log("Error creating chat group: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to create chat group: " . $e->getMessage());
        }
    }
    
    /**
     * Get all chat groups for a project
     */
    public function getByProject($projectId) {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }
        
        try {
            $decoded = $this->validateToken();
            $userId = $decoded->user_id;
            $userRole = $decoded->role;
            
            // Validate project access
            if (!$this->validateProjectAccess($userId, $userRole, $projectId)) {
                $this->sendJsonResponse(403, "Access denied to this project");
                return;
            }
            
            $query = "
                SELECT 
                    cg.*,
                    COUNT(cgm.user_id) as member_count,
                    MAX(cm.created_at) as last_message_at,
                    CASE WHEN cgm.user_id IS NOT NULL THEN 1 ELSE 0 END as is_member
                FROM chat_groups cg
                LEFT JOIN chat_group_members cgm ON cg.id = cgm.group_id
                LEFT JOIN chat_messages cm ON cg.id = cm.group_id AND cm.is_deleted = 0
                WHERE cg.project_id = ? AND cg.is_active = 1
                GROUP BY cg.id
                ORDER BY cg.created_at DESC
            ";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$projectId]);
            $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $this->sendJsonResponse(200, "Chat groups retrieved successfully", $groups);
            
        } catch (Exception $e) {
            error_log("Error fetching chat groups: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to retrieve chat groups: " . $e->getMessage());
        }
    }
    
    /**
     * Update a chat group
     */
    public function update($groupId) {
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }
        
        try {
            $decoded = $this->validateToken();
            $userId = $decoded->user_id;
            $userRole = $decoded->role;
            
            // Only admins can update groups
            if ($userRole !== 'admin') {
                $this->sendJsonResponse(403, "Only admins can update chat groups");
                return;
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            // Check if group exists and user has access
            $group = $this->getGroupWithAccess($groupId, $userId, $userRole);
            if (!$group) {
                $this->sendJsonResponse(404, "Chat group not found or access denied");
                return;
            }
            
            $name = trim($input['name'] ?? $group['name']);
            $description = trim($input['description'] ?? $group['description']);
            
            if (empty($name)) {
                $this->sendJsonResponse(400, "Group name cannot be empty");
                return;
            }
            
            // Check if name already exists in this project (excluding current group)
            $checkStmt = $this->conn->prepare("
                SELECT id FROM chat_groups 
                WHERE project_id = ? AND name = ? AND id != ? AND is_active = 1
            ");
            $checkStmt->execute([$group['project_id'], $name, $groupId]);
            if ($checkStmt->fetch()) {
                $this->sendJsonResponse(409, "A group with this name already exists in this project");
                return;
            }
            
            $stmt = $this->conn->prepare("
                UPDATE chat_groups 
                SET name = ?, description = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$name, $description, $groupId]);
            
            $updatedGroup = $this->getGroupWithDetails($groupId);
            $this->sendJsonResponse(200, "Chat group updated successfully", $updatedGroup);
            
        } catch (Exception $e) {
            error_log("Error updating chat group: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to update chat group: " . $e->getMessage());
        }
    }
    
    /**
     * Delete a chat group
     */
    public function delete($groupId) {
        if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }
        
        try {
            $decoded = $this->validateToken();
            $userId = $decoded->user_id;
            $userRole = $decoded->role;
            
            // Only admins can delete groups
            if ($userRole !== 'admin') {
                $this->sendJsonResponse(403, "Only admins can delete chat groups");
                return;
            }
            
            // Check if group exists and user has access
            $group = $this->getGroupWithAccess($groupId, $userId, $userRole);
            if (!$group) {
                $this->sendJsonResponse(404, "Chat group not found or access denied");
                return;
            }
            
            // Soft delete the group
            $stmt = $this->conn->prepare("
                UPDATE chat_groups 
                SET is_active = 0, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$groupId]);
            
            $this->sendJsonResponse(200, "Chat group deleted successfully");
            
        } catch (Exception $e) {
            error_log("Error deleting chat group: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to delete chat group: " . $e->getMessage());
        }
    }
    
    /**
     * Get group members
     */
    public function getMembers($groupId) {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }
        
        try {
            $decoded = $this->validateToken();
            $userId = $decoded->user_id;
            $userRole = $decoded->role;
            
            // Check if group exists and user has access
            $group = $this->getGroupWithAccess($groupId, $userId, $userRole);
            if (!$group) {
                $this->sendJsonResponse(404, "Chat group not found or access denied");
                return;
            }
            
            $query = "
                SELECT 
                    u.id, u.username, u.email, u.role,
                    cgm.joined_at, cgm.last_read_at
                FROM chat_group_members cgm
                JOIN users u ON cgm.user_id = u.id
                WHERE cgm.group_id = ?
                ORDER BY cgm.joined_at ASC
            ";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$groupId]);
            $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $this->sendJsonResponse(200, "Group members retrieved successfully", $members);
            
        } catch (Exception $e) {
            error_log("Error fetching group members: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to retrieve group members: " . $e->getMessage());
        }
    }
    
    /**
     * Add member to group
     */
    public function addMember($groupId) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }
        
        try {
            $decoded = $this->validateToken();
            $userId = $decoded->user_id;
            $userRole = $decoded->role;
            
            // Only admins can add members
            if ($userRole !== 'admin') {
                $this->sendJsonResponse(403, "Only admins can add members to groups");
                return;
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $memberId = $input['user_id'] ?? null;
            
            if (!$memberId) {
                $this->sendJsonResponse(400, "user_id is required");
                return;
            }
            
            // Check if group exists and user has access
            $group = $this->getGroupWithAccess($groupId, $userId, $userRole);
            if (!$group) {
                $this->sendJsonResponse(404, "Chat group not found or access denied");
                return;
            }
            
            // Check if user is already a member
            $checkStmt = $this->conn->prepare("SELECT 1 FROM chat_group_members WHERE group_id = ? AND user_id = ?");
            $checkStmt->execute([$groupId, $memberId]);
            if ($checkStmt->fetch()) {
                $this->sendJsonResponse(409, "User is already a member of this group");
                return;
            }
            
            // Check if user has access to the project
            if (!$this->validateProjectAccess($memberId, 'user', $group['project_id'])) {
                $this->sendJsonResponse(403, "User does not have access to this project");
                return;
            }
            
            $stmt = $this->conn->prepare("INSERT INTO chat_group_members (group_id, user_id) VALUES (?, ?)");
            $stmt->execute([$groupId, $memberId]);
            
            $this->sendJsonResponse(200, "Member added successfully");
            
        } catch (Exception $e) {
            error_log("Error adding member: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to add member: " . $e->getMessage());
        }
    }

    /**
     * Add multiple members to a chat group
     */
    public function addMembers($groupId, $userIds) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }
        
        try {
            $decoded = $this->validateToken();
            $userId = $decoded->user_id;
            $userRole = $decoded->role;
            
            // Only admins can add members
            if ($userRole !== 'admin') {
                $this->sendJsonResponse(403, "Only admins can add members to groups");
                return;
            }
            
            if (!$userIds || !is_array($userIds) || empty($userIds)) {
                $this->sendJsonResponse(400, "user_ids array is required");
                return;
            }
            
            // Check if group exists and user has access
            $group = $this->getGroupWithAccess($groupId, $userId, $userRole);
            if (!$group) {
                $this->sendJsonResponse(404, "Chat group not found or access denied");
                return;
            }
            
            $this->conn->beginTransaction();
            
            $addedCount = 0;
            $errors = [];
            
            foreach ($userIds as $memberId) {
                try {
                    // Check if user is already a member
                    $checkStmt = $this->conn->prepare("SELECT 1 FROM chat_group_members WHERE group_id = ? AND user_id = ?");
                    $checkStmt->execute([$groupId, $memberId]);
                    if ($checkStmt->fetch()) {
                        $errors[] = "User $memberId is already a member of this group";
                        continue;
                    }
                    
                    // Check if user exists and has access to the project
                    if (!$this->validateProjectAccess($memberId, 'user', $group['project_id'])) {
                        $errors[] = "User $memberId does not have access to this project";
                        continue;
                    }
                    
                    $stmt = $this->conn->prepare("INSERT INTO chat_group_members (group_id, user_id) VALUES (?, ?)");
                    $stmt->execute([$groupId, $memberId]);
                    $addedCount++;
                    
                } catch (Exception $e) {
                    $errors[] = "Failed to add user $memberId: " . $e->getMessage();
                }
            }
            
            $this->conn->commit();
            
            $message = "Successfully added $addedCount member(s) to the group";
            if (!empty($errors)) {
                $message .= ". Errors: " . implode(", ", $errors);
            }
            
            $this->sendJsonResponse(200, $message, [
                'added_count' => $addedCount,
                'errors' => $errors
            ]);
            
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log("Error adding members: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to add members: " . $e->getMessage());
        }
    }
    
    /**
     * Remove member from group
     */
    public function removeMember($groupId) {
        if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }
        
        try {
            $decoded = $this->validateToken();
            $userId = $decoded->user_id;
            $userRole = $decoded->role;
            
            // Only admins can remove members
            if ($userRole !== 'admin') {
                $this->sendJsonResponse(403, "Only admins can remove members from groups");
                return;
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $memberId = $input['user_id'] ?? null;
            
            if (!$memberId) {
                $this->sendJsonResponse(400, "user_id is required");
                return;
            }
            
            // Check if group exists and user has access
            $group = $this->getGroupWithAccess($groupId, $userId, $userRole);
            if (!$group) {
                $this->sendJsonResponse(404, "Chat group not found or access denied");
                return;
            }
            
            // Cannot remove the group creator
            if ($group['created_by'] === $memberId) {
                $this->sendJsonResponse(403, "Cannot remove the group creator");
                return;
            }
            
            $stmt = $this->conn->prepare("DELETE FROM chat_group_members WHERE group_id = ? AND user_id = ?");
            $stmt->execute([$groupId, $memberId]);
            
            $this->sendJsonResponse(200, "Member removed successfully");
            
        } catch (Exception $e) {
            error_log("Error removing member: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to remove member: " . $e->getMessage());
        }
    }

    /**
     * Remove multiple members from a chat group
     */
    public function removeMembers($groupId, $userIds) {
        if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }
        
        try {
            $decoded = $this->validateToken();
            $userId = $decoded->user_id;
            $userRole = $decoded->role;
            
            // Only admins can remove members
            if ($userRole !== 'admin') {
                $this->sendJsonResponse(403, "Only admins can remove members from groups");
                return;
            }
            
            if (!$userIds || !is_array($userIds) || empty($userIds)) {
                $this->sendJsonResponse(400, "user_ids array is required");
                return;
            }
            
            // Check if group exists and user has access
            $group = $this->getGroupWithAccess($groupId, $userId, $userRole);
            if (!$group) {
                $this->sendJsonResponse(404, "Chat group not found or access denied");
                return;
            }
            
            $this->conn->beginTransaction();
            
            $removedCount = 0;
            $errors = [];
            
            foreach ($userIds as $memberId) {
                try {
                    // Cannot remove the group creator
                    if ($group['created_by'] === $memberId) {
                        $errors[] = "Cannot remove the group creator ($memberId)";
                        continue;
                    }
                    
                    // Check if user is actually a member
                    $checkStmt = $this->conn->prepare("SELECT 1 FROM chat_group_members WHERE group_id = ? AND user_id = ?");
                    $checkStmt->execute([$groupId, $memberId]);
                    if (!$checkStmt->fetch()) {
                        $errors[] = "User $memberId is not a member of this group";
                        continue;
                    }
                    
                    $stmt = $this->conn->prepare("DELETE FROM chat_group_members WHERE group_id = ? AND user_id = ?");
                    $stmt->execute([$groupId, $memberId]);
                    $removedCount++;
                    
                } catch (Exception $e) {
                    $errors[] = "Failed to remove user $memberId: " . $e->getMessage();
                }
            }
            
            $this->conn->commit();
            
            $message = "Successfully removed $removedCount member(s) from the group";
            if (!empty($errors)) {
                $message .= ". Errors: " . implode(", ", $errors);
            }
            
            $this->sendJsonResponse(200, $message, [
                'removed_count' => $removedCount,
                'errors' => $errors
            ]);
            
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log("Error removing members: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to remove members: " . $e->getMessage());
        }
    }
    
    /**
     * Helper methods
     */
    private function validateProjectAccess($userId, $userRole, $projectId) {
        if ($userRole === 'admin') {
            return true;
        }
        
        $query = "
            SELECT 1 FROM (
                SELECT 1 FROM project_members WHERE user_id = ? AND project_id = ?
                UNION
                SELECT 1 FROM projects WHERE created_by = ? AND id = ?
            ) as access_check LIMIT 1
        ";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$userId, $projectId, $userId, $projectId]);
        return (bool) $stmt->fetch();
    }
    
    private function getGroupWithAccess($groupId, $userId, $userRole) {
        if ($userRole === 'admin') {
            $query = "SELECT * FROM chat_groups WHERE id = ? AND is_active = 1";
            $params = [$groupId];
        } else {
            $query = "
                SELECT cg.* FROM chat_groups cg
                JOIN chat_group_members cgm ON cg.id = cgm.group_id
                WHERE cg.id = ? AND cg.is_active = 1 AND cgm.user_id = ?
            ";
            $params = [$groupId, $userId];
        }
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    private function getGroupWithDetails($groupId) {
        $query = "
            SELECT 
                cg.*,
                COUNT(cgm.user_id) as member_count,
                MAX(cm.created_at) as last_message_at
            FROM chat_groups cg
            LEFT JOIN chat_group_members cgm ON cg.id = cgm.group_id
            LEFT JOIN chat_messages cm ON cg.id = cm.group_id AND cm.is_deleted = 0
            WHERE cg.id = ?
            GROUP BY cg.id
        ";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$groupId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
} 