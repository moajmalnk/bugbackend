<?php
require_once __DIR__ . '/../BaseAPI.php';

class ProjectLister extends BaseAPI {
    
    public function listProjects() {
        try {
            $decoded = $this->validateToken();
            $userId = $decoded->user_id;
            $userRole = $decoded->role;
            
            // Get projects user has access to
            if ($userRole === 'admin') {
                // Admins can see all projects
                $query = "SELECT id, name, created_at FROM projects ORDER BY created_at DESC";
                $params = [];
            } else {
                // Regular users only see projects they have access to
                $query = "
                    SELECT DISTINCT p.id, p.name, p.created_at 
                    FROM projects p
                    WHERE p.id IN (
                        SELECT DISTINCT project_id FROM project_members WHERE user_id = ?
                        UNION
                        SELECT DISTINCT id FROM projects WHERE created_by = ?
                    )
                    ORDER BY p.created_at DESC
                ";
                $params = [$userId, $userId];
            }
            
            $projects = $this->fetchCached($query, $params, "user_projects_{$userId}", 300);
            
            $this->sendJsonResponse(200, "Projects retrieved successfully", ['projects' => $projects]);
            
        } catch (Exception $e) {
            error_log("Error listing projects: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to retrieve projects: " . $e->getMessage());
        }
    }
}

$controller = new ProjectLister();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $controller->listProjects();
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?> 