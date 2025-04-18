<?php
require_once __DIR__ . '/../BaseAPI.php';

class UserStatsController extends BaseAPI {
    protected $conn;

    public function __construct() {
        $this->conn = $this->getConnection();
    }

    public function handleRequest() {
        try {
            // Verify token
            $token = $this->getBearerToken();
            if (!$token || !$this->validateToken()) {
                throw new Exception('Unauthorized access');
            }

            // Get user ID from query parameter
            $userId = isset($_GET['id']) ? $_GET['id'] : null;
            if (!$userId) {
                throw new Exception('User ID is required');
            }

            // Verify user exists
            $userQuery = "SELECT id FROM users WHERE id = ?";
            $stmt = $this->conn->prepare($userQuery);
            $stmt->bind_param("s", $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows === 0) {
                throw new Exception('User not found');
            }

            // Get total projects
            $projectQuery = "SELECT COUNT(DISTINCT project_id) as total_projects 
                           FROM project_members 
                           WHERE user_id = ?";
            $stmt = $this->conn->prepare($projectQuery);
            $stmt->bind_param("s", $userId);
            $stmt->execute();
            $projectResult = $stmt->get_result()->fetch_assoc();
            $totalProjects = $projectResult['total_projects'];

            // Get total bugs
            $bugQuery = "SELECT COUNT(id) as total_bugs 
                        FROM bugs 
                        WHERE reported_by = ?";
            $stmt = $this->conn->prepare($bugQuery);
            $stmt->bind_param("s", $userId);
            $stmt->execute();
            $bugResult = $stmt->get_result()->fetch_assoc();
            $totalBugs = $bugResult['total_bugs'];

            // Get recent activity
            $activityQuery = "SELECT 'bug' as type, title, created_at 
                            FROM bugs 
                            WHERE reported_by = ?
                            UNION
                            SELECT 'project' as type, p.name as title, pm.joined_at as created_at
                            FROM project_members pm
                            JOIN projects p ON p.id = pm.project_id
                            WHERE pm.user_id = ?
                            ORDER BY created_at DESC
                            LIMIT 5";
            $stmt = $this->conn->prepare($activityQuery);
            $stmt->bind_param("ss", $userId, $userId);
            $stmt->execute();
            $activityResult = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

            echo json_encode([
                'success' => true,
                'data' => [
                    'total_projects' => (int)$totalProjects,
                    'total_bugs' => (int)$totalBugs,
                    'recent_activity' => $activityResult
                ]
            ]);

        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    protected function getBearerToken() {
        $headers = apache_request_headers();
        if (isset($headers['Authorization'])) {
            if (preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $matches)) {
                return $matches[1];
            }
        }
        return null;
    }

    public function validateToken(): bool {
        $token = $this->getBearerToken();
        if (!$token) return false;
        
        $query = "SELECT id FROM users WHERE token = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->num_rows > 0;
    }
}

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Initialize and handle the request
$controller = new UserStatsController();
$controller->handleRequest();