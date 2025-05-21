<?php
header('Content-Type: application/json');
require_once 'UserController.php';
require_once __DIR__ . '/../BaseAPI.php';

class UserStatsController extends BaseAPI {
    protected $conn;

    public function __construct() {
        parent::__construct();
        // $this->conn is already set by BaseAPI
    }

    public function handleRequest() {
        try {
            $this->validateToken();

            $userId = isset($_GET['id']) ? $_GET['id'] : null;
            if (!$userId) {
                throw new Exception('User ID is required');
            }

            // Verify user exists
            $userQuery = "SELECT id FROM users WHERE id = ?";
            $stmt = $this->conn->prepare($userQuery);
            $stmt->execute([$userId]);
            if ($stmt->rowCount() === 0) {
                throw new Exception('User not found');
            }

            // Get total projects
            $projectQuery = "SELECT COUNT(DISTINCT project_id) as total_projects FROM project_members WHERE user_id = ?";
            $stmt = $this->conn->prepare($projectQuery);
            $stmt->execute([$userId]);
            $projectResult = $stmt->fetch(PDO::FETCH_ASSOC);
            $totalProjects = $projectResult['total_projects'] ?? 0;

            // Get total bugs
            $bugQuery = "SELECT COUNT(id) as total_bugs FROM bugs WHERE reported_by = ?";
            $stmt = $this->conn->prepare($bugQuery);
            $stmt->execute([$userId]);
            $bugResult = $stmt->fetch(PDO::FETCH_ASSOC);
            $totalBugs = $bugResult['total_bugs'] ?? 0;

            // Get recent activity
            $activityQuery = "
                SELECT * FROM (
                    SELECT 'bug' as type, title, created_at 
                    FROM bugs 
                    WHERE reported_by = ?
                    UNION ALL
                    SELECT 'project' as type, p.name as title, pm.joined_at as created_at
                    FROM project_members pm
                    JOIN projects p ON p.id = pm.project_id
                    WHERE pm.user_id = ?
                ) AS activity
                ORDER BY created_at DESC
                LIMIT 5
            ";
            $stmt = $this->conn->prepare($activityQuery);
            $stmt->execute([$userId, $userId]);
            $activityResult = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'data' => [
                    'total_projects' => (int)$totalProjects,
                    'total_bugs' => (int)$totalBugs,
                    'recent_activity' => $activityResult
                ]
            ]);
        } catch (Exception $e) {
            error_log("UserStatsController error: " . $e->getMessage());
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$controller = new UserStatsController();
$controller->handleRequest();