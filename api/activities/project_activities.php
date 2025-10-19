<?php
// Enable error reporting for debugging (remove in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Add logging
error_log("ProjectActivities API called: " . $_SERVER['REQUEST_METHOD'] . " " . $_SERVER['REQUEST_URI']);

try {
    require_once __DIR__ . '/ProjectActivityController.php';
    
    $controller = new ProjectActivityController();
    
    // Get request method and project ID
    $method = $_SERVER['REQUEST_METHOD'];
    $projectId = $_GET['project_id'] ?? null;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    
    // Validate parameters
    $limit = max(1, min(50, $limit)); // Between 1 and 50
    $offset = max(0, $offset);
    
    error_log("Project ID: " . ($projectId ?? 'null') . ", Limit: $limit, Offset: $offset");
    
    if ($method === 'GET') {
        if ($projectId) {
            $controller->getProjectActivities($projectId, $limit, $offset);
        } else {
            // Get all activities for user based on their access
            $controller->getProjectActivities(null, $limit, $offset);
        }
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    error_log("Fatal error in project_activities.php: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Internal server error: ' . $e->getMessage(),
        'debug' => [
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]
    ]);
}
?> 