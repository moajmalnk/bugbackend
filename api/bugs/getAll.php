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
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept");
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
    
    $controller = new BugController();
    $memberController = new ProjectMemberController();
    
    // Admin users can see all bugs
    if ($user_role === 'admin') {
        // Remove pagination limits for admin to see all bugs like other users
        $result = $controller->getAllBugs($projectId, 1, 1000);
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Bugs retrieved successfully',
            'data' => $result
        ]);
        exit;
    }
    
    // For non-admin users, we need to filter based on project membership
    // Get all projects the user is a member of
    $userProjects = $memberController->getUserProjects($user_id);
    
    // If a specific project was requested, check if user has access
    if ($projectId) {
        $hasAccess = false;
        foreach ($userProjects as $project) {
            if ($project['project_id'] === $projectId) {
                $hasAccess = true;
                break;
            }
        }
        
        if (!$hasAccess) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'You do not have access to this project']);
            exit;
        }
        
        // User has access to this specific project
        $result = $controller->getAllBugs($projectId, $page, $limit);
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Bugs retrieved successfully',
            'data' => $result
        ]);
        exit;
    }
    
    // No specific project requested, get bugs from all projects user has access to
    $projectIds = array_map(function($project) {
        return $project['project_id'];
    }, $userProjects);
    
    if (empty($projectIds)) {
        // User doesn't have access to any projects
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'No bugs found',
            'data' => [
                'bugs' => [],
                'pagination' => [
                    'currentPage' => $page,
                    'totalPages' => 0,
                    'totalBugs' => 0,
                    'limit' => $limit
                ]
            ]
        ]);
        exit;
    }
    
    // For now, use existing method with modified project filtering
    // This is a workaround until getAllBugsByProjects is implemented
    $bugs = [];
    $totalBugs = 0;
    
    foreach ($projectIds as $pid) {
        $projectResult = $controller->getAllBugs($pid, 1, 1000); // Get all bugs from this project
        $bugs = array_merge($bugs, $projectResult['bugs']);
        $totalBugs += $projectResult['pagination']['totalBugs'];
    }
    
    // Sort by created_at
    usort($bugs, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    
    // Apply pagination manually
    $offset = ($page - 1) * $limit;
    $paginatedBugs = array_slice($bugs, $offset, $limit);
    
    $result = [
        'bugs' => $paginatedBugs,
        'pagination' => [
            'currentPage' => $page,
            'totalPages' => ceil($totalBugs / $limit),
            'totalBugs' => $totalBugs,
            'limit' => $limit
        ]
    ];
    
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