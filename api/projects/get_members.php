<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/ProjectMemberController.php';
require_once __DIR__ . '/../../utils/user_avatar.php';

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

    // Why: v3 includes avatar so old cached payloads without photos are not reused.
    $cacheKey = 'project_members_v3_' . $project_id;
    $cachedResult = $api->getCache($cacheKey);
    
    if ($cachedResult !== null) {
        $api->sendJsonResponse(200, 'Project members retrieved successfully (cached)', $cachedResult);
        exit;
    }

    $userCols = [];
    $colRes = $api->getConnection()->query('SHOW COLUMNS FROM users');
    if ($colRes) {
        while ($row = $colRes->fetch(PDO::FETCH_ASSOC)) {
            $userCols[] = $row['Field'];
        }
    }

    $adminSelect = br_user_avatar_select_cols(['id', 'username', 'email', 'role'], $userCols);

    $memberSelect = ['u.id', 'u.username', 'u.email', 'u.role AS user_role', 'pm.role AS member_role'];
    foreach (['avatar', 'profile_picture', 'profile_picture_url'] as $col) {
        if (in_array($col, $userCols, true)) {
            $memberSelect[] = 'u.`' . $col . '`';
        }
    }

    $admins = $api->fetchCached(
        'SELECT ' . implode(', ', $adminSelect) . " FROM users WHERE role = 'admin'",
        [],
        'admin_users_v3',
        600
    );
    $admins = array_map('br_user_with_resolved_avatar', $admins ?: []);

    $members = $api->fetchCached(
        'SELECT ' . implode(', ', $memberSelect) . '
         FROM project_members pm
         JOIN users u ON pm.user_id = u.id
         WHERE pm.project_id = ?',
        [$project_id],
        'project_members_list_v3_' . $project_id,
        300
    );
    $members = array_map('br_user_with_resolved_avatar', $members ?: []);

    foreach ($members as &$m) {
        if (!isset($m['role']) && isset($m['member_role'])) {
            $m['role'] = $m['member_role'];
        }
    }
    unset($m);

    $result = [
        'admins' => $admins,
        'members' => $members,
    ];

    $api->setCache($cacheKey, $result, 300);
    $api->sendJsonResponse(200, 'Project members retrieved successfully', $result);

} catch (Exception $e) {
    error_log("Error in get_members.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
