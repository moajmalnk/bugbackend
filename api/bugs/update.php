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

// Disable HTML error output to prevent JSON corruption
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/BugController.php';

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

$controller = new BugController();

try {
    // Validate token
    $decoded = $controller->validateToken();
    
    // Use $_POST and $_FILES for multipart/form-data
    $data = $_POST;
    $files = $_FILES;

    // If $_POST is empty, try to get JSON input
    if (empty($data)) {
        $rawInput = file_get_contents('php://input');
        $jsonData = json_decode($rawInput, true);
        if ($jsonData) {
            $data = $jsonData;
        }
    }

    if (!isset($data['id'])) {
        throw new Exception('Bug ID is required');
    }

    // Add user ID from token as updated_by
    $data['updated_by'] = $decoded->user_id;

    // Update the bug using the correct method signature
    $result = $controller->updateBug($data);

    // Send success response
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Bug updated successfully',
        'data' => $result
    ]);

} catch (Exception $e) {
    error_log("Bug update error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to update bug: ' . $e->getMessage()
    ]);
} 