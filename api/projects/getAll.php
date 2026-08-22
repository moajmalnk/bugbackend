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

// Check if the actual admin (not the impersonated user) has admin role
$admin_role = isset($decoded->admin_role) ? strtolower(trim($decoded->admin_role)) : null;
$is_admin = ($user_role_lower === 'admin' && !$is_impersonated) || ($is_impersonated && $admin_role === 'admin');

// Filter projects based on user role
// Admins (either real admins or admins impersonating) see all projects
// Developers see all projects (frontend handles filtering for "my-projects" tab)
// Other non-admins only see projects they are members of
$is_developer = ($user_role_lower === 'developer');
$includeArchived = $is_admin || $is_developer;
$archivedClause = $includeArchived ? '' : " AND (p.status != 'archived' OR p.status IS NULL)";

if ($is_admin || $is_developer) {
    // Admin/developer: return all projects; archived hidden on frontend unless filtered/searched
    $query = $includeArchived
        ? 'SELECT * FROM projects ORDER BY created_at DESC'
        : "SELECT * FROM projects WHERE (status != 'archived' OR status IS NULL) ORDER BY created_at DESC";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Non-admin, non-developer — assigned projects only (archived excluded)
    $query = "SELECT DISTINCT p.* FROM projects p
              INNER JOIN project_members pm ON p.id = pm.project_id
              WHERE pm.user_id = ?{$archivedClause}
              ORDER BY p.created_at DESC";
    $stmt = $conn->prepare($query);
    $stmt->execute([$user_id]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Add members + bug/member stats in batch (avoids N+1 API calls from the frontend)
require_once __DIR__ . '/projectStatsHelper.php';
attachProjectListStats($conn, $projects);

$api->sendJsonResponse(200, "Projects retrieved successfully", $projects); 