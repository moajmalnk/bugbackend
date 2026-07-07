<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

try {
    require_once __DIR__ . '/ProjectActivityController.php';

    $controller = new ProjectActivityController();

    $method = $_SERVER['REQUEST_METHOD'];
    $projectId = $_GET['project_id'] ?? null;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    $mineOnly = isset($_GET['mine_only']) && $_GET['mine_only'] === '1';

    $maxLimit = $mineOnly ? 200 : 100;
    $limit = max(1, min($maxLimit, $limit));
    $offset = max(0, $offset);

    $filters = [
        'search' => trim((string)($_GET['search'] ?? '')),
        'type' => trim((string)($_GET['type'] ?? 'all')),
        'username' => trim((string)($_GET['username'] ?? 'all')),
        'mine_only' => $mineOnly,
    ];

    if ($method === 'GET') {
        if ($projectId) {
            $controller->getProjectActivities($projectId, $limit, $offset, $filters);
        } else {
            $controller->getProjectActivities(null, $limit, $offset, $filters);
        }
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
} catch (Exception $e) {
    error_log("Fatal error in project_activities.php: " . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error: ' . $e->getMessage(),
    ]);
}
