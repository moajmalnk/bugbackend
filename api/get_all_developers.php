<?php
require_once __DIR__ . '/../config/cors.php';
header('Content-Type: application/json');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';
$database = new Database();
$pdo = $database->getConnection();

try {
    $stmt = $pdo->query("SELECT email FROM users WHERE role = 'developer'");
    $emails = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode(['success' => true, 'emails' => $emails]);
} catch (Exception $e) {
    error_log("Error in get_all_developers.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}