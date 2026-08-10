<?php
/**
 * Why: Confirm WhatsApp OTP for onboarding emergency contact without issuing a login token.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../config/utils.php';

class VerifyEmergencyOtpAPI extends BaseAPI
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
            $otp = preg_replace('/\D/', '', (string) ($data['otp'] ?? ''));
            $digits = preg_replace('/\D/', '', $rawPhone);
            if (strlen($digits) === 12 && strpos($digits, '91') === 0) {
                $digits = substr($digits, 2);
            }
            if (strlen($digits) !== 10) {
                $this->sendJsonResponse(400, 'Enter a valid 10-digit mobile number');
                return;
            }
            if (strlen($otp) !== 6) {
                $this->sendJsonResponse(400, 'Enter the 6-digit OTP');
                return;
            }

            $phone = Utils::normalizePhone($digits);
            $purposeEmail = 'onboarding_emg:' . $userId;

            $stmt = $this->conn->prepare(
                'SELECT id FROM user_otps
                 WHERE email = ? AND phone = ? AND otp = ? AND expires_at > NOW()
                 ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([$purposeEmail, $phone, $otp]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $this->sendJsonResponse(401, 'Invalid or expired OTP');
                return;
            }

            $del = $this->conn->prepare('DELETE FROM user_otps WHERE email = ?');
            $del->execute([$purposeEmail]);

            $verifiedAt = date('c');
            $this->stampEmergencyVerified($userId, $phone);

            $this->sendJsonResponse(200, 'Emergency contact verified', [
                'phone' => $phone,
                'verified' => true,
                'verified_at' => $verifiedAt,
            ]);
        } catch (Exception $e) {
            error_log('verify_emergency_otp error: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to verify OTP');
        }
    }

    private function stampEmergencyVerified(string $userId, string $phone): void
    {
        try {
            $cols = [];
            $res = $this->conn->query('SHOW COLUMNS FROM user_onboarding_details');
            if ($res) {
                while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
                    $cols[] = $row['Field'];
                }
            }
            if (!in_array('emergency_contact_verified_at', $cols, true)) {
                return;
            }
            $digits = preg_replace('/\D/', '', $phone) ?? '';
            if (strlen($digits) === 12 && strpos($digits, '91') === 0) {
                $digits = substr($digits, 2);
            }
            $stmt = $this->conn->prepare(
                'UPDATE user_onboarding_details
                 SET emergency_contact = ?, emergency_contact_verified_at = NOW()
                 WHERE user_id = ?'
            );
            $stmt->execute([substr($digits, -15), $userId]);
        } catch (Exception $e) {
            error_log('stampEmergencyVerified: ' . $e->getMessage());
        }
    }
}

$api = new VerifyEmergencyOtpAPI();
$api->handle();
