<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';
$database = new Database();
$pdo = $database->getConnection();

try {
    $stmt = $pdo->query("SELECT email FROM users WHERE role = 'tester'");
    $emails = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode(['success' => true, 'emails' => $emails]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}