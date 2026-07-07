<?php
require_once __DIR__ . '/../BaseAPI.php';

class ProjectActivityController extends BaseAPI {
    
    public function __construct() {
        parent::__construct();
    }

    /**
     * Get recent activities for a specific project
     */
    public function getProjectActivities($projectId = null, $limit = 10, $offset = 0, $filters = []) {
        try {
            $decoded = $this->validateToken();
            if (!$decoded || !isset($decoded->user_id)) {
                $this->sendJsonResponse(401, "Invalid or expired token");
                return;
            }
            $userId = $decoded->user_id;
            $userRole = $decoded->role;
            
            if ($projectId) {
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
                
                if (!$this->validateProjectAccess($userId, $userRole, $projectId)) {
                    $this->sendJsonResponse(403, "Access denied to this project");
                    return;
                }
            }

            $filters = is_array($filters) ? $filters : [];
            $hasFilters = !empty($filters['search'])
                || (!empty($filters['type']) && $filters['type'] !== 'all')
                || (!empty($filters['username']) && $filters['username'] !== 'all')
                || !empty($filters['mine_only']);

            $cacheKey = md5(json_encode([
                'project' => $projectId,
                'user' => $userId,
                'role' => $userRole,
                'limit' => $limit,
                'offset' => $offset,
                'filters' => $filters,
            ]));

            if (!$hasFilters) {
                $cachedResult = $this->getCache($cacheKey);
                if ($cachedResult !== null) {
                    $this->sendJsonResponse(200, "Activities retrieved successfully (cached)", $cachedResult);
                    return;
                }
            }

            $queryBundle = $this->buildActivitiesQueryBundle($projectId, $userRole, $userId, $filters, $limit, $offset);
            $activities = $hasFilters
                ? $this->executeSelectAll($queryBundle['query'], $queryBundle['params'])
                : $this->fetchCached($queryBundle['query'], $queryBundle['params'], $cacheKey . '_data', 300);

            $totalResult = $hasFilters
                ? $this->executeSelectOne($queryBundle['count_query'], $queryBundle['count_params'])
                : $this->fetchSingleCached($queryBundle['count_query'], $queryBundle['count_params'], $cacheKey . '_count', 300);
            $total = (int)($totalResult['total'] ?? 0);

            $formattedActivities = $this->formatActivities($activities);

            $result = [
                'activities' => $formattedActivities,
                'pagination' => [
                    'total' => $total,
                    'limit' => (int)$limit,
                    'offset' => (int)$offset,
                    'hasMore' => ($offset + $limit) < $total
                ],
                'facets' => $this->getActivityFacets($projectId, $userRole, $userId),
            ];

            if (!$hasFilters) {
                $this->setCache($cacheKey, $result, 300);
            }

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
            if (!$decoded || !isset($decoded->user_id)) {
                $this->sendJsonResponse(401, "Invalid or expired token");
                return;
            }
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
            if (!$decoded || !isset($decoded->user_id)) {
                $this->sendJsonResponse(401, "Invalid or expired token");
                return;
            }
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
                'SELECT u.username, COUNT(*) as activity_count FROM project_activities pa JOIN users u ON pa.user_id  = u.id  WHERE pa.project_id = ? GROUP BY pa.user_id, u.username ORDER BY activity_count DESC LIMIT 5',
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
                SELECT 1 FROM project_members WHERE user_id = ? AND project_id = ?
                UNION
                SELECT 1 FROM projects WHERE created_by = ? AND id = ?
            ) as access_check LIMIT 1
        ";
        
        $result = $this->fetchSingleCached($accessQuery, [$userId, $projectId, $userId, $projectId], $cacheKey, 300);
        $hasAccess = !empty($result);
        
        return $hasAccess;
    }
    
    /**
     * Build enriched activity queries with filters.
     */
    private function buildActivitiesQueryBundle($projectId, $userRole, $userId, $filters, $limit, $offset) {
        $selectSql = $this->getActivitySelectSql();
        $joinSql = $this->getActivityJoinSql();
        $where = [];
        $params = [];

        if ($projectId) {
            $where[] = 'pa.project_id = ?';
            $params[] = $projectId;
        } elseif ($userRole !== 'admin') {
            $where[] = '(
                pa.project_id IS NULL
                OR pa.project_id IN (
                    SELECT DISTINCT project_id FROM project_members WHERE user_id = ?
                    UNION
                    SELECT DISTINCT id FROM projects WHERE created_by = ?
                )
            )';
            $params[] = $userId;
            $params[] = $userId;
        }

