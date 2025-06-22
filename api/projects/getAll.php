<?php
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/ProjectController.php';

$api = new BaseAPI();
$decoded = $api->validateToken();
$user_id = $decoded->user_id;
$user_role = $decoded->role;

if ($user_role === 'admin') {
    // Admins see all projects
    $query = "SELECT * FROM projects";
    $stmt = $api->getConnection()->prepare($query);
    $stmt->execute();
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Only assigned projects for testers/developers
    $query = "SELECT p.* FROM projects p
              JOIN project_members pm ON p.id = pm.project_id
              WHERE pm.user_id = ?";
    $stmt = $api->getConnection()->prepare($query);
    $stmt->execute([$user_id]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$api->sendJsonResponse(200, "Projects retrieved successfully", $projects); 