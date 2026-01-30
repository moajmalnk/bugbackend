<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../utils/send_email.php';
require_once __DIR__ . '/../../config/utils.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

try {
    header('Content-Type: application/json');
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

    $pdo = Database::getInstance()->getConnection();
    if (!$pdo) {
        throw new Exception('Database connection failed');
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
        exit;
    }
    
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
    $apikey = "dfedcb5f0d514809f40f26b078eba6b8";
    $url = "https://notifyapi.bugricer.com/wapp/api/send?apikey=$apikey&number=$phone&msg=" . urlencode($msg);
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
        
        // Use the existing sendOtpEmail function but with better HTML formatting
        $html_body = '<div style="font-family:Segoe UI,Arial,sans-serif;max-width:480px;margin:0 auto;background:#fff;border-radius:8px;box-shadow:0 2px 8px #e2e8f0;overflow:hidden;">
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
    <span>🐞 Sent from <b>BugRicer</b> &mdash; <a href="https://bugricer.com" style="color:#2563eb;text-decoration:none;">bugricer.com</a></span>
  </div>
</div>';
        
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            // $mail->SMTPDebug = 2; // Enable verbose debug output
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'codo.bugricer@gmail.com';
            $mail->Password = 'ieka afeu uhds qkam';  // New app password
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;
            $mail->Debugoutput = function($str, $level) {
                error_log("PHPMailer SMTP Debug: $str");
            };
            $mail->setFrom('codo.bugricer@gmail.com', 'BugRicer');
            $mail->addAddress($email);
            $mail->Subject = 'Your BugRicer OTP';
            $mail->isHTML(true);
            $mail->Body = $html_body;
            $mail->AltBody = 'Your BugRicer OTP is: ' . $otp . '. This OTP is valid for 5 minutes. Do not share this code with anyone.';
            $mail->send();
            echo json_encode([
                'success' => true, 
                'message' => 'OTP sent via Email',
                'email' => $email
            ]);
        } catch (Exception $e) {
            error_log("OTP mail error: " . $mail->ErrorInfo);
            error_log("OTP mail exception: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to send OTP email']);
        }
    }
} catch (Exception $e) {
    error_log("send_otp.php fatal error: " . $e->getMessage());
    error_log("send_otp.php trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error: ' . $e->getMessage()]);
}