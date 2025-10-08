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
    
    // Mark user as not completed
    $updateStmt = $conn->prepare("
        UPDATE shared_task_assignees 
        SET completed_at = NULL 
        WHERE shared_task_id = ? AND assigned_to = ?
    ");
    
    if (!$updateStmt->execute([$taskId, $userId])) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to mark task as not completed']);
        exit;
    }
    
    // If task was completed, change it back to pending
    $taskUpdateStmt = $conn->prepare("
        UPDATE shared_tasks 
        SET status = 'pending', completed_at = NULL, completed_by = NULL
        WHERE id = ?
    ");
    $taskUpdateStmt->execute([$taskId]);
    
    echo json_encode(['success' => true, 'message' => 'Task marked as not completed successfully']);
    
} catch (Exception $e) {
    error_log("Error in uncomplete_shared_task.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error: ' . $e->getMessage()]);
}
