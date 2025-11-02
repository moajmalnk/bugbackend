<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/SharedTaskController.php';

try {
    $controller = new SharedTaskController();
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method not allowed', 405);
    }
    
    // Validate token and get user
    $decoded = $controller->validateToken();
    
    // Get request data
    $data = $controller->getRequestData();
    if (!$data) {
        throw new Exception('Invalid request data', 400);
    }
    // Ensure created_by is set from token
    if (!isset($data['created_by']) || empty($data['created_by'])) {
        $data['created_by'] = $decoded->user_id;
    }
    
    $controller->createSharedTask($data);
    
} catch (Exception $e) {
    error_log("Error in shared_tasks_create.php: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

