<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../BaseAPI.php';

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    $api = new BaseAPI();
    
    $project_id = $_GET['project_id'] ?? null;
    if (!$project_id) {
        $api->sendJsonResponse(400, 'Missing project_id');
        exit;
    }

    // Create cache key for available members
    $cacheKey = 'available_members_' . $project_id;
    $cachedResult = $api->getCache($cacheKey);
    
    if ($cachedResult !== null) {
        $api->sendJsonResponse(200, 'Available members retrieved successfully (cached)', $cachedResult);
        exit;
    }

    // Get already assigned user_ids with caching
    $assigned = $api->fetchCached(
        "SELECT user_id FROM project_members WHERE project_id = ?",
        [$project_id],
        'assigned_members_' . $project_id,
        300 // Cache for 5 minutes
    );
    
    $assignedIds = array_column($assigned, 'user_id');

    // Get all testers and developers not assigned to this project
    if (count($assignedIds) > 0) {
        $placeholders = str_repeat('?,', count($assignedIds) - 1) . '?';
        $sql = "SELECT id, username, email, role FROM users 
                WHERE (role = 'tester' OR role = 'developer') 
                AND id NOT IN ($placeholders)";
        $params = $assignedIds;
    } else {
        $sql = "SELECT id, username, email, role FROM users 
                WHERE (role = 'tester' OR role = 'developer')";
        $params = [];
    }

    $users = $api->fetchCached(
        $sql,
        $params,
        'available_users_' . md5($sql . implode('', $params)),
        300 // Cache for 5 minutes
    );

    $result = ['users' => $users];
    
    // Cache the complete result
    $api->setCache($cacheKey, $result, 300);

    $api->sendJsonResponse(200, 'Available members retrieved successfully', $result);

} catch (Exception $e) {
    error_log("Error in get_available_members.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
