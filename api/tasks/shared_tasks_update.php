<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/SharedTaskController.php';

try {
    $controller = new SharedTaskController();
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception('Method not allowed', 405);
    }
    
    // Validate token and get user
    $controller->validateToken();
    $token = $controller->getAuthToken();
    $decoded = $controller->decodeToken($token);
    $userId = $decoded->user_id;
    
    // Get request data
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data || !isset($data['id'])) {
        throw new Exception('Invalid request data', 400);
    }
    
    $taskId = $data['id'];
    unset($data['id']);
    
    $controller->updateSharedTask($taskId, $data, $userId);
    
} catch (Exception $e) {
    error_log("Error in shared_tasks_update.php: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

