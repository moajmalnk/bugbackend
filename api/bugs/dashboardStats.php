<?php
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
    header("Access-Control-Allow-Origin: https://bugs.bugricer.com");
}

header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin, X-Impersonate-User, X-User-Id");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Max-Age: 3600");
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/BugController.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $api = new BaseAPI();
    $decoded = $api->validateToken();

    $user_id = $decoded->user_id;
    $user_role = strtolower(trim((string) ($decoded->role ?? '')));
    $isAdmin = BaseAPI::hasGlobalDataScope($decoded);

    $from = isset($_GET['from']) ? trim((string) $_GET['from']) : null;
    $to = isset($_GET['to']) ? trim((string) $_GET['to']) : null;
    if ($from === '') {
        $from = null;
    }
    if ($to === '') {
        $to = null;
    }

    $accessUserId = $isAdmin ? null : $user_id;
    $controller = new BugController();
    $stats = $controller->getDashboardStats($from, $to, $accessUserId);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Dashboard bug stats retrieved',
        'data' => $stats,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
