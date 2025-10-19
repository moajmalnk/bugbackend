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
    
    // Validate token and check admin access
    $decoded = $api->validateToken();
    if ($decoded->role !== 'admin') {
        $api->sendJsonResponse(403, 'Only admins can add project members');
        exit;
    }

    $data = $api->getRequestData();
    $project_id = $data['project_id'] ?? null;
    $user_id = $data['user_id'] ?? null;
    $role = $data['role'] ?? null;

    if (!$project_id || !$user_id || !$role) {
        $api->sendJsonResponse(400, 'Missing required fields: project_id, user_id, role');
        exit;
    }

    // Check if already assigned
    $existing = $api->fetchSingleCached(
        "SELECT * FROM project_members WHERE project_id = ? AND user_id = ?",
        [$project_id, $user_id]
    );
    
    if ($existing) {
        $api->sendJsonResponse(400, 'User already assigned to this project');
        exit;
    }

    // Insert member using prepared statement
    $stmt = $api->prepare("INSERT INTO project_members (project_id, user_id, role, joined_at) VALUES (?, ?, ?, NOW())");
    $result = $stmt->execute([$project_id, $user_id, $role]);
    
    if (!$result) {
        $api->sendJsonResponse(500, 'Failed to add member to project');
        exit;
    }

    // Clear related cache
    $api->clearCache('project_members_' . $project_id);
    $api->clearCache('project_members_list_' . $project_id);
    $api->clearCache('user_projects_' . $user_id);

    $api->sendJsonResponse(200, 'Member added successfully to project');

} catch (Exception $e) {
    error_log("Error in add_member.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
