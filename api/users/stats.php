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

    public function handleRequest($callback = null) {
        try {
            $this->validateToken();

            $userId = isset($_GET['id']) ? $_GET['id'] : null;
            if (!$userId) {
                throw new Exception('User ID is required');
            }

            // Check cache first
            $cacheKey = 'user_stats_' . $userId;
            $cachedStats = $this->getCache($cacheKey);
            
            if ($cachedStats !== null) {
                echo json_encode([
                    'success' => true,
                    'data' => $cachedStats
                ]);
                exit;
            }

            // Verify user exists with cached query
            $userExists = $this->fetchSingleCached(
                "SELECT id FROM users WHERE id = ?", 
                [$userId], 
                'user_exists_' . $userId, 
                3600 // Cache for 1 hour
            );
            
            if (!$userExists) {
                throw new Exception('User not found');
            }

            // Use optimized individual queries with caching for better performance
            $totalProjects = $this->fetchSingleCached(
                "SELECT COUNT(DISTINCT COALESCE(pm.project_id, p.id)) as total_projects
                 FROM (SELECT ? as user_id) u
                 LEFT JOIN project_members pm ON pm.user_id = u.user_id
                 LEFT JOIN projects p ON p.created_by = u.user_id",
                [$userId],
                'user_projects_count_' . $userId,
                600
            )['total_projects'] ?? 0;
            
            $totalBugs = $this->fetchSingleCached(
                "SELECT COUNT(id) as total_bugs FROM bugs WHERE reported_by = ?",
                [$userId],
                'user_bugs_count_' . $userId,
                600
            )['total_bugs'] ?? 0;
            
            $totalFixes = $this->fetchSingleCached(
                "SELECT COUNT(id) as total_fixes FROM bugs WHERE updated_by = ? AND status = 'fixed'",
                [$userId],
                'user_fixes_count_' . $userId,
                600
            )['total_fixes'] ?? 0;

            // Optimized recent activity query using single query with UNION ALL
            $activityQuery = "
                (SELECT 'bug' as type, title, created_at, id
                 FROM bugs 
                 WHERE reported_by = ?
                 ORDER BY created_at DESC
                 LIMIT 3)
                UNION ALL
                (SELECT 'fix' as type, CONCAT('Fixed: ', title) as title, updated_at as created_at, id
                 FROM bugs 
                 WHERE updated_by = ? AND status = 'fixed'
                 ORDER BY updated_at DESC
                 LIMIT 3)
                UNION ALL
                (SELECT 'project' as type, p.name as title, pm.joined_at as created_at, pm.project_id as id
                 FROM project_members pm
                 JOIN projects p ON p.id = pm.project_id
                 WHERE pm.user_id = ?
                 ORDER BY pm.joined_at DESC
                 LIMIT 2)
                ORDER BY created_at DESC
                LIMIT 5
            ";

            $activityResult = $this->fetchCached(
                $activityQuery, 
                [$userId, $userId, $userId],
                'user_activity_' . $userId,
                300 // Cache for 5 minutes
            );

            $statsData = [
                'total_projects' => (int)$totalProjects,
                'total_bugs' => (int)$totalBugs,
                'total_fixes' => (int)$totalFixes,
                'recent_activity' => $activityResult
            ];

            // Cache the final result for 10 minutes
            $this->setCache($cacheKey, $statsData, 600);

            echo json_encode([
                'success' => true,
                'data' => $statsData
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