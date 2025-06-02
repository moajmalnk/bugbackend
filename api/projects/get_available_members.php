<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/database.php';

$database = new Database();
$pdo = $database->getConnection();

$project_id = $_GET['project_id'] ?? null;
if (!$project_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing project_id']);
    exit;
}

try {
    // Get already assigned user_ids
    $stmt = $pdo->prepare("SELECT user_id FROM project_members WHERE project_id = ?");
    $stmt->execute([$project_id]);
    $assigned = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Get all testers and developers not assigned to this project
    $sql = "SELECT id, username, email, role FROM users WHERE (role = 'tester' OR role = 'developer')";
    if (count($assigned) > 0) {
        $in = implode(',', array_fill(0, count($assigned), '?'));
        $sql .= " AND id NOT IN ($in)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($assigned);
    } else {
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
    }
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'users' => $users]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
