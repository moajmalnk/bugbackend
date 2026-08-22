<?php
// Handle CORS headers first
$allowedOrigins = [
    'https://bugs.moajmalnk.in',
    'https://bugricer.com',
    'https://www.bugricer.com',
    'https://bugs.bugricer.com',
    'https://www.bugricer.com',
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
    // Default to the main production domain
    header("Access-Control-Allow-Origin: https://bugs.bugricer.com");
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
    
    // Check impersonation
    $is_impersonated = false;
    if (isset($decoded->impersonated)) {
        $is_impersonated = $decoded->impersonated === true || $decoded->impersonated === 'true' || $decoded->impersonated === 1;
    }
    if (!$is_impersonated && isset($decoded->admin_id) && !empty($decoded->admin_id)) {
        $is_impersonated = true;
    }
    
    // Check if the actual admin (not the impersonated user) has admin role
    $admin_role = isset($decoded->admin_role) ? strtolower(trim($decoded->admin_role)) : null;
    $user_role_lower = strtolower(trim($user_role));
    $isAdmin = ($user_role_lower === 'admin' && !$is_impersonated) || ($is_impersonated && $admin_role === 'admin');
    
    $projectId = isset($_GET['project_id']) && $_GET['project_id'] !== '' && $_GET['project_id'] !== 'all'
        ? $_GET['project_id']
        : null;
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    $status = isset($_GET['status']) && $_GET['status'] !== '' ? $_GET['status'] : null;
    $userId = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? $_GET['user_id'] : null;
    $search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
    $priority = isset($_GET['priority']) && $_GET['priority'] !== '' && $_GET['priority'] !== 'all'
        ? trim((string)$_GET['priority'])
        : '';
    $fixedBy = isset($_GET['fixed_by']) && $_GET['fixed_by'] !== '' && $_GET['fixed_by'] !== 'all'
        ? trim((string)$_GET['fixed_by'])
        : '';
    $bugTypeId = isset($_GET['bug_type_id']) && $_GET['bug_type_id'] !== '' && $_GET['bug_type_id'] !== 'all'
        ? trim((string)$_GET['bug_type_id'])
        : '';
    $verificationFilter = isset($_GET['verification']) && $_GET['verification'] !== '' && $_GET['verification'] !== 'all'
        ? trim((string)$_GET['verification'])
        : '';
    $verifiedFrom = isset($_GET['verified_from']) ? trim((string) $_GET['verified_from']) : '';
    $verifiedTo = isset($_GET['verified_to']) ? trim((string) $_GET['verified_to']) : '';
    $sort = isset($_GET['sort']) ? trim((string) $_GET['sort']) : '';

    $filters = [
        'search' => $search,
        'priority' => $priority,
        'fixed_by' => $fixedBy,
        'bug_type_id' => $bugTypeId,
        'verification_filter' => $verificationFilter,
        'verified_from' => $verifiedFrom,
        'verified_to' => $verifiedTo,
        'sort' => $sort,
        'facet_user_id' => $user_id,
    ];

    // Non-admins only see bugs from projects they belong to
    if (!$isAdmin) {
        $filters['access_user_id'] = $user_id;

        if ($projectId) {
            $accessQuery = "SELECT 1 FROM project_members WHERE user_id = ? AND project_id = ? 
                           UNION SELECT 1 FROM projects WHERE created_by = ? AND id = ?";
            $hasAccess = $api->fetchSingleCached(
                $accessQuery,
                [$user_id, $projectId, $user_id, $projectId],
                'user_project_access_' . $user_id . '_' . $projectId,
                600
            );

            if (!$hasAccess) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'You do not have access to this project']);
                exit;
            }
        }
    }

    $controller = new BugController();
    $result = $controller->getAllBugs($projectId, $page, $limit, $status, $userId, $filters);

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
