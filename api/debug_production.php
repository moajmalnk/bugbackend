<?php
// Production Debug Script
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$debug = [
    'timestamp' => date('Y-m-d H:i:s'),
    'server_info' => [
        'php_version' => phpversion(),
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
        'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'Unknown',
        'script_filename' => $_SERVER['SCRIPT_FILENAME'] ?? 'Unknown',
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'Unknown',
        'request_uri' => $_SERVER['REQUEST_URI'] ?? 'Unknown',
        'http_host' => $_SERVER['HTTP_HOST'] ?? 'Unknown'
    ],
    'environment' => [
        'working_directory' => getcwd(),
        'file_exists_BaseAPI' => file_exists(__DIR__ . '/BaseAPI.php'),
        'file_exists_database' => file_exists(__DIR__ . '/../config/database.php'),
        'file_exists_utils' => file_exists(__DIR__ . '/../config/utils.php'),
        'file_exists_cors' => file_exists(__DIR__ . '/../config/cors.php')
    ]
];

try {
    // Test basic database connection
    require_once __DIR__ . '/../config/database.php';
    $database = Database::getInstance();
    $conn = $database->getConnection();
    
    $debug['database'] = [
        'connection_status' => $conn ? 'Connected' : 'Failed',
        'connection_type' => get_class($conn ?? new stdClass())
    ];
    
    if ($conn) {
        // Test basic query
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM projects");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $debug['database']['projects_count'] = $result['count'] ?? 0;
        
        // Test activities table
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM project_activities");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $debug['database']['activities_count'] = $result['count'] ?? 0;
        
        // Test recent activities
        $stmt = $conn->prepare("SELECT id, activity_type, created_at FROM project_activities ORDER BY created_at DESC LIMIT 3");
        $stmt->execute();
        $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $debug['database']['recent_activities'] = $activities;
    }
    
    $debug['success'] = true;
    $debug['message'] = 'Debug completed successfully';
    
} catch (Exception $e) {
    $debug['success'] = false;
    $debug['error'] = $e->getMessage();
    $debug['error_file'] = $e->getFile();
    $debug['error_line'] = $e->getLine();
    $debug['stack_trace'] = $e->getTraceAsString();
}

// Test ActivityController
try {
    if (file_exists(__DIR__ . '/activities/ProjectActivityController.php')) {
        require_once __DIR__ . '/activities/ProjectActivityController.php';
        $debug['controller_test'] = 'ProjectActivityController loaded successfully';
    } else {
        $debug['controller_test'] = 'ProjectActivityController file not found';
    }
} catch (Exception $e) {
    $debug['controller_test'] = 'Error loading controller: ' . $e->getMessage();
}

echo json_encode($debug, JSON_PRETTY_PRINT);
?> 