<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../utils/send_email.php';
require_once __DIR__ . '/../../config/utils.php';
require_once __DIR__ . '/../../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$pdo = Database::getInstance()->getConnection();

header('Content-Type: application/json');
$data = json_decode(file_get_contents('php://input'), true);
$method = $data['method'] ?? 'mail';
$otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$expires_at = date('Y-m-d H:i:s', strtotime('+5 minutes'));

if ($method === 'whatsapp') {
    $phone = $data['phone'] ?? '';
    $phone = Utils::normalizePhone($phone);
    if (!$phone) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Phone required']);
        exit;
    }
    // Check if user exists with this phone
    $stmt = $pdo->prepare("SELECT id FROM users WHERE phone = ?");
    $stmt->execute([$phone]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User with this phone does not exist']);
        exit;
    }
    // Store OTP in DB
    $stmt = $pdo->prepare("INSERT INTO user_otps (phone, otp, expires_at) VALUES (?, ?, ?)");
    $stmt->execute([$phone, $otp, $expires_at]);
    // Send WhatsApp
    $msg = "🔐 *BugRicer Login OTP*\n\n";
    $msg .= "Your one-time password is: *$otp*\n";
    $msg .= "This OTP is valid for 5 minutes.\n\n";
    $msg .= "⚠️ *Do not share this code with anyone.*\n";
    $msg .= "If you did not request this, please ignore this message.\n\n";
    $msg .= "🐞 _Sent from BugRicer_";
    $apikey = "05ce7a9046414e42b3983330611f8bf5";
    $url = "http://148.251.129.118/whatsapp/api/send?mobile=$phone&msg=" . urlencode($msg) . "&apikey=$apikey";
    $response = file_get_contents($url);
    error_log('WhatsApp API response: ' . $response);
    echo json_encode([
        'success' => true, 
        'message' => 'OTP sent via WhatsApp',
        'phone' => $phone
    ]);
} else {
    $email = $data['email'] ?? '';
    if (!$email) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Email required']);
        exit;
    }
    // Check if user exists with this email
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User with this email does not exist']);
        exit;
    }
    // Store OTP in DB
    $stmt = $pdo->prepare("INSERT INTO user_otps (email, otp, expires_at) VALUES (?, ?, ?)");
    $stmt->execute([$email, $otp, $expires_at]);
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.hostinger.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'bugs@moajmalnk.in';
        $mail->Password = 'Codo@8848';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = 465;
        $mail->setFrom('bugs@moajmalnk.in', 'Bug Ricer');
        $mail->addAddress($email);
        $mail->Subject = 'Your BugRicer OTP';
        $mail->isHTML(true);
        $mail->Body = '<div style="font-family:Segoe UI,Arial,sans-serif;max-width:480px;margin:0 auto;background:#fff;border-radius:8px;box-shadow:0 2px 8px #e2e8f0;overflow:hidden;">
  <div style="background:#2563eb;color:#fff;padding:24px 0;text-align:center;">
    <h1 style="margin:0;font-size:28px;letter-spacing:1px;">BugRicer Login OTP</h1>
  </div>
  <div style="padding:32px 24px 24px 24px;text-align:center;">
    <p style="font-size:16px;margin-bottom:16px;">Use the following one-time password to sign in:</p>
    <div style="font-size:36px;font-weight:bold;letter-spacing:8px;margin:24px 0 16px 0;color:#2563eb;">' . htmlspecialchars($otp) . '</div>
    <p style="font-size:15px;margin-bottom:8px;">This OTP is valid for <b>5 minutes</b>.</p>
    <p style="font-size:14px;color:#dc2626;margin-bottom:0;">⚠️ Do not share this code with anyone.</p>
    <p style="font-size:13px;color:#64748b;margin-top:18px;">If you did not request this, you can safely ignore this email.</p>
  </div>
  <div style="background:#f8fafc;color:#64748b;padding:16px 0;text-align:center;font-size:12px;border-top:1px solid #e2e8f0;">
    <span>🐞 Sent from <b>BugRicer</b> &mdash; <a href="https://bugs.moajmalnk.in" style="color:#2563eb;text-decoration:none;">bugs.moajmalnk.in</a></span>
  </div>
</div>';
        $mail->AltBody = 'Your BugRicer OTP is: ' . $otp . '. This OTP is valid for 5 minutes. Do not share this code with anyone.';
        $mail->send();
        echo json_encode([
            'success' => true, 
            'message' => 'OTP sent via Email',
            'email' => $email
        ]);
    } catch (Exception $e) {
        error_log("OTP mail error: " . $mail->ErrorInfo);
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to send OTP email']);
    }
}