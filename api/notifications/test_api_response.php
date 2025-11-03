<?php
/**
 * Test API Response Format
 * This mimics get_all.php but uses the proven working query
 * Helps verify the API response format matches what frontend expects
 */

require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../BaseAPI.php';

header('Content-Type: application/json');

try {
    // Get token from query string
    $token = $_GET['token'] ?? null;
    
    // If token provided in query, set it in Authorization header
    if ($token) {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    }
    
    $api = new BaseAPI();
    $userData = $api->validateToken();
    
    if (!$userData || !isset($userData->user_id)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
    
    $userId = (string)$userData->user_id;
    $conn = $api->getConnection();
    
    // Check if new columns exist
    $columnsExist = [];
    $colCheck = $conn->query("SHOW COLUMNS FROM notifications");
    $existingCols = $colCheck->fetchAll(PDO::FETCH_COLUMN);
    $columnsExist['entity_type'] = in_array('entity_type', $existingCols);
    $columnsExist['entity_id'] = in_array('entity_id', $existingCols);
    $columnsExist['project_id'] = in_array('project_id', $existingCols);
    
    // Use the EXACT working query from test_query.php
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    
    // Build query based on column existence
    $selectFields = [
        'n.id',
        'n.type',
        'n.title',
        'n.message',
    ];
    
    if ($columnsExist['entity_type']) {
        $selectFields[] = 'n.entity_type';
    } else {
        $selectFields[] = 'NULL as entity_type';
    }
    
    if ($columnsExist['entity_id']) {
        $selectFields[] = 'n.entity_id';
    } else {
        $selectFields[] = 'NULL as entity_id';
    }
    
    if ($columnsExist['project_id']) {
        $selectFields[] = 'n.project_id';
    } else {
        $selectFields[] = 'NULL as project_id';
    }
    
    $selectFields = array_merge($selectFields, [
        'n.bug_id',
        'n.bug_title',
        'n.status',
        'n.created_by',
        'n.created_at',
        'un.`read`',
        'un.read_at'
    ]);
    
    $query = "
        SELECT 
            " . implode(",\n            ", $selectFields) . "
        FROM user_notifications un
        JOIN notifications n ON un.notification_id = n.id
        WHERE un.user_id = ?
        ORDER BY n.created_at DESC
        LIMIT ? OFFSET ?
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute([$userId, $limit, $offset]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format exactly like get_all.php
    $formattedNotifications = [];
    foreach ($notifications as $notification) {
        $formattedNotifications[] = [
            'id' => (int)$notification['id'],
            'type' => $notification['type'] ?? 'info',
            'title' => $notification['title'] ?? 'Notification',
            'message' => $notification['message'] ?? '',
            'entity_type' => $notification['entity_type'] ?? null,
            'entity_id' => $notification['entity_id'] ?? null,
            'project_id' => $notification['project_id'] ?? null,
            'bug_id' => $notification['bug_id'] ?? null,
            'bug_title' => $notification['bug_title'] ?? null,
            'status' => $notification['status'] ?? null,
            'created_by' => $notification['created_by'] ?? 'system',
            'createdAt' => $notification['created_at'] ?? date('Y-m-d H:i:s'),
            'created_at' => $notification['created_at'] ?? date('Y-m-d H:i:s'),
            'read' => (bool)(int)$notification['read'],
            'read_at' => $notification['read_at'] ?? null
        ];
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'notifications' => $formattedNotifications,
            'count' => count($formattedNotifications),
            'limit' => $limit,
            'offset' => $offset
        ],
        'debug' => [
            'user_id' => $userId,
            'raw_count' => count($notifications),
            'query_used' => 'Direct JOIN (proven working)',
            'columns_exist' => $columnsExist,
            'migration_needed' => !($columnsExist['entity_type'] && $columnsExist['entity_id'] && $columnsExist['project_id'])
        ]
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], JSON_PRETTY_PRINT);
}

