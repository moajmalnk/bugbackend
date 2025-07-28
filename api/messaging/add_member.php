<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/ChatGroupController.php';

$controller = new ChatGroupController();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);
    $group_id = $data['group_id'] ?? null;
    $user_ids = $data['user_ids'] ?? null;
    
    if (!$group_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'group_id is required']);
        exit;
    }
    
    if (!$user_ids || !is_array($user_ids) || empty($user_ids)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'user_ids array is required']);
        exit;
    }
    
    $controller->addMembers($group_id, $user_ids);
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?> 