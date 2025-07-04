<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../utils/send_email.php';

$pdo = Database::getInstance()->getConnection();

header('Content-Type: application/json');
$data = json_decode(file_get_contents('php://input'), true);
$method = $data['method'] ?? 'mail';
$otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$expires_at = date('Y-m-d H:i:s', strtotime('+5 minutes'));

if ($method === 'whatsapp') {
    $phone = $data['phone'] ?? '';
    if (!$phone) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Phone required']);
        exit;
    }
    // Store OTP in DB
    $stmt = $pdo->prepare("INSERT INTO user_otps (phone, otp, expires_at) VALUES (?, ?, ?)");
    $stmt->execute([$phone, $otp, $expires_at]);
    // Send WhatsApp
    $msg = "🔐 *BugRacer Login OTP*\n\n";
    $msg .= "Your one-time password is: *$otp*\n";
    $msg .= "This OTP is valid for 5 minutes.\n\n";
    $msg .= "⚠️ *Do not share this code with anyone.*\n";
    $msg .= "If you did not request this, please ignore this message.\n\n";
    $msg .= "🐞 _Sent from BugRacer_";
    $apikey = "a522ae4a3b274d43acf155772bb82a7c";
    $url = "http://148.251.129.118/whatsapp/api/send?mobile=$phone&msg=" . urlencode($msg) . "&apikey=$apikey";
    file_get_contents($url);
    echo json_encode(['success' => true, 'message' => 'OTP sent via WhatsApp']);
} else {
    $email = $data['email'] ?? '';
    if (!$email) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Email required']);
        exit;
    }
    // Store OTP in DB
    $stmt = $pdo->prepare("INSERT INTO user_otps (email, otp, expires_at) VALUES (?, ?, ?)");
    $stmt->execute([$email, $otp, $expires_at]);
    // Send Email (your logic)
    // mail($email, "Your BugRacer OTP", "Your OTP is: $otp");
    echo json_encode(['success' => true, 'message' => 'OTP sent via Email']);
}