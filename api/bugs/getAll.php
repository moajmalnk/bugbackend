<?php
// Handle CORS headers first
$allowedOrigins = [
    'https://bugs.moajmalnk.in',
    'http://localhost:8080',
    'http://localhost:3000',
    'http://127.0.0.1:8080'
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins)) {
    header("Access-Control-Allow-Origin: $origin");
} else if (strpos($origin, 'localhost') !== false || strpos($origin, '127.0.0.1') !== false) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    header("Access-Control-Allow-Origin: https://bugs.moajmalnk.in");
}

header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin, X-Impersonate-User, X-User-Id");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Max-Age: 3600");
header('Content-Type: application/json');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/BugController.php';
require_once __DIR__ . '/../projects/ProjectMemberController.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $api = new BaseAPI();
    $decoded = $api->validateToken();
    
    $user_id = $decoded->user_id;
    $user_role = $decoded->role;
    
    $projectId = isset($_GET['project_id']) ? $_GET['project_id'] : null;
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    
    // Create cache key for this request
    $cacheKey = 'user_bugs_' . $user_id . '_' . ($projectId ?? 'all') . '_' . $page . '_' . $limit;
    $cachedResult = $api->getCache($cacheKey);
    
    if ($cachedResult !== null) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Bugs retrieved successfully (cached)',
            'data' => $cachedResult
        ]);
        exit;
    }
    
    $controller = new BugController();
    
    // Admin users can see all bugs
    if ($user_role === 'admin') {
        $result = $controller->getAllBugs($projectId, $page, $limit);
        $api->setCache($cacheKey, $result, 300); // Cache for 5 minutes
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Bugs retrieved successfully',
            'data' => $result
        ]);
        exit;
    }
    
    // For non-admin users, use optimized single query approach
    if ($projectId) {
        // Check project access with cached query
        $accessQuery = "SELECT 1 FROM project_members WHERE user_id = ? AND project_id = ? 
                       UNION SELECT 1 FROM projects WHERE created_by = ? AND id = ?";
        $hasAccess = $api->fetchSingleCached($accessQuery, [$user_id, $projectId, $user_id, $projectId], 
                                           'user_project_access_' . $user_id . '_' . $projectId, 600);
        
        if (!$hasAccess) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'You do not have access to this project']);
            exit;
        }
        
        // User has access to this specific project
        $result = $controller->getAllBugs($projectId, $page, $limit);
        $api->setCache($cacheKey, $result, 300);
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Bugs retrieved successfully',
            'data' => $result
        ]);
        exit;
    }
    
    // No specific project requested - get bugs from all accessible projects in one optimized query
    $offset = ($page - 1) * $limit;
    
    // Single optimized query to get bugs with project access check
    $bugsQuery = "
        SELECT DISTINCT b.*, 
               u.username as reporter_name,
               p.name as project_name
        FROM bugs b
        LEFT JOIN users u ON b.reported_by = u.id
        LEFT JOIN projects p ON b.project_id = p.id
        WHERE b.project_id IN (
            SELECT DISTINCT project_id FROM project_members WHERE user_id = ?
            UNION
            SELECT DISTINCT id FROM projects WHERE created_by = ?
        )
        ORDER BY b.created_at DESC
        LIMIT ? OFFSET ?
    ";
    
    // Count query for pagination
    $countQuery = "
        SELECT COUNT(DISTINCT b.id) as total
        FROM bugs b
        WHERE b.project_id IN (
            SELECT DISTINCT project_id FROM project_members WHERE user_id = ?
            UNION
            SELECT DISTINCT id FROM projects WHERE created_by = ?
        )
    ";
    
    // Execute queries with prepared statements
    $stmt = $api->prepare($bugsQuery);
    $stmt->execute([$user_id, $user_id, $limit, $offset]);
    $bugs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $totalBugs = $api->fetchSingleCached($countQuery, [$user_id, $user_id], 
                                       'user_total_bugs_' . $user_id, 600)['total'];
    
    // Get attachments for all bugs in one query if there are bugs
    if (!empty($bugs)) {
        $bugIds = array_column($bugs, 'id');
        $placeholders = str_repeat('?,', count($bugIds) - 1) . '?';
        
        $attachmentQuery = "SELECT bug_id, id, file_name, file_path, file_type 
                          FROM bug_attachments 
                          WHERE bug_id IN ($placeholders)
                          ORDER BY bug_id, id";
        
        $attachmentStmt = $api->prepare($attachmentQuery);
        $attachmentStmt->execute($bugIds);
        $allAttachments = $attachmentStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Group attachments by bug_id
        $attachmentsByBug = [];
        foreach ($allAttachments as $attachment) {
            $attachmentsByBug[$attachment['bug_id']][] = $attachment;
        }
        
        // Assign attachments to bugs
        foreach ($bugs as &$bug) {
            $bug['attachments'] = $attachmentsByBug[$bug['id']] ?? [];
        }
    }
    
    $pendingCountQuery = "SELECT COUNT(*) as pending FROM bugs WHERE status IN ('pending', 'in_progress')";
    $pendingCount = $api->fetchSingleCached($pendingCountQuery, [], 'pending_bugs_count', 300)['pending'];
    
    $result = [
        'bugs' => $bugs,
        'pagination' => [
            'currentPage' => $page,
            'totalPages' => ceil($totalBugs / $limit),
            'totalBugs' => (int)$totalBugs,
            'limit' => $limit,
            'pendingBugsCount' => (int)$pendingCount
        ]
    ];
    
    // Cache the result for 5 minutes
    $api->setCache($cacheKey, $result, 300);
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Bugs retrieved successfully',
        'data' => $result
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
} 