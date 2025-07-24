<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/utils.php';

$pdo = Database::getInstance()->getConnection();

header('Content-Type: application/json');
$data = json_decode(file_get_contents('php://input'), true);
$email = $data['email'] ?? '';
$otp = $data['otp'] ?? '';
$method = $data['method'] ?? 'mail';

if ($method === 'whatsapp') {
    $phone = $data['phone'] ?? '';
    $phone = Utils::normalizePhone($phone);
    if (!$phone) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Phone required']);
        exit;
    }
    if (!$otp) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'OTP required']);
        exit;
    }
    // Check OTP
    $stmt = $pdo->prepare("SELECT * FROM user_otps WHERE phone = ? AND otp = ? AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
    $stmt->execute([$phone, $otp]);
    $otpRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$otpRow) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid or expired OTP']);
        exit;
    }

    // Get user by phone (try with and without +91)
    $stmt = $pdo->prepare("SELECT * FROM users WHERE phone = ?");
    $stmt->execute([$phone]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }

    // Optionally: delete OTP after use
    $stmt = $pdo->prepare("DELETE FROM user_otps WHERE id = ?");
    $stmt->execute([$otpRow['id']]);

} else {
    if (!$email) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Email required']);
        exit;
    }
    if (!$otp) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'OTP required']);
        exit;
    }
    // Check OTP
    $stmt = $pdo->prepare("SELECT * FROM user_otps WHERE email = ? AND otp = ? AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
    $stmt->execute([$email, $otp]);
    $otpRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$otpRow) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid or expired OTP']);
        exit;
    }

    // Get user by email
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }

    // Optionally: delete OTP after use
    $stmt = $pdo->prepare("DELETE FROM user_otps WHERE id = ?");
    $stmt->execute([$otpRow['id']]);
}

// Generate JWT token
$token = Utils::generateJWT($user['id'], $user['username'], $user['role']);
unset($user['password']);
echo json_encode([
    'success' => true,
    'user' => $user,
    'token' => $token
]);