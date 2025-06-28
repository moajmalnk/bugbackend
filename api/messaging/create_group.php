<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/ChatGroupController.php';

$controller = new ChatGroupController();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->create();
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?> 