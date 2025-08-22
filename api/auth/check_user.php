<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../utils/send_email.php';
require_once __DIR__ . '/../../config/utils.php';

$pdo = Database::getInstance()->getConnection();
header('Content-Type: application/json');
$data = json_decode(file_get_contents('php://input'), true);

$type = $data['type'] ?? '';
$value = $data['value'] ?? '';

if (!$type || !$value) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Type and value required']);
    exit;
}

if ($type === 'email') {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
} else if ($type === 'phone') {
    $value = Utils::normalizePhone($value);
    $stmt = $pdo->prepare("SELECT id FROM users WHERE phone = ?");
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid type']);
    exit;
}

$stmt->execute([$value]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user) {
    echo json_encode(['success' => true, 'exists' => true]);
} else {
    echo json_encode(['success' => true, 'exists' => false]);
}
