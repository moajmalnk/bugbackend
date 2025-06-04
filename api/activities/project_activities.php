<?php
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
?> 