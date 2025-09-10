<?php
require_once __DIR__ . '/../../config/database.php';
require_once 'AuthController.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$identifier = $data['identifier'] ?? '';
$password = $data['password'] ?? '';

if (!$identifier || !$password) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Identifier and password required']);
    exit;
}

$pdo = Database::getInstance()->getConnection();
$auth = new AuthController($pdo);
$result = $auth->loginWithIdentifier($identifier, $password);

if ($result['success']) {
    echo json_encode($result);
} else {
    http_response_code(401);
    echo json_encode($result);
}
?> 