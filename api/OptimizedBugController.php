<?php
require_once __DIR__ . '/OptimizedBaseAPI.php';
require_once __DIR__ . '/../config/database_optimization.php';

class OptimizedBugController extends OptimizedBaseAPI {
    
    // Cache keys for different data types
    private const CACHE_KEYS = [
        'bug_list' => 'bugs_list_',
        'bug_detail' => 'bug_detail_',
        'bug_stats' => 'bug_stats_',
        'user_bugs' => 'user_bugs_',
        'project_bugs' => 'project_bugs_',
        'bug_attachments' => 'bug_attachments_'
    ];
    
    // Query templates for prepared statements
    private const QUERIES = [
        'get_bug_with_details' => "
            SELECT 
                b.*,
                u.username as reporter_name,
                u.email as reporter_email,
                p.name as project_name,
                p.description as project_description,
                updater.username as updater_name
            FROM bugs b
            LEFT JOIN users u ON b.reported_by = u.id
            LEFT JOIN projects p ON b.project_id = p.id
            LEFT JOIN users updater ON b.updated_by = updater.id
            WHERE b.id = ?
        ",
        
        'get_bugs_with_pagination' => "
            SELECT 
                b.*,
                u.username as reporter_name,
                p.name as project_name,
                COUNT(ba.id) as attachment_count
            FROM bugs b
            LEFT JOIN users u ON b.reported_by = u.id
            LEFT JOIN projects p ON b.project_id = p.id
            LEFT JOIN bug_attachments ba ON b.id = ba.bug_id
            WHERE b.project_id IN (
                SELECT DISTINCT project_id FROM project_members WHERE user_id = ?
                UNION
                SELECT DISTINCT id FROM projects WHERE created_by = ?
            )
            GROUP BY b.id
            ORDER BY b.created_at DESC
            LIMIT ? OFFSET ?
        ",
        
        'get_bug_statistics' => "
            SELECT 
                COUNT(*) as total_bugs,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_count,
                SUM(CASE WHEN status = 'fixed' THEN 1 ELSE 0 END) as fixed_count,
                SUM(CASE WHEN status = 'declined' THEN 1 ELSE 0 END) as declined_count,
                SUM(CASE WHEN priority = 'high' THEN 1 ELSE 0 END) as high_priority_count,
                SUM(CASE WHEN priority = 'medium' THEN 1 ELSE 0 END) as medium_priority_count,
                SUM(CASE WHEN priority = 'low' THEN 1 ELSE 0 END) as low_priority_count
            FROM bugs 
            WHERE project_id IN (
                SELECT DISTINCT project_id FROM project_members WHERE user_id = ?
                UNION
                SELECT DISTINCT id FROM projects WHERE created_by = ?
            )
        "
    ];
    
    public function __construct() {
        parent::__construct();
    }
    
    /**
     * Get bug with optimized caching and eager loading
     */
    public function getBug($bugId, $userId = null) {
        $cacheKey = self::CACHE_KEYS['bug_detail'] . $bugId;
        
        // Check cache first
        $cachedBug = $this->getCache($cacheKey);
        if ($cachedBug !== null) {
            return $cachedBug;
        }
        
        // Execute optimized query
        $bug = $this->fetchSingleCached(
            self::QUERIES['get_bug_with_details'],
            [$bugId],
            $cacheKey,
            1800 // Cache for 30 minutes
        );
        
        if (!$bug) {
            return null;
        }
        
        // Load attachments in separate optimized query
        $bug['attachments'] = $this->getBugAttachments($bugId);
        
        // Cache the complete bug data
        $this->setCache($cacheKey, $bug, 1800);
        
        return $bug;
    }
    
