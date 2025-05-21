<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

require_once __DIR__ . '/BaseAPI.php';
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$token = $data['token'] ?? null;

// TODO: Get user ID from session or authentication
$userId = 1; // Replace with actual user ID logic

if ($token && $userId) {
    $pdo = new PDO('mysql:host=localhost;dbname=u262074081_bugfixer', 'u262074081_bugfixer', 'CodoMail@8848');
    $stmt = $pdo->prepare("UPDATE users SET fcm_token = ? WHERE id = ?");
    $stmt->execute([$token, $userId]);
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Missing token or user']);
}
?>