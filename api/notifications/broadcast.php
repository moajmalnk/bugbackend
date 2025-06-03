<?php
require_once __DIR__ . '/../../config/cors.php';
header('Content-Type: application/json');

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../users/UserController.php';

try {
    // Verify user authentication
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? '';
    
    if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    
    $token = substr($authHeader, 7);
    $userController = new UserController($pdo);
    $currentUser = $userController->verifyToken($token);
    
    if (!$currentUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid token']);
        exit;
    }
    
    // Get request body
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    
    if (!$data) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
        exit;
    }
    
    // Validate required fields
    $requiredFields = ['type', 'title', 'message', 'bugId', 'bugTitle', 'createdBy'];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
            exit;
        }
    }
    
    // Create notifications table if it doesn't exist
    $createTableSQL = "
        CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            type ENUM('new_bug', 'status_change') NOT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            bug_id INT NOT NULL,
            bug_title VARCHAR(255) NOT NULL,
            status VARCHAR(50) NULL,
            created_by VARCHAR(100) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_created_at (created_at),
            INDEX idx_type (type),
            INDEX idx_bug_id (bug_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    
    $pdo->exec($createTableSQL);
    
    // Insert the notification
    $sql = "
        INSERT INTO notifications (type, title, message, bug_id, bug_title, status, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ";
    
    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute([
        $data['type'],
        $data['title'],
        $data['message'],
        $data['bugId'],
        $data['bugTitle'],
        $data['status'] ?? null,
        $data['createdBy']
    ]);
    
    if ($result) {
        $notificationId = $pdo->lastInsertId();
        
        echo json_encode([
            'success' => true,
            'message' => 'Notification broadcasted successfully',
            'notificationId' => $notificationId
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to broadcast notification']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
} 