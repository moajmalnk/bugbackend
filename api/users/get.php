<?php
header('Content-Type: application/json');
require_once 'UserController.php';

try {
    $controller = new UserController();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Method not allowed', 405);
    }

    // Validate token
    $controller->validateToken();

    $userId = isset($_GET['id']) ? $_GET['id'] : null;

    if ($userId) {
        // Get single user
        $controller->getUser($userId);
    } else {
        // Get all users
        $controller->getUsers();
    }
} catch (Exception $e) {
    error_log("Error in get.php: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage() ?: 'An unexpected error occurred'
    ]);
} 