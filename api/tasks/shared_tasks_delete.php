<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/SharedTaskController.php';

try {
    $controller = new SharedTaskController();
    
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        throw new Exception('Method not allowed', 405);
    }
    
    // Validate token and get user
    $controller->validateToken();
    $token = $controller->getAuthToken();
    $decoded = $controller->decodeToken($token);
    $userId = $decoded->user_id;
    
    $taskId = $_GET['id'] ?? null;
    
    if (!$taskId) {
        throw new Exception('Task ID is required', 400);
    }
    
    $controller->deleteSharedTask($taskId, $userId);
    
} catch (Exception $e) {
    error_log("Error in shared_tasks_delete.php: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

