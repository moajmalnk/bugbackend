<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/ChatMessageController.php';

$controller = new ChatMessageController();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $groupId = $_GET['group_id'] ?? null;
    
    if (!$groupId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'group_id is required']);
        exit;
    }
    
    $controller->getByGroup($groupId);
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?> 