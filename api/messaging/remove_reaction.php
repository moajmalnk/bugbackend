<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/ReactionController.php';

$controller = new ReactionController();

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $controller->remove();
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?> 