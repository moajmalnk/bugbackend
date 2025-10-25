<?php
require_once __DIR__ . '/SessionController.php';

$controller = new SessionController();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$controller->endSession($data);
?>
