<?php
require_once __DIR__ . '/SessionController.php';

$controller = new SessionController();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$controller->getAllActiveSessions();
?>
