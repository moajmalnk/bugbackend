<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/ProjectMemberController.php';

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

    // Create cache key for this project's members
    $cacheKey = 'project_members_' . $project_id;
    $cachedResult = $api->getCache($cacheKey);
    
    if ($cachedResult !== null) {
        $api->sendJsonResponse(200, 'Project members retrieved successfully (cached)', $cachedResult);
        exit;
    }

    // Get admins with caching
    $admins = $api->fetchCached(
        "SELECT id, username, email, role FROM users WHERE role = 'admin'",
        [],
        'admin_users',
        600 // Cache for 10 minutes
    );

    // Get project members using optimized query
    $members = $api->fetchCached(
        "SELECT u.id, u.username, u.email, pm.role 
         FROM project_members pm 
         JOIN users u ON pm.user_id = u.id 
         WHERE pm.project_id = ?",
        [$project_id],
        'project_members_list_' . $project_id,
        300 // Cache for 5 minutes
    );

    $result = [
        'admins' => $admins,
        'members' => $members
    ];

    // Cache the complete result
    $api->setCache($cacheKey, $result, 300);

    $api->sendJsonResponse(200, 'Project members retrieved successfully', $result);

} catch (Exception $e) {
    error_log("Error in get_members.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
