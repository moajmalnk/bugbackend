<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/projectStatsHelper.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    $api = new BaseAPI();
    $decoded = $api->validateToken();
    $user_id = (int) $decoded->user_id;
    $user_role = $decoded->role;

    $conn = $api->getConnection();
    $user_role_lower = strtolower(trim((string) $user_role));
    $is_admin = BaseAPI::hasGlobalDataScope($decoded);
    // Why: Developers browse all projects when not impersonating; impersonation
    // uses the target user's membership only.
    $is_developer = ($user_role_lower === 'developer' && !BaseAPI::isImpersonating($decoded));

    $cacheKey = 'project_stats_' . $user_id . '_' . ($is_admin ? 'admin' : ($is_developer ? 'developer' : $user_role_lower));
    $cached = $api->getCache($cacheKey);
    if ($cached !== null) {
        $api->sendJsonResponse(200, 'Project stats retrieved successfully (cached)', $cached);
        exit;
    }

    $hasDeletedAt = false;
    try {
        $colStmt = $conn->query("SHOW COLUMNS FROM projects LIKE 'deleted_at'");
        $hasDeletedAt = $colStmt && $colStmt->rowCount() > 0;
    } catch (Throwable $e) {
        $hasDeletedAt = false;
    }
    $liveClause = $hasDeletedAt ? ' AND deleted_at IS NULL' : '';
    $liveClauseP = $hasDeletedAt ? ' AND p.deleted_at IS NULL' : '';

    if ($is_admin || $is_developer) {
        $projectStmt = $conn->prepare(
            "SELECT id FROM projects WHERE (status != 'archived' OR status IS NULL){$liveClause}"
        );
        $projectStmt->execute();
    } else {
        $projectStmt = $conn->prepare(
            "SELECT DISTINCT p.id
             FROM projects p
             INNER JOIN project_members pm ON p.id = pm.project_id
             WHERE pm.user_id = ?
               AND (p.status != 'archived' OR p.status IS NULL){$liveClauseP}"
        );
        $projectStmt->execute([$user_id]);
    }

    $projectIds = array_map(
        static fn($row) => $row['id'],
        $projectStmt->fetchAll(PDO::FETCH_ASSOC)
    );

    $stats = buildProjectStatsBundle($conn, $projectIds, $user_id, $is_admin);

    $api->setCache($cacheKey, $stats, 60);
    $api->sendJsonResponse(200, 'Project stats retrieved successfully', $stats);
} catch (Exception $e) {
    error_log('Error in get_project_stats.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
