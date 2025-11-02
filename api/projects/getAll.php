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

// Filter projects based on user role
// CRITICAL: If role is 'tester' or 'developer', ALWAYS filter by project_members (regardless of impersonation flag)
// Only real admins (role='admin' AND not impersonating) see all projects
if ($user_role_lower === 'admin' && !$is_impersonated) {
    // Real admin (not impersonating) - see all projects
    $query = "SELECT * FROM projects";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Non-admin role OR admin impersonating - return only assigned projects
    // This covers: tester, developer, or admin impersonating another user
    $query = "SELECT DISTINCT p.* FROM projects p
              INNER JOIN project_members pm ON p.id = pm.project_id
              WHERE pm.user_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->execute([$user_id]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Add members array to each project for frontend filtering
foreach ($projects as &$project) {
    $stmt2 = $conn->prepare("SELECT user_id FROM project_members WHERE project_id = ?");
    $stmt2->execute([$project['id']]);
    $project['members'] = array_column($stmt2->fetchAll(PDO::FETCH_ASSOC), 'user_id');
}

$api->sendJsonResponse(200, "Projects retrieved successfully", $projects); 