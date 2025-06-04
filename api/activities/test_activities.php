<?php
// TEST ENDPOINT - REMOVE IN PRODUCTION
require_once __DIR__ . '/../BaseAPI.php';

class TestActivities extends BaseAPI {
    
    public function testGetActivities($projectId = null, $limit = 10, $offset = 0) {
        try {
            echo "<!-- Test mode: Authentication bypassed -->\n";
            
            // List available projects first
            $projects = $this->fetchCached("SELECT id, name FROM projects ORDER BY created_at DESC LIMIT 5");
            echo "<!-- Available projects:\n";
            foreach ($projects as $project) {
                echo "   - " . $project['id'] . " : " . $project['name'] . "\n";
            }
            echo "-->\n";
            
            if ($projectId) {
                // Check if project exists
                $projectExists = $this->fetchSingleCached("SELECT id, name FROM projects WHERE id = ?", [$projectId]);
                
                if (!$projectExists) {
                    $this->sendJsonResponse(404, "Project not found. Use one of the available project IDs listed in the HTML comments above.");
                    return;
                }
                
                // Get project activities
                $query = "
                    SELECT 
                        pa.*,
                        u.username,
                        u.email,
                        p.name as project_name,
                        CASE 
                            WHEN pa.activity_type = 'bug_reported' THEN b.title
                            WHEN pa.activity_type = 'bug_updated' THEN b.title
                            WHEN pa.activity_type = 'bug_fixed' THEN b.title
                            ELSE NULL
                        END as related_title
                    FROM project_activities pa
                    LEFT JOIN users u ON pa.user_id COLLATE utf8mb4_unicode_ci = u.id COLLATE utf8mb4_unicode_ci
                    LEFT JOIN projects p ON pa.project_id COLLATE utf8mb4_unicode_ci = p.id COLLATE utf8mb4_unicode_ci
                    LEFT JOIN bugs b ON pa.related_id COLLATE utf8mb4_unicode_ci = b.id COLLATE utf8mb4_unicode_ci AND pa.activity_type LIKE 'bug_%'
                    WHERE pa.project_id = ?
                    ORDER BY pa.created_at DESC
                    LIMIT ? OFFSET ?
                ";
                $params = [$projectId, $limit, $offset];
                $countParams = [$projectId];
                $countQuery = "SELECT COUNT(*) as total FROM project_activities WHERE project_id = ?";
            } else {
                // Get all activities
                $query = "
                    SELECT 
                        pa.*,
                        u.username,
                        u.email,
                        p.name as project_name,
                        CASE 
                            WHEN pa.activity_type = 'bug_reported' THEN b.title
                            WHEN pa.activity_type = 'bug_updated' THEN b.title
                            WHEN pa.activity_type = 'bug_fixed' THEN b.title
                            ELSE NULL
                        END as related_title
                    FROM project_activities pa
                    LEFT JOIN users u ON pa.user_id COLLATE utf8mb4_unicode_ci = u.id COLLATE utf8mb4_unicode_ci
                    LEFT JOIN projects p ON pa.project_id COLLATE utf8mb4_unicode_ci = p.id COLLATE utf8mb4_unicode_ci
                    LEFT JOIN bugs b ON pa.related_id COLLATE utf8mb4_unicode_ci = b.id COLLATE utf8mb4_unicode_ci AND pa.activity_type LIKE 'bug_%'
                    ORDER BY pa.created_at DESC
                    LIMIT ? OFFSET ?
                ";
                $params = [$limit, $offset];
                $countParams = [];
                $countQuery = "SELECT COUNT(*) as total FROM project_activities";
            }
            
            // Execute queries
            $activities = $this->fetchCached($query, $params);
            $totalResult = $this->fetchSingleCached($countQuery, $countParams);
            $total = $totalResult['total'] ?? 0;
            
            // Format activities
            $formattedActivities = $this->formatActivities($activities);
            
            $result = [
                'activities' => $formattedActivities,
                'pagination' => [
                    'total' => (int)$total,
                    'limit' => (int)$limit,
                    'offset' => (int)$offset,
                    'hasMore' => ($offset + $limit) < $total
                ]
            ];
            
            $this->sendJsonResponse(200, "Activities retrieved successfully (TEST MODE)", $result);
            
        } catch (Exception $e) {
            error_log("Error in test activities: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to retrieve activities: " . $e->getMessage());
        }
    }
    
    private function formatActivities($activities) {
        return array_map(function($activity) {
            $metadata = null;
            if (!empty($activity['metadata'])) {
                $metadata = is_string($activity['metadata']) 
                    ? json_decode($activity['metadata'], true) 
                    : $activity['metadata'];
            }
            
            return [
                'id' => $activity['id'],
                'type' => $activity['activity_type'],
                'description' => $activity['description'],
                'user' => [
                    'id' => $activity['user_id'],
                    'username' => $activity['username'],
                    'email' => $activity['email']
                ],
                'project' => [
                    'id' => $activity['project_id'],
                    'name' => $activity['project_name']
                ],
                'related_title' => $activity['related_title'],
                'metadata' => $metadata,
                'created_at' => $activity['created_at'],
                'time_ago' => $this->timeAgo($activity['created_at'])
            ];
        }, $activities);
    }
    
    private function timeAgo($datetime) {
        $time = time() - strtotime($datetime);
        
        if ($time < 60) return 'just now';
        if ($time < 3600) return floor($time/60) . ' minutes ago';
        if ($time < 86400) return floor($time/3600) . ' hours ago';
        if ($time < 2592000) return floor($time/86400) . ' days ago';
        if ($time < 31536000) return floor($time/2592000) . ' months ago';
        return floor($time/31536000) . ' years ago';
    }
}

$controller = new TestActivities();

$method = $_SERVER['REQUEST_METHOD'];
$projectId = $_GET['project_id'] ?? null;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

$limit = max(1, min(50, $limit));
$offset = max(0, $offset);

if ($method === 'GET') {
    $controller->testGetActivities($projectId, $limit, $offset);
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?> 