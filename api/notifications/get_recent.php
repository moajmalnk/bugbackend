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
    
    if (!$data || !isset($data['since'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing since parameter']);
        exit;
    }
    
    $since = $data['since'];
    
    // Validate date format
    $sinceDateTime = DateTime::createFromFormat(DateTime::ATOM, $since);
    if (!$sinceDateTime) {
        // Try alternative format
        $sinceDateTime = DateTime::createFromFormat('Y-m-d H:i:s', $since);
        if (!$sinceDateTime) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid date format']);
            exit;
        }
    }
    
    // Check if notifications table exists
    $tableExistsSQL = "SHOW TABLES LIKE 'notifications'";
    $result = $pdo->query($tableExistsSQL);
    
    if ($result->rowCount() == 0) {
        // Table doesn't exist yet, return empty notifications
        echo json_encode([
            'success' => true,
            'notifications' => [],
            'count' => 0
        ]);
        exit;
    }
    
    // Get notifications since the specified time
    // Exclude notifications created by the current user to avoid self-notifications
    $sql = "
        SELECT 
            id,
            type,
            title,
            message,
            bug_id as bugId,
            bug_title as bugTitle,
            status,
            created_by as createdBy,
            created_at as createdAt
        FROM notifications 
        WHERE created_at > ? 
        AND created_by != ?
        ORDER BY created_at DESC 
        LIMIT 50
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $sinceDateTime->format('Y-m-d H:i:s'),
        $currentUser['name'] ?? $currentUser['username'] ?? 'Unknown'
    ]);
    
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Convert to proper format
    $formattedNotifications = array_map(function($notification) {
        return [
            'id' => $notification['id'],
            'type' => $notification['type'],
            'title' => $notification['title'],
            'message' => $notification['message'],
            'bugId' => $notification['bugId'],
            'bugTitle' => $notification['bugTitle'],
            'status' => $notification['status'],
            'createdBy' => $notification['createdBy'],
            'createdAt' => $notification['createdAt']
        ];
    }, $notifications);
    
    echo json_encode([
        'success' => true,
        'notifications' => $formattedNotifications,
        'count' => count($formattedNotifications),
        'since' => $since
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
} 