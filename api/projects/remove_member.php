<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/database.php';
// TODO: Add your authentication check here to ensure only admins can use this endpoint

$database = new Database();
$pdo = $database->getConnection();

$data = json_decode(file_get_contents("php://input"), true);
$project_id = $data['project_id'] ?? null;
$user_id = $data['user_id'] ?? null;

if (!$project_id || !$user_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing data']);
    exit;
}

try {
    // Delete the member from project_members
    $stmt = $pdo->prepare("DELETE FROM project_members WHERE project_id = ? AND user_id = ?");
    $result = $stmt->execute([$project_id, $user_id]);
    
    if ($result && $stmt->rowCount() > 0) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Member not found or already removed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} 