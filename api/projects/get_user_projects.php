<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../BaseAPI.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    $api = new BaseAPI();
    $decoded = $api->validateToken();

    if (($decoded->role ?? '') !== 'admin') {
        $api->sendJsonResponse(403, 'Only admins can view another user\'s project assignments');
        exit;
    }

    $userId = $_GET['user_id'] ?? null;
    if (!$userId) {
        $api->sendJsonResponse(400, 'Missing required field: user_id');
        exit;
    }

    // Confirm target user exists and is developer/tester
    $user = $api->fetchSingleCached(
        "SELECT id, username, role FROM users WHERE id = ? LIMIT 1",
        [$userId]
    );

    if (!$user) {
        $api->sendJsonResponse(404, 'User not found');
        exit;
    }

    $role = $user['role'] ?? '';
    if (!in_array($role, ['developer', 'tester'], true)) {
        $api->sendJsonResponse(400, 'Project assignment is only available for developers and testers');
        exit;
    }

    $projects = $api->fetchCached(
        "SELECT p.id, p.name, p.status, p.description, pm.role AS member_role, pm.joined_at
         FROM project_members pm
         INNER JOIN projects p ON p.id = pm.project_id
         WHERE pm.user_id = ?
         ORDER BY p.name ASC",
        [$userId],
        'user_assigned_projects_' . $userId,
        60
    );

    $api->sendJsonResponse(200, 'User projects retrieved successfully', [
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'role' => $user['role'],
        ],
        'projects' => $projects ?: [],
    ]);
} catch (Exception $e) {
    error_log('Error in get_user_projects.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
