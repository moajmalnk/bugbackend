<?php
require_once '../config/headers.php';
require_once 'UserController.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit();
    }

    if (!isset($_GET['id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'User ID is required']);
        exit();
    }

    $controller = new UserController();
    $controller->delete($_GET['id']);
} catch (Exception $e) {
    error_log("Delete user error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    exit();
}