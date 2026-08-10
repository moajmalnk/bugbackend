<?php
/**
 * Why: Confirm onboarding contact email via OTP. Respond as soon as the code is
 * stored so the UI can show OTP fields instantly; SMTP delivery runs after flush.
 *
 * Purpose key must fit user_otps.phone VARCHAR(20) — never store full UUIDs there.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../config/composer_autoload.php';
require_once __DIR__ . '/../../utils/email.php';
require_once __DIR__ . '/../../utils/onboarding_contact_unique.php';

class SendContactEmailOtpAPI extends BaseAPI
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

            $email = strtolower(trim((string) ($data['email'] ?? '')));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->sendJsonResponse(400, 'Enter a valid email address');
                return;
            }
            if (strlen($email) > 150) {
                $this->sendJsonResponse(400, 'Email is too long');
                return;
            }

            $conflict = br_onboarding_contact_email_conflict($this->conn, $email, $userId);
            if ($conflict !== null) {
                $this->sendJsonResponse(409, $conflict);
                return;
            }

            $purposePhone = $this->purposeKey($userId);

            $rateStmt = $this->conn->prepare(
                'SELECT created_at FROM user_otps
                 WHERE phone = ? AND email = ?
                 ORDER BY id DESC LIMIT 1'
            );
            $rateStmt->execute([$purposePhone, $email]);
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

            $del = $this->conn->prepare('DELETE FROM user_otps WHERE phone = ? OR (email = ? AND phone LIKE ?)');
            $del->execute([$purposePhone, $email, 'onboarding_mail:%']);

            $ins = $this->conn->prepare(
                'INSERT INTO user_otps (email, phone, otp, expires_at)
                 VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE))'
            );
            $ins->execute([$email, $purposePhone, $otp]);

            $parts = explode('@', $email);
            $local = $parts[0] ?? '';
            $domain = $parts[1] ?? '';
            $maskedLocal = strlen($local) <= 2
                ? str_repeat('*', strlen($local))
                : substr($local, 0, 1) . str_repeat('*', max(1, strlen($local) - 2)) . substr($local, -1);
            $masked = $maskedLocal . '@' . $domain;

            $this->flushJson(200, 'OTP sent via email', [
                'email' => $email,
                'masked' => $masked,
                'expires_in' => 300,
            ]);

            // Skip SMTP if user already verified (OTP row cleared) while we were flushing.
            $still = $this->conn->prepare(
                'SELECT id FROM user_otps WHERE phone = ? AND email = ? AND otp = ? LIMIT 1'
            );
            $still->execute([$purposePhone, $email, $otp]);
            if (!$still->fetch(PDO::FETCH_ASSOC)) {
                return;
            }

            $html = '<div style="font-family:Segoe UI,Arial,sans-serif;max-width:480px;margin:0 auto;background:#fff;border-radius:8px;box-shadow:0 2px 8px #e2e8f0;overflow:hidden;">
  <div style="background:#2563eb;color:#fff;padding:24px 0;text-align:center;">
    <h1 style="margin:0;font-size:24px;letter-spacing:1px;">BugRicer Email Verification</h1>
  </div>
  <div style="padding:32px 24px 24px 24px;text-align:center;">
    <p style="font-size:16px;margin-bottom:16px;">Use this code to verify your contact email for onboarding:</p>
    <div style="font-size:36px;font-weight:bold;letter-spacing:8px;margin:24px 0 16px 0;color:#2563eb;">' . htmlspecialchars($otp) . '</div>
    <p style="font-size:15px;margin-bottom:8px;">This OTP is valid for <b>5 minutes</b>.</p>
    <p style="font-size:14px;color:#dc2626;margin-bottom:0;">Do not share this code with anyone.</p>
  </div>
  <div style="background:#f8fafc;color:#64748b;padding:16px 0;text-align:center;font-size:12px;border-top:1px solid #e2e8f0;">
    <span>Sent from <b>BugRicer</b> onboarding</span>
  </div>
</div>';
            $text = "Your BugRicer onboarding email OTP is: {$otp}. Valid for 5 minutes.";

            @ini_set('default_socket_timeout', '8');
            sendEmail($email, 'BugRicer — verify your contact email', $html, $text);
        } catch (Exception $e) {
            error_log('send_contact_email_otp error: ' . $e->getMessage());
            if (!headers_sent()) {
                $this->sendJsonResponse(500, 'Failed to send email OTP');
            }
        }
    }

    private function purposeKey(string $userId): string
    {
        // Fits VARCHAR(20): om_ + 16 hex chars
        return 'om_' . substr(hash('sha256', $userId), 0, 16);
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
}

$api = new SendContactEmailOtpAPI();
$api->handle();
