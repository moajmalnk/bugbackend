<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/ChatMessageController.php';

$controller = new ChatMessageController();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->send();
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?> 