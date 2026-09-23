<?php
// Prevent caching to ensure fresh data
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/ProjectController.php';

$api = new BaseAPI();
$decoded = $api->validateToken();
$user_id = $decoded->user_id;
$user_role = $decoded->role;

// Check impersonation in multiple ways for robustness
$is_impersonated = false;
if (isset($decoded->impersonated)) {
    $is_impersonated = $decoded->impersonated === true || $decoded->impersonated === 'true' || $decoded->impersonated === 1;
}
// Also check if admin_id is set (indicating impersonation)
if (!$is_impersonated && isset($decoded->admin_id) && !empty($decoded->admin_id)) {
    $is_impersonated = true;
}

$conn = $api->getConnection();
$user_role_lower = strtolower(trim($user_role));

$is_real_admin = ($user_role_lower === 'admin' && !$is_impersonated);
$is_developer = ($user_role_lower === 'developer' && !$is_impersonated);

// Why: While impersonating, always show the *target* user's assigned projects —
// not the admin's full catalog — so bug forms match tester UX.
$includeArchived = $is_real_admin || $is_developer;
$archivedClause = $includeArchived ? '' : " AND (p.status != 'archived' OR p.status IS NULL)";

// Why: Soft-deleted recycle-bin projects must never appear in the live Projects list/counts.
$hasDeletedAt = false;
try {
    $colStmt = $conn->query("SHOW COLUMNS FROM projects LIKE 'deleted_at'");
    $hasDeletedAt = $colStmt && $colStmt->rowCount() > 0;
} catch (Throwable $e) {
    $hasDeletedAt = false;
}
$liveClause = $hasDeletedAt ? 'deleted_at IS NULL' : '1=1';
$liveClauseP = $hasDeletedAt ? 'p.deleted_at IS NULL' : '1=1';

if ($is_real_admin || $is_developer) {
    // Real admin/developer: return live projects; archived hidden on frontend unless filtered/searched
    $query = $includeArchived
        ? "SELECT * FROM projects WHERE {$liveClause} ORDER BY created_at DESC"
        : "SELECT * FROM projects WHERE {$liveClause} AND (status != 'archived' OR status IS NULL) ORDER BY created_at DESC";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Testers, other roles, and any impersonation — assigned live projects only
    $query = "SELECT DISTINCT p.* FROM projects p
              INNER JOIN project_members pm ON p.id = pm.project_id
              WHERE pm.user_id = ? AND {$liveClauseP}{$archivedClause}
              ORDER BY p.created_at DESC";
    $stmt = $conn->prepare($query);
    $stmt->execute([$user_id]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Add members + bug/member stats in batch (avoids N+1 API calls from the frontend)
require_once __DIR__ . '/projectStatsHelper.php';
attachProjectListStats($conn, $projects);

$api->sendJsonResponse(200, "Projects retrieved successfully", $projects); 