        if (!empty($filters['mine_only'])) {
            $where[] = 'pa.user_id = ?';
            $params[] = $userId;
        }

        if (!empty($filters['search'])) {
            $term = '%' . trim($filters['search']) . '%';
            $where[] = '(
                pa.description LIKE ?
                OR pa.activity_type LIKE ?
                OR u.username LIKE ?
                OR p.name LIKE ?
                OR b.title LIKE ?
                OR up.title LIKE ?
                OR ru.username LIKE ?
                OR ann.title LIKE ?
                OR COALESCE(st.title, ut.title) LIKE ?
            )';
            $params = array_merge($params, array_fill(0, 9, $term));
        }

        if (!empty($filters['type']) && $filters['type'] !== 'all') {
            $where[] = 'pa.activity_type = ?';
            $params[] = $filters['type'];
        }

        if (!empty($filters['username']) && $filters['username'] !== 'all') {
            $where[] = 'u.username = ?';
            $params[] = $filters['username'];
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $query = "{$selectSql} {$joinSql} {$whereSql} ORDER BY pa.created_at DESC LIMIT ? OFFSET ?";
        $countQuery = "SELECT COUNT(*) as total {$joinSql} {$whereSql}";

        return [
            'query' => $query,
            'params' => array_merge($params, [(int)$limit, (int)$offset]),
            'count_query' => $countQuery,
            'count_params' => $params,
        ];
    }

    private function getActivitySelectSql() {
        return "
            SELECT
                pa.*,
                u.username,
                u.email,
                p.name as project_name,
                CASE
                    WHEN pa.activity_type LIKE 'bug_%' THEN b.title
                    WHEN pa.activity_type LIKE 'task_%' THEN COALESCE(st.title, ut.title)
                    WHEN pa.activity_type LIKE 'update_%' THEN up.title
                    WHEN pa.activity_type LIKE 'project_%' THEN p.name
                    WHEN pa.activity_type LIKE 'user_%' THEN ru.username
                    WHEN pa.activity_type LIKE 'announcement_%' THEN ann.title
                    ELSE NULL
                END as related_title,
                CASE
                    WHEN pa.activity_type LIKE 'bug_%' THEN 'bug'
                    WHEN pa.activity_type LIKE 'task_%' THEN 'task'
                    WHEN pa.activity_type LIKE 'update_%' THEN 'update'
                    WHEN pa.activity_type LIKE 'fix_%' THEN 'fix'
                    WHEN pa.activity_type LIKE 'project_%' THEN 'project'
                    WHEN pa.activity_type LIKE 'user_%' THEN 'user'
                    WHEN pa.activity_type LIKE 'announcement_%' THEN 'announcement'
                    WHEN pa.activity_type LIKE 'message_%' THEN 'message'
                    WHEN pa.activity_type LIKE 'meeting_%' THEN 'meeting'
                    WHEN pa.activity_type LIKE 'feedback_%' THEN 'feedback'
                    WHEN pa.activity_type LIKE 'comment_%' THEN 'comment'
                    WHEN pa.activity_type LIKE 'file_%' THEN 'file'
                    WHEN pa.activity_type LIKE 'settings_%' THEN 'settings'
                    WHEN pa.activity_type LIKE 'member_%' THEN 'member'
                    ELSE 'general'
                END as related_entity,
                ru.username as target_username
        ";
    }

    private function getActivityJoinSql() {
        return "
            FROM project_activities pa
            LEFT JOIN users u ON pa.user_id = u.id
            LEFT JOIN projects p ON pa.project_id = p.id
            LEFT JOIN bugs b ON pa.related_id = b.id AND pa.activity_type LIKE 'bug_%'
            LEFT JOIN updates up ON pa.related_id = up.id AND pa.activity_type LIKE 'update_%'
            LEFT JOIN users ru ON pa.related_id = ru.id AND pa.activity_type LIKE 'user_%'
            LEFT JOIN announcements ann ON pa.related_id = ann.id AND pa.activity_type LIKE 'announcement_%'
            LEFT JOIN shared_tasks st ON pa.related_id = st.id AND pa.activity_type LIKE 'task_%'
            LEFT JOIN user_tasks ut ON pa.related_id = ut.id AND pa.activity_type LIKE 'task_%'
        ";
    }

    private function getActivityFacets($projectId, $userRole, $userId) {
        $joinSql = $this->getActivityJoinSql();
        $where = [];
        $params = [];

        if ($projectId) {
            $where[] = 'pa.project_id = ?';
            $params[] = $projectId;
        } elseif ($userRole !== 'admin') {
            $where[] = '(
                pa.project_id IS NULL
                OR pa.project_id IN (
                    SELECT DISTINCT project_id FROM project_members WHERE user_id = ?
                    UNION
                    SELECT DISTINCT id FROM projects WHERE created_by = ?
                )
            )';
            $params[] = $userId;
            $params[] = $userId;
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $facetKey = md5(json_encode([$projectId, $userRole, $userId]));

        $types = $this->fetchCached(
            "SELECT pa.activity_type, COUNT(*) as count {$joinSql} {$whereSql} GROUP BY pa.activity_type ORDER BY count DESC LIMIT 30",
            $params,
            'activity_facets_types_' . $facetKey,
            300
        ) ?: [];

        $usersWhere = $whereSql !== ''
            ? "{$whereSql} AND u.username IS NOT NULL"
            : 'WHERE u.username IS NOT NULL';

        $users = $this->fetchCached(
            "SELECT u.username, COUNT(*) as count {$joinSql} {$usersWhere} GROUP BY u.username ORDER BY count DESC LIMIT 30",
            $params,
            'activity_facets_users_' . $facetKey,
            300
        ) ?: [];

        return [
            'types' => $types,
            'users' => $users,
        ];
    }

    private function executeSelectAll($query, $params) {
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function executeSelectOne($query, $params) {
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: ['total' => 0];
    }

    /**
     * Build project activity query based on user role
     * @deprecated Use buildActivitiesQueryBundle()
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
            LEFT JOIN users u ON pa.user_id  = u.id             LEFT JOIN projects p ON pa.project_id  = p.id             LEFT JOIN bugs b ON pa.related_id  = b.id  AND pa.activity_type LIKE 'bug_%'
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
                LEFT JOIN users u ON pa.user_id = u.id
                LEFT JOIN projects p ON pa.project_id = p.id
                LEFT JOIN bugs b ON pa.related_id = b.id AND pa.activity_type LIKE 'bug_%'
                ORDER BY pa.created_at DESC
                LIMIT ? OFFSET ?
            ";
        } else {
            // Regular users see activities from projects they have access to PLUS non-project activities (project_id IS NULL)
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
                LEFT JOIN users u ON pa.user_id = u.id
                LEFT JOIN projects p ON pa.project_id = p.id
                LEFT JOIN bugs b ON pa.related_id = b.id AND pa.activity_type LIKE 'bug_%'
                WHERE (
                    pa.project_id IS NULL 
                    OR pa.project_id IN (
                        SELECT DISTINCT project_id FROM project_members WHERE user_id = ?
                        UNION
                        SELECT DISTINCT id FROM projects WHERE created_by = ?
                    )
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
                WHERE (
                    pa.project_id IS NULL 
                    OR pa.project_id IN (
                        SELECT DISTINCT project_id FROM project_members WHERE user_id = ?
                        UNION
                        SELECT DISTINCT id FROM projects WHERE created_by = ?
                    )
                )
            ";
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

            $summary = $this->buildActivitySummary($activity);

            return [
                'id' => $activity['id'],
                'type' => $activity['activity_type'],
                'description' => $activity['description'],
                'summary' => $summary,
                'user_id' => $activity['user_id'],
                'project_id' => $activity['project_id'],
                'related_id' => $activity['related_id'],
                'username' => $activity['username'],
                'email' => $activity['email'],
                'project_name' => $activity['project_name'],
                'user' => [
                    'id' => $activity['user_id'],
                    'username' => $activity['username'],
                    'email' => $activity['email']
                ],
                'project' => $activity['project_id'] ? [
                    'id' => $activity['project_id'],
                    'name' => $activity['project_name']
                ] : null,
                'related_title' => $activity['related_title'],
                'related_entity' => $activity['related_entity'] ?? 'general',
                'target_username' => $activity['target_username'] ?? null,
                'metadata' => $metadata,
                'created_at' => $activity['created_at'],
                'time_ago' => $this->timeAgo($activity['created_at'])
            ];
        }, $activities);
    }

    private function buildActivitySummary(array $activity) {
        $actor = trim((string)($activity['username'] ?? 'Unknown user'));
        $type = (string)($activity['activity_type'] ?? '');
        $relatedTitle = trim((string)($activity['related_title'] ?? ''));
        $targetUsername = trim((string)($activity['target_username'] ?? ''));
        $projectName = trim((string)($activity['project_name'] ?? ''));
        $actionLabel = $this->getActivityActionLabel($type);
        $target = $relatedTitle ?: $this->extractTargetFromDescription($activity['description'] ?? '');

        if ($target !== '') {
            $summary = "{$actor} {$actionLabel} \"{$target}\"";
            if ($projectName !== '') {
                $summary .= " in {$projectName}";
            }
            return $summary;
        }

        $description = trim((string)($activity['description'] ?? ''));
        if ($description !== '') {
            if (stripos($description, $actor) === 0) {
                return $description;
            }
            return "{$actor} — {$description}";
        }

        return "{$actor} {$actionLabel}";
    }

    private function getActivityActionLabel($type) {
        $labels = [
            'bug_created' => 'created bug',
            'bug_reported' => 'reported bug',
            'bug_updated' => 'updated bug',
            'bug_fixed' => 'fixed bug',
            'bug_assigned' => 'assigned bug',
            'bug_deleted' => 'deleted bug',
            'bug_status_changed' => 'changed status for bug',
            'task_created' => 'created task',
            'task_updated' => 'updated task',
            'task_completed' => 'completed task',
            'task_deleted' => 'deleted task',
            'task_assigned' => 'assigned task',
            'update_created' => 'created update',
            'update_updated' => 'updated update',
            'update_deleted' => 'deleted update',
            'fix_created' => 'created fix',
            'fix_updated' => 'updated fix',
            'fix_deleted' => 'deleted fix',
            'project_created' => 'created project',
            'project_updated' => 'updated project',
            'project_deleted' => 'deleted project',
            'member_added' => 'added member',
            'member_removed' => 'removed member',
            'user_created' => 'created user',
            'user_updated' => 'updated user',
            'user_deleted' => 'deleted user',
            'user_role_changed' => 'changed role for user',
            'announcement_created' => 'created announcement',
            'announcement_updated' => 'updated announcement',
            'announcement_deleted' => 'deleted announcement',
            'announcement_broadcast' => 'broadcast announcement',
            'settings_updated' => 'updated settings',
            'file_uploaded' => 'uploaded file',
            'file_deleted' => 'deleted file',
            'message_sent' => 'sent message',
            'comment_added' => 'added comment',
            'milestone_reached' => 'reached milestone',
        ];

        if (isset($labels[$type])) {
            return $labels[$type];
        }

        return str_replace('_', ' ', $type);
    }

    private function extractTargetFromDescription($description) {
        $description = trim((string)$description);
        if ($description === '') {
            return '';
        }

        if (preg_match('/:\s*(.+)$/', $description, $matches)) {
            return trim($matches[1], " \t\n\r\0\x0B\"'");
        }

        return '';
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
     * Delete an activity (admin only)
     */
    public function deleteActivity($activityId) {
        try {
            $decoded = $this->validateToken();
            if (!$decoded || !isset($decoded->user_id)) {
                $this->sendJsonResponse(401, "Invalid or expired token");
                return;
            }
            
            $userRole = $decoded->role;
            
            // Only admins can delete activities
            if ($userRole !== 'admin') {
                $this->sendJsonResponse(403, "Access denied. Only administrators can delete activities.");
                return;
            }
            
            // Check if activity exists
            $activity = $this->fetchSingleCached(
                "SELECT id, project_id, user_id FROM project_activities WHERE id = ?",
                [$activityId],
                "activity_exists_{$activityId}",
                60
            );
            
            if (!$activity) {
                $this->sendJsonResponse(404, "Activity not found");
                return;
            }
            
            // Delete the activity
            $stmt = $this->conn->prepare("DELETE FROM project_activities WHERE id = ?");
            $result = $stmt->execute([$activityId]);
            
            if ($result) {
                // Invalidate all activity caches since we don't know which specific caches to clear
                $this->invalidateAllActivityCaches();
                
                $this->sendJsonResponse(200, "Activity deleted successfully");
            } else {
                $this->sendJsonResponse(500, "Failed to delete activity");
            }
            
        } catch (Exception $e) {
            error_log("Error deleting activity: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to delete activity: " . $e->getMessage());
        }
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
    
    /**
     * Invalidate all activity caches (used when deleting activities)
     */
    private function invalidateAllActivityCaches() {
        // Clear all activity-related cache patterns
        $this->clearCache("project_activities_");
        $this->clearCache("user_activities_");
        $this->clearCache("project_activity_stats_");
        $this->clearCache("total_activities_");
        $this->clearCache("recent_activities_");
        $this->clearCache("activity_types_");
        $this->clearCache("top_contributors_");
        $this->clearCache("activities_");
    }
}
?> 