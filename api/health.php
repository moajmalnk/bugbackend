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

header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Max-Age: 3600");
header('Content-Type: application/json');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

try {
    // Check database connection
    require_once __DIR__ . '/BaseAPI.php';
    $baseAPI = new BaseAPI();
    
    // Basic health check - just ensure we can connect to database
    $stmt = $baseAPI->pdo->query("SELECT 1");
    $dbStatus = $stmt ? 'connected' : 'disconnected';
    
    // Get server info
    $serverInfo = [
        'php_version' => PHP_VERSION,
        'server_time' => date('c'),
        'memory_usage' => memory_get_usage(true),
        'memory_peak' => memory_get_peak_usage(true),
    ];
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'status' => 'healthy',
        'database' => $dbStatus,
        'server' => $serverInfo,
        'timestamp' => time()
    ]);
    
} catch (Exception $e) {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'status' => 'unhealthy',
        'error' => 'Database connection failed',
        'timestamp' => time()
    ]);
} 