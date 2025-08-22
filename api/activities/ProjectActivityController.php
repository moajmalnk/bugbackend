<?php
require_once __DIR__ . '/../BaseAPI.php';

class ProjectActivityController extends BaseAPI {
    
    public function __construct() {
        parent::__construct();
    }

    /**
     * Get recent activities for a specific project
     */
    public function getProjectActivities($projectId = null, $limit = 10, $offset = 0) {
        try {
            $decoded = $this->validateToken();
            $userId = $decoded->user_id;
            $userRole = $decoded->role;
            
            // If project ID is provided, validate it exists first
            if ($projectId) {
                // Check if project exists
                $projectExists = $this->fetchSingleCached(
                    "SELECT id, name FROM projects WHERE id = ?",
                    [$projectId],
                    "project_exists_{$projectId}",
                    300
                );
                
                if (!$projectExists) {
                    $this->sendJsonResponse(404, "Project not found");
                    return;
                }
                
                // Validate project access
                if (!$this->validateProjectAccess($userId, $userRole, $projectId)) {
                    $this->sendJsonResponse(403, "Access denied to this project");
                    return;
                }
            }
            
            // Create cache key
            $cacheKey = $projectId 
                ? "project_activities_{$projectId}_{$limit}_{$offset}"
                : "user_activities_{$userId}_{$limit}_{$offset}";
            
            // Try to get cached results first
            $cachedResult = $this->getCache($cacheKey);
            if ($cachedResult !== null) {
                $this->sendJsonResponse(200, "Activities retrieved successfully (cached)", $cachedResult);
                return;
            }
            
            // Build query based on whether we want project-specific or user activities
            if ($projectId) {
                $query = $this->buildProjectActivityQuery($userRole, $projectId);
                $params = [$projectId, $limit, $offset];
                $countParams = [$projectId];
            } else {
                $query = $this->buildUserActivityQuery($userRole, $userId);
                $params = [$userId, $userId, $limit, $offset]; // Note: userId twice for UNION query
                $countParams = [$userId, $userId]; // Note: userId twice for UNION query
            }
            
            // Execute main query with caching
            $activities = $this->fetchCached($query, $params, $cacheKey . '_data', 300);
            
            // Get total count
            $countQuery = $projectId 
                ? $this->buildProjectActivityCountQuery($userRole, $projectId)
                : $this->buildUserActivityCountQuery($userRole, $userId);
            
            $totalResult = $this->fetchSingleCached($countQuery, $countParams, $cacheKey . '_count', 300);
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
            
            // Cache the result
            $this->setCache($cacheKey, $result, 300);
            
            $this->sendJsonResponse(200, "Activities retrieved successfully", $result);
            
        } catch (Exception $e) {
            error_log("Error in getProjectActivities: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to retrieve activities: " . $e->getMessage());
        }
    }
    
    /**
     * Log a new activity
     */
    public function logActivity($type, $description, $projectId = null, $relatedId = null, $metadata = null) {
        try {
            $decoded = $this->validateToken();
            $userId = $decoded->user_id;
            
            // Use regular INSERT instead of bulkInsert
            $sql = "INSERT INTO project_activities (user_id, project_id, activity_type, description, related_id, metadata, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt = $this->conn->prepare($sql);
            $result = $stmt->execute([
                $userId,
                $projectId,
                $type,
                $description,
                $relatedId,
                $metadata ? json_encode($metadata) : null,
                date('Y-m-d H:i:s')
            ]);
            
            if ($result) {
                // Invalidate related caches
                $this->invalidateActivityCaches($projectId, $userId);
                
                $this->sendJsonResponse(201, "Activity logged successfully", ['id' => $this->conn->lastInsertId()]);
            } else {
                $this->sendJsonResponse(500, "Failed to log activity");
            }
            
        } catch (Exception $e) {
            error_log("Error logging activity: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to log activity: " . $e->getMessage());
        }
    }
    
    /**
     * Get activity statistics for a project
     */
    public function getActivityStats($projectId) {
        try {
            $decoded = $this->validateToken();
            $userId = $decoded->user_id;
            $userRole = $decoded->role;
            
            if (!$this->validateProjectAccess($userId, $userRole, $projectId)) {
                $this->sendJsonResponse(403, "Access denied to this project");
                return;
            }
            
            $cacheKey = "project_activity_stats_{$projectId}";
            $cachedStats = $this->getCache($cacheKey);
            
            if ($cachedStats !== null) {
                $this->sendJsonResponse(200, "Activity statistics retrieved (cached)", $cachedStats);
                return;
            }
            
            // Get various activity statistics using individual queries
            $totalActivities = $this->fetchSingleCached(
                'SELECT COUNT(*) as count FROM project_activities WHERE project_id = ?',
                [$projectId],
                "total_activities_{$projectId}",
                300
            );
            
            $recentActivities = $this->fetchSingleCached(
                'SELECT COUNT(*) as count FROM project_activities WHERE project_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAYS)',
                [$projectId],
                "recent_activities_{$projectId}",
                300
            );
            
            $activityTypes = $this->fetchCached(
                'SELECT activity_type, COUNT(*) as count FROM project_activities WHERE project_id = ? GROUP BY activity_type ORDER BY count DESC',
                [$projectId],
                "activity_types_{$projectId}",
                300
            );
            
            $topContributors = $this->fetchCached(
                'SELECT u.username, COUNT(*) as activity_count FROM project_activities pa JOIN users u ON pa.user_id COLLATE utf8mb4_unicode_ci = u.id COLLATE utf8mb4_unicode_ci WHERE pa.project_id = ? GROUP BY pa.user_id, u.username ORDER BY activity_count DESC LIMIT 5',
                [$projectId],
                "top_contributors_{$projectId}",
                300
            );
            
            $stats = [
                'total_activities' => $totalActivities['count'] ?? 0,
                'recent_activities' => $recentActivities['count'] ?? 0,
                'activity_types' => $activityTypes ?? [],
                'top_contributors' => $topContributors ?? []
            ];
            
            // Cache for 10 minutes
            $this->setCache($cacheKey, $stats, 600);
            
            $this->sendJsonResponse(200, "Activity statistics retrieved successfully", $stats);
            
        } catch (Exception $e) {
            error_log("Error getting activity stats: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to retrieve activity statistics: " . $e->getMessage());
        }
    }
    
