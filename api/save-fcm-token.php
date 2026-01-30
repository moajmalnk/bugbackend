<?php
require_once __DIR__ . '/../config/cors.php';
header('Content-Type: application/json');

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
    require_once __DIR__ . '/users/UserController.php';
    $controller = new UserController();
    $conn = $controller->getConnection();
    if (!$conn) {
        throw new Exception('Database connection failed', 500);
    }

    // Check if fcm_token column exists, add if missing
    try {
        $check = $conn->query("SHOW COLUMNS FROM users LIKE 'fcm_token'");
        if ($check && $check->rowCount() === 0) {
            $conn->exec("ALTER TABLE users ADD COLUMN fcm_token VARCHAR(255) DEFAULT NULL");
        }
    } catch (PDOException $e) {
        error_log("save-fcm-token: Could not add fcm_token column: " . $e->getMessage());
    }

    $decoded = $controller->validateToken();
    $userId = $decoded->user_id ?? null;

    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $token = $data['token'] ?? null;

    if (!$token || !$userId) {
        throw new Exception('Missing token or user', 400);
    }

    $stmt = $conn->prepare("UPDATE users SET fcm_token = ? WHERE id = ?");
    $stmt->execute([$token, $userId]);

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    error_log("save-fcm-token PDO error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
} catch (Exception $e) {
    $code = $e->getCode();
    if (!is_int($code) || $code < 400) {
        $code = (strpos($e->getMessage(), 'token') !== false || strpos($e->getMessage(), 'auth') !== false) ? 401 : 500;
    }
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}