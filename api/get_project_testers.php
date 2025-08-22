<?php
require_once __DIR__ . '/../config/cors.php';
header('Content-Type: application/json');


require_once __DIR__ . '/../config/database.php';
$database = new Database();
$pdo = $database->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$projectId = $_GET['project_id'] ?? null;
if (!$projectId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing project_id']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT u.email
        FROM users u
        WHERE u.role = 'tester'
          AND u.id NOT IN (
              SELECT pm.user_id
              FROM project_members pm
              WHERE pm.project_id = ?
          )
    ");
    $stmt->execute([$projectId]);
    $emails = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode(['success' => true, 'emails' => $emails]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}