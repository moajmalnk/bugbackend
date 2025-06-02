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
    // Get admins
    $stmt = $pdo->query("SELECT id, username, email, role FROM users WHERE role = 'admin'");
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get project members
    $stmt = $pdo->prepare("SELECT u.id, u.username, u.email, pm.role FROM project_members pm JOIN users u ON pm.user_id = u.id WHERE pm.project_id = ?");
    $stmt->execute([$project_id]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'admins' => $admins, 'members' => $members]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