    /**
     * Get bugs with advanced pagination and filtering
     */
    public function getBugs($filters = [], $page = 1, $limit = 10, $userId = null) {
        // Build cache key based on filters
        $cacheKey = $this->buildBugsCacheKey($filters, $page, $limit, $userId);
        
        // Check cache first
        $cachedBugs = $this->getCache($cacheKey);
        if ($cachedBugs !== null) {
            return $cachedBugs;
        }
        
        // Build optimized query with filters
        $query = $this->buildBugsQuery($filters, $userId);
        $params = $this->buildBugsParams($filters, $userId, $page, $limit);
        
        // Execute query with caching
        $bugs = $this->fetchCached(
            $query,
            $params,
            $cacheKey,
            300 // Cache for 5 minutes
        );
        
        // Get total count for pagination
        $totalCount = $this->getBugsCount($filters, $userId);
        
        // Get statistics
        $stats = $this->getBugStatistics($userId);
        
        $result = [
            'bugs' => $bugs,
            'pagination' => [
                'currentPage' => $page,
                'totalPages' => ceil($totalCount / $limit),
                'totalBugs' => $totalCount,
                'limit' => $limit
            ],
            'statistics' => $stats
        ];
        
        // Cache the result
        $this->setCache($cacheKey, $result, 300);
        
        return $result;
    }
    
