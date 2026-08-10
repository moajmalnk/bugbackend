<?php
/**
 * Why: Confirm onboarding contact email via OTP. Respond as soon as the code is
 * stored so the UI can show OTP fields instantly; SMTP delivery runs after flush.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../config/composer_autoload.php';
require_once __DIR__ . '/../../utils/email.php';

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

            $purposePhone = 'onboarding_mail:' . $userId;

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
            $expiresAt = date('Y-m-d H:i:s', time() + 5 * 60);

            $del = $this->conn->prepare('DELETE FROM user_otps WHERE phone = ?');
            $del->execute([$purposePhone]);

            $ins = $this->conn->prepare(
                'INSERT INTO user_otps (email, phone, otp, expires_at) VALUES (?, ?, ?, ?)'
            );
            $ins->execute([$email, $purposePhone, $otp, $expiresAt]);

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

            // Best-effort after response; keep SMTP timeout low.
            @ini_set('default_socket_timeout', '8');
            sendEmail($email, 'BugRicer — verify your contact email', $html, $text);
        } catch (Exception $e) {
            error_log('send_contact_email_otp error: ' . $e->getMessage());
            if (!headers_sent()) {
                $this->sendJsonResponse(500, 'Failed to send email OTP');
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
}

$api = new SendContactEmailOtpAPI();
$api->handle();
