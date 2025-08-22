<?php
header('Content-Type: application/json');

require_once 'UserController.php';

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method not allowed', 405);
    }

    $controller = new UserController();

    // Validate token
    $controller->validateToken();

    // Get request data
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data || !isset($data['id'])) {
        throw new Exception('Invalid request data', 400);
    }

    // Update user
    $controller->updateUser($data['id'], $data);
} catch (Exception $e) {
    error_log("Error in update.php: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage() ?: 'An unexpected error occurred'
    ]);
}