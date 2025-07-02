<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../utils/send_email.php';

$pdo = Database::getInstance()->getConnection();

header('Content-Type: application/json');
$data = json_decode(file_get_contents('php://input'), true);
$email = $data['email'] ?? '';

if (!$email) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Email required']);
    exit;
}

// Check if user exists
$stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'User not found']);
    exit;
}

// Generate OTP
$otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$expires = date('Y-m-d H:i:s', time() + 60 * 5); // 5 minutes

// Store OTP
$stmt = $pdo->prepare("INSERT INTO user_otps (email, otp, expires_at) VALUES (?, ?, ?)");
$stmt->execute([$email, $otp, $expires]);

// Send OTP
$subject = "Your BugRacer Login OTP";
$body = <<<HTML
<div style="font-family: 'Segoe UI', Arial, sans-serif; background: #f4f7f6; padding: 32px;">
  <div style="max-width: 480px; margin: 0 auto; background: #fff; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.07); overflow: hidden;">
    <div style="background: #2563eb; color: #fff; padding: 24px 0; text-align: center;">
      <img src="https://fonts.gstatic.com/s/e/notoemoji/16.0/1f41e/32.png" alt="BugRacer" style="width: 40px; height: 40px; margin-bottom: 8px;">
      <h1 style="margin: 0; font-size: 24px; letter-spacing: 1px;">BugRacer Login OTP</h1>
    </div>
    <div style="padding: 32px 24px 24px 24px; text-align: center;">
      <p style="font-size: 16px; color: #222; margin-bottom: 16px;">
        Use the following <b>One-Time Password (OTP)</b> to log in to your BugRacer account:
      </p>
      <div style="font-size: 32px; font-weight: bold; letter-spacing: 8px; color: #2563eb; margin: 24px 0;">
        $otp
      </div>
      <p style="font-size: 15px; color: #444; margin-bottom: 0;">
        This OTP is valid for <b>5 minutes</b>.<br>
        If you did not request this, you can safely ignore this email.
      </p>
    </div>
    <div style="background: #f8fafc; color: #64748b; padding: 16px; text-align: center; font-size: 12px;">
      &copy; 2025 BugRacer. All rights reserved.
    </div>
  </div>
</div>
HTML;
sendWelcomeEmail($email, $subject, $body);

echo json_encode(['success' => true, 'message' => 'OTP sent']);