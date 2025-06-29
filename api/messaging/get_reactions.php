<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/ReactionController.php';

$controller = new ReactionController();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $messageId = $_GET['message_id'] ?? null;
    
    if (!$messageId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'message_id is required']);
        exit;
    }
    
    $controller->getByMessage($messageId);
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?> 