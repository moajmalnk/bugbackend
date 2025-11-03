<?php
// Handle CORS headers first
$allowedOrigins = [
    'https://bugs.moajmalnk.in',
    'https://bugricer.com',
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
    header("Access-Control-Allow-Origin: https://bugs.bugricer.com");
}

header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Max-Age: 3600");
header('Content-Type: application/json');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/BugController.php';
require_once __DIR__ . '/../projects/ProjectMemberController.php';

if (!isset($_GET['id'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Bug ID is required'
    ]);
    exit;
}

$api = new BaseAPI();
try {
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
    
    // Get the bug ID from the request
    $bugId = $_GET['id'];
    
    // First, get the bug to determine its project
    $controller = new BugController();
    $bug = $controller->getBugBasicInfo($bugId);
    
    if (!$bug) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Bug not found'
        ]);
        exit;
    }
    
    $projectId = $bug['project_id'];
    
    // Admin users can access all bugs (real admins or admins impersonating)
    if ($isAdmin) {
        // Allow access for admins
        $controller->getById($bugId);
        exit;
    }
    
    // For non-admin users, check if they are a member of the project
    $memberController = new ProjectMemberController();
    $hasAccess = $memberController->hasProjectAccess($user_id, $projectId);
    
    if (!$hasAccess) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'You do not have access to this bug'
        ]);
        exit;
    }
    
    // User has access, get the bug details
    $controller->getById($bugId);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
} 