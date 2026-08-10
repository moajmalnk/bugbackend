<?php
/**
 * Why: Confirm emergency contact via WhatsApp OTP. Respond immediately after
 * storing the code so the UI feels instant; deliver WhatsApp after the response
 * is flushed to the client.
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

            $phone = Utils::normalizePhone($digits);
            $purposeEmail = 'onboarding_emg:' . $userId;

            $rateStmt = $this->conn->prepare(
                'SELECT created_at FROM user_otps
                 WHERE email = ? AND phone = ?
                 ORDER BY id DESC LIMIT 1'
            );
            $rateStmt->execute([$purposeEmail, $phone]);
            $last = $rateStmt->fetch(PDO::FETCH_ASSOC);
            if ($last && !empty($last['created_at'])) {
                $elapsed = time() - strtotime($last['created_at']);
                if ($elapsed < 30) {
                    $this->sendJsonResponse(429, 'Please wait ' . (30 - $elapsed) . 's before resending OTP', [
                        'retry_after' => 30 - $elapsed,
                    ]);
                    return;
                }
            }

            $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            $del = $this->conn->prepare('DELETE FROM user_otps WHERE email = ?');
            $del->execute([$purposeEmail]);

            $ins = $this->conn->prepare(
                'INSERT INTO user_otps (email, phone, otp, expires_at)
                 VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE))'
            );
            $ins->execute([$purposeEmail, $phone, $otp]);

            $masked = substr($digits, 0, 2) . '******' . substr($digits, -2);

            // Return immediately — WhatsApp delivery continues after flush.
            $this->flushJson(200, 'OTP sent via WhatsApp', [
                'phone' => $phone,
                'masked' => $masked,
                'expires_in' => 300,
            ]);

            // Skip WhatsApp if already verified (OTP cleared) during flush window.
            $still = $this->conn->prepare(
                'SELECT id FROM user_otps WHERE email = ? AND phone = ? AND otp = ? LIMIT 1'
            );
            $still->execute([$purposeEmail, $phone, $otp]);
            if (!$still->fetch(PDO::FETCH_ASSOC)) {
                return;
            }

            $msg = "🔐 *BugRicer Emergency Contact OTP*\n\n";
            $msg .= "Your verification code is: *$otp*\n";
            $msg .= "Valid for 5 minutes.\n\n";
            $msg .= "⚠️ Do not share this code.\n";
            $msg .= "If you did not expect this, ignore the message.\n\n";
            $msg .= "🐞 _BugRicer Onboarding_";

            $this->sendWhatsAppFast('91' . $digits, $msg);
        } catch (Exception $e) {
            error_log('send_emergency_otp error: ' . $e->getMessage());
            if (!headers_sent()) {
                $this->sendJsonResponse(500, 'Failed to send OTP');
            }
        }
    }

    /**
     * @param array<string, mixed>|null $data
     */
    private function flushJson(int $status, string $message, ?array $data = null): void
    {
        http_response_code($status);
        $payload = [
            'success' => $status >= 200 && $status < 300,
            'message' => $message,
        ];
        if ($data !== null) {
            $payload['data'] = $data;
        }
        echo json_encode($payload);
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            if (ob_get_level() > 0) {
                @ob_end_flush();
            }
            @flush();
        }
        ignore_user_abort(true);
    }

    private function sendWhatsAppFast(string $number, string $message): void
    {
        if (!defined('WHATSAPP_API_URL') || !defined('WHATSAPP_API_KEY')) {
            return;
        }
        $number = preg_replace('/\D/', '', $number);
        $url = WHATSAPP_API_URL
            . '?apikey=' . urlencode(WHATSAPP_API_KEY)
            . '&number=' . urlencode($number)
            . '&msg=' . urlencode($message);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 6,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        error_log("send_emergency_otp fast WA HTTP {$httpCode}: " . substr((string) $response, 0, 200));
    }
}

$api = new SendEmergencyOtpAPI();
$api->handle();