    /**
     * Create bug with optimized validation and caching
     */
    public function createBug($bugData, $userId) {
        // Validate input data
        $validation = $this->validateBugData($bugData);
        if (!$validation['valid']) {
            throw new Exception($validation['message']);
        }
        
        // Check project access
        $hasAccess = $this->checkProjectAccess($bugData['project_id'], $userId);
        if (!$hasAccess) {
            throw new Exception('Access denied to project');
        }
        
        // Generate UUID for bug
        $bugId = $this->utils->generateUUID();
        
        // Prepare bug data
        $bugData['id'] = $bugId;
        $bugData['reported_by'] = $userId;
        $bugData['created_at'] = date('Y-m-d H:i:s');
        $bugData['updated_at'] = date('Y-m-d H:i:s');
        
        // Execute optimized insert
        $this->conn->beginTransaction();
        
        try {
            // Insert bug
            $insertQuery = "
                INSERT INTO bugs (id, title, description, project_id, reported_by, priority, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";
            
            $stmt = $this->prepare($insertQuery);
            $stmt->execute([
                $bugData['id'],
                $bugData['title'],
                $bugData['description'],
                $bugData['project_id'],
                $bugData['reported_by'],
                $bugData['priority'],
                $bugData['status'],
                $bugData['created_at'],
                $bugData['updated_at']
            ]);
            
            // Log activity
            $this->logBugActivity($bugId, $userId, 'bug_created', 'Bug created');
            
            // Clear related caches
            $this->clearBugCaches($bugData['project_id'], $userId);
            
            $this->conn->commit();
            
            // Return created bug
            return $this->getBug($bugId, $userId);
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            throw $e;
        }
    }
    
    /**
     * Update bug with optimized validation and caching
     */
    public function updateBug($bugId, $updateData, $userId) {
        // Get existing bug
        $existingBug = $this->getBug($bugId, $userId);
        if (!$existingBug) {
            throw new Exception('Bug not found');
        }
        
        // Check permissions
        $canUpdate = $this->canUpdateBug($existingBug, $userId);
        if (!$canUpdate) {
            throw new Exception('Permission denied');
        }
        
        // Validate update data
        $validation = $this->validateBugUpdate($updateData);
        if (!$validation['valid']) {
            throw new Exception($validation['message']);
        }
        
        // Prepare update data
        $updateData['updated_by'] = $userId;
        $updateData['updated_at'] = date('Y-m-d H:i:s');
        
        // Execute optimized update
        $this->conn->beginTransaction();
        
        try {
            $updateQuery = "
                UPDATE bugs 
                SET title = ?, description = ?, priority = ?, status = ?, updated_by = ?, updated_at = ?
                WHERE id = ?
            ";
            
            $stmt = $this->prepare($updateQuery);
            $stmt->execute([
                $updateData['title'],
                $updateData['description'],
                $updateData['priority'],
                $updateData['status'],
                $updateData['updated_by'],
                $updateData['updated_at'],
                $bugId
            ]);
            
            // Log activity
            $this->logBugActivity($bugId, $userId, 'bug_updated', 'Bug updated');
            
            // Clear related caches
            $this->clearBugCaches($existingBug['project_id'], $userId);
            
            $this->conn->commit();
            
            // Return updated bug
            return $this->getBug($bugId, $userId);
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            throw $e;
        }
    }
    
    /**
     * Get bug attachments with optimized query
     */
    private function getBugAttachments($bugId) {
        $cacheKey = self::CACHE_KEYS['bug_attachments'] . $bugId;
        
        return $this->fetchCached(
            "SELECT id, file_name, file_path, file_type, uploaded_by, created_at 
             FROM bug_attachments 
             WHERE bug_id = ? 
             ORDER BY created_at DESC",
            [$bugId],
            $cacheKey,
            3600 // Cache for 1 hour
        );
    }
    
    /**
     * Build optimized bugs query with filters
     */
    private function buildBugsQuery($filters, $userId) {
        $baseQuery = "
            SELECT 
                b.*,
                u.username as reporter_name,
                p.name as project_name,
                COUNT(ba.id) as attachment_count
            FROM bugs b
            LEFT JOIN users u ON b.reported_by = u.id
            LEFT JOIN projects p ON b.project_id = p.id
            LEFT JOIN bug_attachments ba ON b.id = ba.bug_id
        ";
        
        $whereConditions = ["b.project_id IN (
            SELECT DISTINCT project_id FROM project_members WHERE user_id = ?
            UNION
            SELECT DISTINCT id FROM projects WHERE created_by = ?
        )"];
        
        $params = [$userId, $userId];
        
        // Add filters
        if (!empty($filters['status'])) {
            $whereConditions[] = "b.status = ?";
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['priority'])) {
            $whereConditions[] = "b.priority = ?";
            $params[] = $filters['priority'];
        }
        
        if (!empty($filters['project_id'])) {
            $whereConditions[] = "b.project_id = ?";
            $params[] = $filters['project_id'];
        }
        
        if (!empty($filters['search'])) {
            $whereConditions[] = "(b.title LIKE ? OR b.description LIKE ?)";
            $searchTerm = '%' . $filters['search'] . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        $query = $baseQuery . " WHERE " . implode(' AND ', $whereConditions);
        $query .= " GROUP BY b.id ORDER BY b.created_at DESC LIMIT ? OFFSET ?";
        
        return $query;
    }
    
    /**
     * Build parameters for bugs query
     */
    private function buildBugsParams($filters, $userId, $page, $limit) {
        $params = [$userId, $userId];
        
        // Add filter parameters
        if (!empty($filters['status'])) $params[] = $filters['status'];
        if (!empty($filters['priority'])) $params[] = $filters['priority'];
        if (!empty($filters['project_id'])) $params[] = $filters['project_id'];
        if (!empty($filters['search'])) {
            $searchTerm = '%' . $filters['search'] . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        // Add pagination parameters
        $offset = ($page - 1) * $limit;
        $params[] = $limit;
        $params[] = $offset;
        
        return $params;
    }
    
    /**
     * Get bug statistics with caching
     */
    private function getBugStatistics($userId) {
        $cacheKey = self::CACHE_KEYS['bug_stats'] . $userId;
        
        return $this->fetchSingleCached(
            self::QUERIES['get_bug_statistics'],
            [$userId, $userId],
            $cacheKey,
            900 // Cache for 15 minutes
        );
    }
    
    /**
     * Build cache key for bugs list
     */
    private function buildBugsCacheKey($filters, $page, $limit, $userId) {
        $filterString = md5(serialize($filters));
        return self::CACHE_KEYS['bug_list'] . $userId . '_' . $filterString . '_' . $page . '_' . $limit;
    }
    
    /**
     * Clear bug-related caches
     */
    private function clearBugCaches($projectId, $userId) {
        $patterns = [
            self::CACHE_KEYS['bug_list'] . $userId,
            self::CACHE_KEYS['project_bugs'] . $projectId,
            self::CACHE_KEYS['bug_stats'] . $userId
        ];
        
        foreach ($patterns as $pattern) {
            $this->clearCache($pattern);
        }
    }
    
    /**
     * Validate bug data
     */
    private function validateBugData($data) {
        if (empty($data['title']) || strlen($data['title']) > 255) {
            return ['valid' => false, 'message' => 'Invalid title'];
        }
        
        if (empty($data['project_id']) || !$this->utils->isValidUUID($data['project_id'])) {
            return ['valid' => false, 'message' => 'Invalid project ID'];
        }
        
        if (!in_array($data['priority'], ['low', 'medium', 'high'])) {
            return ['valid' => false, 'message' => 'Invalid priority'];
        }
        
        return ['valid' => true];
    }
    
    /**
     * Validate bug update data
     */
    private function validateBugUpdate($data) {
        if (isset($data['title']) && (empty($data['title']) || strlen($data['title']) > 255)) {
            return ['valid' => false, 'message' => 'Invalid title'];
        }
        
        if (isset($data['priority']) && !in_array($data['priority'], ['low', 'medium', 'high'])) {
            return ['valid' => false, 'message' => 'Invalid priority'];
        }
        
        if (isset($data['status']) && !in_array($data['status'], ['pending', 'in_progress', 'fixed', 'declined', 'rejected'])) {
            return ['valid' => false, 'message' => 'Invalid status'];
        }
        
        return ['valid' => true];
    }
    
    /**
     * Check project access
     */
    private function checkProjectAccess($projectId, $userId) {
        $accessQuery = "
            SELECT 1 FROM project_members WHERE user_id = ? AND project_id = ?
            UNION
            SELECT 1 FROM projects WHERE created_by = ? AND id = ?
        ";
        
        $result = $this->fetchSingleCached($accessQuery, [$userId, $projectId, $userId, $projectId]);
        return $result !== null;
    }
    
    /**
     * Check if user can update bug
     */
    private function canUpdateBug($bug, $userId) {
        // Admin can update any bug
        $user = $this->getUser($userId);
        if ($user['role'] === 'admin') {
            return true;
        }
        
        // Project creator can update bugs in their project
        $project = $this->getProject($bug['project_id']);
        if ($project['created_by'] === $userId) {
            return true;
        }
        
        // Bug reporter can update their own bugs
        if ($bug['reported_by'] === $userId) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Log bug activity
     */
    private function logBugActivity($bugId, $userId, $activityType, $description) {
        $activityQuery = "
            INSERT INTO project_activities (user_id, project_id, activity_type, description, related_id, created_at)
            SELECT ?, project_id, ?, ?, ?, NOW()
            FROM bugs WHERE id = ?
        ";
        
        $stmt = $this->prepare($activityQuery);
        $stmt->execute([$userId, $activityType, $description, $bugId, $bugId]);
    }
    
    /**
     * Get user data (cached)
     */
    private function getUser($userId) {
        $cacheKey = 'user_' . $userId;
        
        return $this->fetchSingleCached(
            "SELECT id, username, email, role FROM users WHERE id = ?",
            [$userId],
            $cacheKey,
            3600 // Cache for 1 hour
        );
    }
    
    /**
     * Get project data (cached)
     */
    private function getProject($projectId) {
        $cacheKey = 'project_' . $projectId;
        
        return $this->fetchSingleCached(
            "SELECT id, name, created_by FROM projects WHERE id = ?",
            [$projectId],
            $cacheKey,
            1800 // Cache for 30 minutes
        );
    }
}
?> 