    /**
     * Validate project access for user
     */
    private function validateProjectAccess($userId, $userRole, $projectId) {
        // Admins have access to all projects
        if ($userRole === 'admin') {
            return true;
        }
        
        $cacheKey = "user_project_access_{$userId}_{$projectId}";
        $hasAccess = $this->getCache($cacheKey);
        
        if ($hasAccess !== null) {
            return $hasAccess;
        }
        
        // Check if user is a member of the project or project owner
        $accessQuery = "
            SELECT 1 FROM (
                SELECT 1 FROM project_members WHERE user_id COLLATE utf8mb4_unicode_ci = ? AND project_id COLLATE utf8mb4_unicode_ci = ?
                UNION
                SELECT 1 FROM projects WHERE created_by COLLATE utf8mb4_unicode_ci = ? AND id COLLATE utf8mb4_unicode_ci = ?
            ) as access_check LIMIT 1
        ";
        
        $result = $this->fetchSingleCached($accessQuery, [$userId, $projectId, $userId, $projectId], $cacheKey, 300);
        $hasAccess = !empty($result);
        
        return $hasAccess;
    }
    
    /**
     * Build project activity query based on user role
     */
    private function buildProjectActivityQuery($userRole, $projectId) {
        return "
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
    }
    
    /**
     * Build user activity query
     */
    private function buildUserActivityQuery($userRole, $userId) {
        if ($userRole === 'admin') {
            // Admins can see all activities
            return "
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
        } else {
            // Regular users only see activities from projects they have access to
            return "
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
                WHERE pa.project_id COLLATE utf8mb4_unicode_ci IN (
                    SELECT DISTINCT project_id FROM project_members WHERE user_id = ?
                    UNION
                    SELECT DISTINCT id FROM projects WHERE created_by = ?
                )
                ORDER BY pa.created_at DESC
                LIMIT ? OFFSET ?
            ";
        }
    }
    
    /**
     * Build count queries
     */
    private function buildProjectActivityCountQuery($userRole, $projectId) {
        return "SELECT COUNT(*) as total FROM project_activities WHERE project_id = ?";
    }
    
    private function buildUserActivityCountQuery($userRole, $userId) {
        if ($userRole === 'admin') {
            return "SELECT COUNT(*) as total FROM project_activities";
        } else {
            return "
                SELECT COUNT(*) as total 
                FROM project_activities pa
                WHERE pa.project_id IN (
                    SELECT DISTINCT project_id FROM project_members WHERE user_id = ?
                    UNION
                    SELECT DISTINCT id FROM projects WHERE created_by = ?
                )
            ";
        }
    }
    
    /**
     * Format activities for consistent output
     */
    private function formatActivities($activities) {
        return array_map(function($activity) {
            // Handle metadata parsing for both JSON and TEXT columns
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
    
    /**
     * Calculate time ago string
     */
    private function timeAgo($datetime) {
        $time = time() - strtotime($datetime);
        
        if ($time < 60) return 'just now';
        if ($time < 3600) return floor($time/60) . ' minutes ago';
        if ($time < 86400) return floor($time/3600) . ' hours ago';
        if ($time < 2592000) return floor($time/86400) . ' days ago';
        if ($time < 31536000) return floor($time/2592000) . ' months ago';
        return floor($time/31536000) . ' years ago';
    }
    
    /**
     * Invalidate activity-related caches
     */
    private function invalidateActivityCaches($projectId = null, $userId = null) {
        // Clear specific cache keys
        if ($projectId) {
            $this->clearCache("project_activities_{$projectId}");
            $this->clearCache("project_activity_stats_{$projectId}");
            $this->clearCache("total_activities_{$projectId}");
            $this->clearCache("recent_activities_{$projectId}");
            $this->clearCache("activity_types_{$projectId}");
            $this->clearCache("top_contributors_{$projectId}");
        }
        
        if ($userId) {
            $this->clearCache("user_activities_{$userId}");
        }
        
        // Clear general activity cache patterns
        $this->clearCache("activities_");
    }
}
?> 