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
        // Fallback for servers that don't have getallheaders()
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    }
    
    if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'No valid token provided. Header: ' . $authHeader]);
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
    
    // Mark user as completed
    $updateStmt = $conn->prepare("
        UPDATE shared_task_assignees 
        SET completed_at = NOW() 
        WHERE shared_task_id = ? AND assigned_to = ?
    ");
    
    if (!$updateStmt->execute([$taskId, $userId])) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to mark task as completed']);
        exit;
    }
    
    // Check if all assignees have completed
    $countStmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_assignees,
            COUNT(completed_at) as completed_assignees
        FROM shared_task_assignees 
        WHERE shared_task_id = ?
    ");
    $countStmt->execute([$taskId]);
    $counts = $countStmt->fetch(PDO::FETCH_ASSOC);
    
    // If all assignees completed, mark task as completed
    if ($counts && $counts['total_assignees'] > 0 && $counts['total_assignees'] == $counts['completed_assignees']) {
        $taskUpdateStmt = $conn->prepare("
            UPDATE shared_tasks 
            SET status = 'completed', completed_at = NOW(), completed_by = ?
            WHERE id = ?
        ");
        $taskUpdateStmt->execute([$userId, $taskId]);
    }
    
    echo json_encode(['success' => true, 'message' => 'Task marked as completed successfully']);
    
} catch (Exception $e) {
    error_log("Error in complete_shared_task.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error: ' . $e->getMessage()]);
}
