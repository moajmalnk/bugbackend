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
        $api->sendJsonResponse(403, 'Only admins can remove project members');
        exit;
    }

    $data = $api->getRequestData();
    $project_id = $data['project_id'] ?? null;
    $user_id = $data['user_id'] ?? null;

    if (!$project_id || !$user_id) {
        $api->sendJsonResponse(400, 'Missing required fields: project_id, user_id');
        exit;
    }

    // Delete the member from project_members
    $stmt = $api->prepare("DELETE FROM project_members WHERE project_id = ? AND user_id = ?");
    $result = $stmt->execute([$project_id, $user_id]);
    
    if ($result && $stmt->rowCount() > 0) {
        // Clear related cache
        $api->clearCache('project_members_' . $project_id);
        $api->clearCache('project_members_list_' . $project_id);
        $api->clearCache('user_projects_' . $user_id);
        
        $api->sendJsonResponse(200, 'Member removed successfully from project');
    } else {
        $api->sendJsonResponse(404, 'Member not found in this project or already removed');
    }
    
} catch (Exception $e) {
    error_log("Error in remove_member.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
} 