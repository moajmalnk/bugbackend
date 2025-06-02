<?php
require_once 'BaseAPI.php';

header('Content-Type: application/json');

// Only allow in development or with proper authorization
$isDev = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false);
$hasDebugAuth = (($_GET['debug_key'] ?? '') === 'bugricer_debug_2024');

if (!$isDev && !$hasDebugAuth) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Access denied"]);
    exit();
}

try {
    $database = new Database();
    $conn = $database->getConnection();
    $dbConnected = true;
} catch (Exception $e) {
    $dbConnected = false;
    $dbError = $e->getMessage();
}

$debugInfo = [
    "success" => true,
    "timestamp" => date('Y-m-d H:i:s'),
    "environment" => [
        "http_host" => $_SERVER['HTTP_HOST'] ?? 'unknown',
        "server_name" => $_SERVER['SERVER_NAME'] ?? 'unknown',
        "server_software" => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
        "document_root" => $_SERVER['DOCUMENT_ROOT'] ?? 'unknown',
        "request_uri" => $_SERVER['REQUEST_URI'] ?? 'unknown',
        "php_version" => phpversion(),
        "is_https" => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on'
    ],
    "database" => [
        "connected" => $dbConnected,
        "error" => $dbConnected ? null : ($dbError ?? 'unknown error')
    ],
    "jwt" => [
        "extension_loaded" => extension_loaded('openssl'),
        "test_token_generation" => false
    ],
    "files" => [
        "autoload_exists" => file_exists(__DIR__ . '/../vendor/autoload.php'),
        "config_dir_exists" => is_dir(__DIR__ . '/../config'),
        "logs_dir_exists" => is_dir(__DIR__ . '/../logs')
    ]
];

// Test JWT generation if database is connected
if ($dbConnected) {
    try {
        require_once __DIR__ . '/../config/utils.php';
        $testToken = Utils::generateJWT('test-user-id', 'test-user', 'user');
        $decoded = Utils::validateJWT($testToken);
        $debugInfo['jwt']['test_token_generation'] = ($decoded !== false);
        $debugInfo['jwt']['test_token'] = substr($testToken, 0, 50) . '...';
    } catch (Exception $e) {
        $debugInfo['jwt']['test_error'] = $e->getMessage();
    }
}

echo json_encode($debugInfo, JSON_PRETTY_PRINT);
?> 