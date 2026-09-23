<?php
$allowedOrigins = [
    'https://bugs.moajmalnk.in',
    'https://bugricer.com',
    'https://www.bugricer.com',
    'https://bugs.bugricer.com',
    'http://localhost:8080',
    'http://localhost:3000',
    'http://127.0.0.1:8080',
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins)) {
    header("Access-Control-Allow-Origin: $origin");
} elseif (strpos($origin, 'localhost') !== false || strpos($origin, '127.0.0.1') !== false) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    header('Access-Control-Allow-Origin: https://bugs.bugricer.com');
}

header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin, X-Impersonate-User, X-User-Id');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Max-Age: 3600');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/BugController.php';
require_once __DIR__ . '/../PermissionManager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $api = new BaseAPI();
    $decoded = $api->validateToken();

    $user_role = $decoded->role ?? '';
    $userId = $decoded->user_id ?? null;
    $user_role_lower = strtolower(trim((string) $user_role));
    $isAdmin = BaseAPI::hasGlobalDataScope($decoded);
    $isDeveloper = $user_role_lower === 'developer';
    $isTester = $user_role_lower === 'tester';

    $hasCommonBugsPermission = false;
    if ($userId) {
        try {
            $hasCommonBugsPermission = PermissionManager::getInstance()->hasPermissionOrAdmin(
                (string) $userId,
                'COMMON_BUGS_VIEW',
                $user_role
            );
        } catch (Throwable $e) {
            error_log('getCommon permission check: ' . $e->getMessage());
        }
    }

    // Why: Testers are granted COMMON_BUGS_VIEW; API previously blocked everyone
    // except admin/developer and returned 403 while the sidebar still showed the page.
    if (!$isAdmin && !$isDeveloper && !$isTester && !$hasCommonBugsPermission) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => "You don't have permission to perform this action."]);
        exit;
    }

    // Why: Common Bugs is an org-wide duplicate catalog — developers and testers
    // must see every entry to check before reporting, not only assigned projects.
    $scopeUserId = null;

    $projectId = isset($_GET['project_id']) && $_GET['project_id'] !== '' ? $_GET['project_id'] : null;
    $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;
    $reason = isset($_GET['reason']) ? strtolower(trim((string) $_GET['reason'])) : 'all';

    $controller = new BugController();
    $result = $controller->getCommonBugs($page, $limit, $projectId, $reason, $scopeUserId);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Common bugs retrieved successfully',
        'data' => $result,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
