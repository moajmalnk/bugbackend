<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/utils.php';
require_once __DIR__ . '/../../config/cors.php';

// Set proper headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    // Get task ID from URL parameters
    $taskId = $_GET['id'] ?? null;
    
    if (!$taskId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Task ID is required']);
        exit;
    }
    
    // Get authorization header
    $authHeader = '';
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    } else {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    }
    
    if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'No valid token provided']);
        exit;
    }
    
    $token = $matches[1];
    
    // Validate token
    $utils = new Utils();
    $decodedToken = $utils->validateJWT($token);
    
    if (!$decodedToken) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid token']);
        exit;
    }
    
    $userId = $decodedToken->user_id;
    
    // Get database connection
    $database = Database::getInstance();
    $conn = $database->getConnection();
    
    if (!$conn) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database connection failed']);
        exit;
    }
    
    // Check if user is assigned to this task
    $checkStmt = $conn->prepare("
        SELECT 1 FROM shared_task_assignees 
        WHERE shared_task_id = ? AND assigned_to = ?
    ");
    $checkStmt->execute([$taskId, $userId]);
    
    if (!$checkStmt->fetch()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'User is not assigned to this task']);
        exit;
    }
    
    // Remove user from assignees (decline)
    $deleteStmt = $conn->prepare("
        DELETE FROM shared_task_assignees 
        WHERE shared_task_id = ? AND assigned_to = ?
    ");
    
    if (!$deleteStmt->execute([$taskId, $userId])) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to decline task']);
        exit;
    }
    
    // If no more assignees, delete the task
    $countStmt = $conn->prepare("
        SELECT COUNT(*) as assignee_count FROM shared_task_assignees 
        WHERE shared_task_id = ?
    ");
    $countStmt->execute([$taskId]);
    $count = $countStmt->fetch(PDO::FETCH_ASSOC)['assignee_count'];
    
    if ($count == 0) {
        $deleteTaskStmt = $conn->prepare("DELETE FROM shared_tasks WHERE id = ?");
        $deleteTaskStmt->execute([$taskId]);
        echo json_encode(['success' => true, 'message' => 'Task declined and removed (no more assignees)']);
    } else {
        echo json_encode(['success' => true, 'message' => 'Task declined successfully']);
    }
    
} catch (Exception $e) {
    error_log("Error in decline_shared_task.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error: ' . $e->getMessage()]);
}
