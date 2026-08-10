<?php
/**
 * Why: Confirm email OTP for onboarding contact email without issuing a login token.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../BaseAPI.php';

class VerifyContactEmailOtpAPI extends BaseAPI
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
            $otp = preg_replace('/\D/', '', (string) ($data['otp'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->sendJsonResponse(400, 'Enter a valid email address');
                return;
            }
            if (strlen($otp) !== 6) {
                $this->sendJsonResponse(400, 'Enter the 6-digit OTP');
                return;
            }

            $purposePhone = 'onboarding_mail:' . $userId;

            $stmt = $this->conn->prepare(
                'SELECT id FROM user_otps
                 WHERE phone = ? AND email = ? AND otp = ? AND expires_at > NOW()
                 ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([$purposePhone, $email, $otp]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $this->sendJsonResponse(401, 'Invalid or expired OTP');
                return;
            }

            $del = $this->conn->prepare('DELETE FROM user_otps WHERE phone = ?');
            $del->execute([$purposePhone]);

            $this->sendJsonResponse(200, 'Contact email verified', [
                'email' => $email,
                'verified' => true,
            ]);
        } catch (Exception $e) {
            error_log('verify_contact_email_otp error: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to verify email OTP');
        }
    }
}

$api = new VerifyContactEmailOtpAPI();
$api->handle();
