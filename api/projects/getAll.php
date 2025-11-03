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
// Non-admins only see projects they are members of
if ($is_admin) {
    // Admin (real or impersonating) - see all projects
    $query = "SELECT * FROM projects";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Non-admin role - return only assigned projects
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