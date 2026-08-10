<?php
/**
 * Why: Confirm the emergency contact number during onboarding via WhatsApp OTP
 * so HR does not store an unreachable number.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../config/utils.php';
require_once __DIR__ . '/../../utils/whatsapp.php';

class SendEmergencyOtpAPI extends BaseAPI
{
    public function handle(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(405, 'Method not allowed');
            return;
        }

        try {
            $decoded = $this->validateToken();
            $userId = (string) ($decoded->user_id ?? '');
            if ($userId === '') {
                $this->sendJsonResponse(401, 'Invalid token');
                return;
            }

            $data = json_decode(file_get_contents('php://input'), true);
            if (!is_array($data)) {
                $data = $_POST;
            }

            $rawPhone = (string) ($data['phone'] ?? '');
            $digits = preg_replace('/\D/', '', $rawPhone);
            if (strlen($digits) === 12 && strpos($digits, '91') === 0) {
                $digits = substr($digits, 2);
            }
            if (strlen($digits) !== 10) {
                $this->sendJsonResponse(400, 'Enter a valid 10-digit Indian mobile number');
                return;
            }

            $phone = Utils::normalizePhone($digits); // +91XXXXXXXXXX
            $purposeEmail = 'onboarding_emg:' . $userId;

            // Rate-limit: one send per 45 seconds for this user+purpose
            $rateStmt = $this->conn->prepare(
                'SELECT created_at FROM user_otps
                 WHERE email = ? AND phone = ?
                 ORDER BY id DESC LIMIT 1'
            );
            $rateStmt->execute([$purposeEmail, $phone]);
            $last = $rateStmt->fetch(PDO::FETCH_ASSOC);
            if ($last && !empty($last['created_at'])) {
                $elapsed = time() - strtotime($last['created_at']);
                if ($elapsed < 45) {
                    $this->sendJsonResponse(429, 'Please wait ' . (45 - $elapsed) . 's before resending OTP', [
                        'retry_after' => 45 - $elapsed,
                    ]);
                    return;
                }
            }

            $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $expiresAt = date('Y-m-d H:i:s', time() + 5 * 60);

            // Clear prior unused OTPs for this onboarding purpose
            $del = $this->conn->prepare('DELETE FROM user_otps WHERE email = ?');
            $del->execute([$purposeEmail]);

            $ins = $this->conn->prepare(
                'INSERT INTO user_otps (email, phone, otp, expires_at) VALUES (?, ?, ?, ?)'
            );
            $ins->execute([$purposeEmail, $phone, $otp, $expiresAt]);

            $msg = "🔐 *BugRicer Emergency Contact OTP*\n\n";
            $msg .= "Your verification code is: *$otp*\n";
            $msg .= "Valid for 5 minutes.\n\n";
            $msg .= "⚠️ Do not share this code.\n";
            $msg .= "If you did not expect this, ignore the message.\n\n";
            $msg .= "🐞 _BugRicer Onboarding_";

            // Prefer international digits without '+' (same as login WhatsApp OTP).
            $sent = sendWhatsAppMessage('91' . $digits, $msg);
            if (!$sent) {
                $sent = sendWhatsAppMessage($phone, $msg);
            }
            if (!$sent && defined('WHATSAPP_API_KEY') && defined('WHATSAPP_API_URL')) {
                $url = WHATSAPP_API_URL
                    . '?apikey=' . urlencode(WHATSAPP_API_KEY)
                    . '&number=' . urlencode('91' . $digits)
                    . '&msg=' . urlencode($msg);
                $raw = @file_get_contents($url);
                $sent = is_string($raw) && $raw !== '' && stripos($raw, 'error') === false;
                error_log('send_emergency_otp fallback WhatsApp response: ' . substr((string) $raw, 0, 300));
            }
            if (!$sent) {
                $this->sendJsonResponse(502, 'Could not send WhatsApp OTP. Check the number and try again.');
                return;
            }

            $masked = substr($digits, 0, 2) . '******' . substr($digits, -2);
            $this->sendJsonResponse(200, 'OTP sent via WhatsApp', [
                'phone' => $phone,
                'masked' => $masked,
                'expires_in' => 300,
            ]);
        } catch (Exception $e) {
            error_log('send_emergency_otp error: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to send OTP');
        }
    }
}

$api = new SendEmergencyOtpAPI();
$api->handle();
