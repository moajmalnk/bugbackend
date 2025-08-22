<?php
require_once __DIR__ . '/../config/cors.php';
header('Content-Type: application/json');

require_once __DIR__ . '/users/UserController.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

try {
    $controller = new UserController();

    // Validate token and get user object or ID
    $user = $controller->validateToken();
    $userId = is_object($user) ? $user->user_id : $user;


    $data = json_decode(file_get_contents('php://input'), true);
    $token = $data['token'] ?? null;

    if (!$token || !$userId) {
        throw new Exception('Missing token or user', 400);
    }

    $conn = $controller->getConnection();
    if (!$conn) {
        throw new Exception('Database connection failed', 500);
    }

    $stmt = $conn->prepare("UPDATE users SET fcm_token = ? WHERE id = ?");
    $stmt->execute([$token, $userId]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
}
?>