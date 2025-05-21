<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/UserController.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['message' => 'Method Not Allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$userId = $input['userId'] ?? null;
$currentPassword = $input['currentPassword'] ?? null;
$newPassword = $input['newPassword'] ?? null;

if (!$userId || !$currentPassword || !$newPassword) {
    http_response_code(400);
    echo json_encode(['message' => 'Missing required fields']);
    exit;
}

try {
    $controller = new UserController();

    $conn = $controller->getConnection();

    if (!$conn) {
        http_response_code(500);
        echo json_encode(['message' => 'Database connection failed']);
        exit;
    }

    // Fetch user by ID
    $query = "SELECT password FROM users WHERE id = ?";
    try {
        $stmt = $conn->prepare($query);
        $stmt->execute([$userId]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['message' => 'SQL error', 'error' => $e->getMessage()]);
        exit;
    }
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode(['message' => 'User not found']);
        exit;
    }

    // Verify current password
    if (!password_verify($currentPassword, $user['password'])) {
        http_response_code(401);
        echo json_encode(['message' => 'Current password is incorrect']);
        exit;
    }

    // Update password
    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    $updateQuery = "UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?";
    $updateStmt = $conn->prepare($updateQuery);
    $updateStmt->execute([$hashedPassword, $userId]);

    echo json_encode(['message' => 'Password changed successfully']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Server error', 'error' => $e->getMessage()]);
}