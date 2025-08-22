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
    
    // Admin users can access all bugs
    if ($user_role === 'admin') {
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