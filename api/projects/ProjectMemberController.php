<?php

require_once __DIR__ . '/../BaseAPI.php';

class ProjectMemberController extends BaseAPI {
    
    /**
     * Get all projects a user is a member of
     * 
     * @param string $userId The user ID to check
     * @return array List of projects the user is a member of
     */
    public function getUserProjects($userId) {
        try {
            // First check projects where user is explicitly added as a member
            $query = "SELECT pm.project_id, pm.role
                     FROM project_members pm
                     WHERE pm.user_id = ?";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$userId]);
            $memberProjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get user role
            $userQuery = "SELECT role FROM users WHERE id = ?";
            $userStmt = $this->conn->prepare($userQuery);
            $userStmt->execute([$userId]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            
            // Admins have access to all projects
            if ($user && $user['role'] === 'admin') {
                $projectQuery = "SELECT id as project_id, 'admin' as role FROM projects";
                $projectStmt = $this->conn->prepare($projectQuery);
                $projectStmt->execute();
                return $projectStmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            return $memberProjects;
        } catch (Exception $e) {
            error_log("Error getting user projects: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Check if a user has access to a specific project
     * 
     * @param string $userId The user ID to check
     * @param string $projectId The project ID to check access for
     * @return bool True if user has access, false otherwise
     */
    public function hasProjectAccess($userId, $projectId) {
        try {
            // Get user role
            $userQuery = "SELECT role FROM users WHERE id = ?";
            $userStmt = $this->conn->prepare($userQuery);
            $userStmt->execute([$userId]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            
            // Admins have access to all projects
            if ($user && $user['role'] === 'admin') {
                return true;
            }
            
            // Check if user is a member of the project
            $query = "SELECT 1 FROM project_members WHERE user_id = ? AND project_id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$userId, $projectId]);
            
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            error_log("Error checking project access: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get all members of a project
     * 
     * @param string $projectId The project ID
     * @return array List of project members
     */
    public function getProjectMembers($projectId) {
        try {
            $query = "SELECT pm.user_id, pm.role, u.username, u.email, u.role as user_role
                     FROM project_members pm
                     JOIN users u ON pm.user_id = u.id
                     WHERE pm.project_id = ?";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$projectId]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting project members: " . $e->getMessage());
            return [];
        }
    }
}