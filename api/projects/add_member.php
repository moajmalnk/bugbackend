<?php
require_once __DIR__ . '/../../config/cors.php';
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/database.php';
// TODO: Add your authentication check here to ensure only admins can use this endpoint

$database = new Database();
$pdo = $database->getConnection();

$data = json_decode(file_get_contents("php://input"), true);
$project_id = $data['project_id'] ?? null;
$user_id = $data['user_id'] ?? null;
$role = $data['role'] ?? null;

if (!$project_id || !$user_id || !$role) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing data']);
    exit;
}

try {
    // Check if already assigned
    $stmt = $pdo->prepare("SELECT * FROM project_members WHERE project_id = ? AND user_id = ?");
    $stmt->execute([$project_id, $user_id]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'User already assigned']);
        exit;
    }

    // Insert member
    $stmt = $pdo->prepare("INSERT INTO project_members (project_id, user_id, role) VALUES (?, ?, ?)");
    $stmt->execute([$project_id, $user_id, $role]);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
