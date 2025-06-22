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

$controller = new UserController();

try {
    $decodedToken = $controller->validateToken();
    $actorId = $decodedToken->user_id;
    $actorRole = $decodedToken->role;
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['message' => 'Authentication failed: ' . $e->getMessage()]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$userId = $input['userId'] ?? null;
$newPassword = $input['newPassword'] ?? null;
$currentPassword = $input['currentPassword'] ?? null;

if (!$userId || !$newPassword) {
    http_response_code(400);
    echo json_encode(['message' => 'User ID and new password are required']);
    exit;
}

try {
    $conn = $controller->getConnection();
    if (!$conn) {
        http_response_code(500);
        echo json_encode(['message' => 'Database connection failed']);
        exit;
    }

    $stmt = $conn->prepare("SELECT id, password FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$targetUser) {
        http_response_code(404);
        echo json_encode(['message' => 'Target user not found']);
        exit;
    }
    
    $isPasswordVerificationRequired = true;
    if ($actorRole === 'admin' && $actorId !== $userId) {
        $isPasswordVerificationRequired = false;
    }

    if ($isPasswordVerificationRequired) {
        if ($actorRole !== 'admin' && $actorId !== $userId) {
            http_response_code(403);
            echo json_encode(['message' => 'Forbidden: You can only change your own password.']);
            exit;
        }

        if (!$currentPassword) {
            http_response_code(400);
            echo json_encode(['message' => 'Current password is required.']);
            exit;
        }

        if (!password_verify($currentPassword, $targetUser['password'])) {
            http_response_code(401);
            echo json_encode(['message' => 'Current password is incorrect']);
            exit;
        }
    }

    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    $updateQuery = "UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?";
    $updateStmt = $conn->prepare($updateQuery);

    if ($updateStmt->execute([$hashedPassword, $userId])) {
        echo json_encode(['message' => 'Password changed successfully']);
    } else {
        http_response_code(500);
        echo json_encode(['message' => 'Failed to update password.']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Server error: ' . $e->getMessage()]);
}