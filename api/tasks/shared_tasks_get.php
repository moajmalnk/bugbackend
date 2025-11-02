<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/SharedTaskController.php';

try {
    $controller = new SharedTaskController();
    
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Method not allowed', 405);
    }
    
    // Validate token and get user
    $decoded = $controller->validateToken();
    $userId = $decoded->user_id;
    
    $status = $_GET['status'] ?? null;
    
    $controller->getSharedTasks($userId, $status);
    
} catch (Exception $e) {
    error_log("Error in shared_tasks_get.php: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

