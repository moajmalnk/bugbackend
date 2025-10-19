<?php
header('Content-Type: application/json');

require_once 'UserController.php';

try {
    $controller = new UserController();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method not allowed', 405);
    }

    // Validate token
    $controller->validateToken();

    // Get request data
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        throw new Exception('Invalid request data', 400);
    }

    // Remove 'name' from request data if present
    unset($data['name']);

    // Create user
    $controller->createUser($data);
} catch (Exception $e) {
    error_log("Error in create.php: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage() ?: 'An unexpected error occurred'
    ]);
}