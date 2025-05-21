<?php
header('Content-Type: application/json');
require_once 'UserController.php';

try {
    $controller = new UserController();

    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        throw new Exception('Method not allowed', 405);
    }

    $controller->validateToken();

    $userId = $_GET['id'] ?? null;
    if (!$userId) {
        throw new Exception('User ID is required', 400);
    }

    $controller->delete($userId);
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage() ?: 'An unexpected error occurred'
    ]);
}