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

            $purposePhone = $this->purposeKey($userId);

            // Match new short purpose key, or legacy truncated "onboarding_mail:…" rows by email+otp.
            $stmt = $this->conn->prepare(
                'SELECT id, phone FROM user_otps
                 WHERE email = ? AND otp = ? AND expires_at > NOW()
                   AND (phone = ? OR phone LIKE ? OR phone LIKE ?)
                 ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([
                $email,
                $otp,
                $purposePhone,
                'onboarding_mail:%',
                'om_%',
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $this->sendJsonResponse(401, 'Invalid or expired OTP');
                return;
            }

            // Clear this user's mail OTPs (and any legacy rows for this email) so SMTP is skipped.
            $del = $this->conn->prepare(
                'DELETE FROM user_otps
                 WHERE phone = ?
                    OR (email = ? AND (phone LIKE ? OR phone LIKE ?))'
            );
            $del->execute([
                $purposePhone,
                $email,
                'om_%',
                'onboarding_mail:%',
            ]);

            $verifiedAt = date('c');
            $this->stampContactEmailVerified($userId, $email);

            $this->sendJsonResponse(200, 'Contact email verified', [
                'email' => $email,
                'verified' => true,
                'verified_at' => $verifiedAt,
            ]);
        } catch (Exception $e) {
            error_log('verify_contact_email_otp error: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to verify email OTP');
        }
    }

    private function purposeKey(string $userId): string
    {
        return 'om_' . substr(hash('sha256', $userId), 0, 16);
    }

    private function stampContactEmailVerified(string $userId, string $email): void
    {
        try {
            $cols = [];
            $res = $this->conn->query('SHOW COLUMNS FROM user_onboarding_details');
            if ($res) {
                while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
                    $cols[] = $row['Field'];
                }
            }
            if (
                !in_array('contact_email', $cols, true) ||
                !in_array('contact_email_verified_at', $cols, true)
            ) {
                return;
            }
            $stmt = $this->conn->prepare(
                'UPDATE user_onboarding_details
                 SET contact_email = ?, contact_email_verified_at = NOW()
                 WHERE user_id = ?'
            );
            $stmt->execute([$email, $userId]);
            // Why: First-time onboarding verifies OTP before the details row exists.
            if ($stmt->rowCount() === 0) {
                $ins = $this->conn->prepare(
                    'INSERT INTO user_onboarding_details (user_id, contact_email, contact_email_verified_at)
                     VALUES (?, ?, NOW())'
                );
                try {
                    $ins->execute([$userId, $email]);
                } catch (Exception $ignored) {
                    // Row may have been created concurrently; ignore.
                }
            }
        } catch (Exception $e) {
            error_log('stampContactEmailVerified: ' . $e->getMessage());
        }
    }
}

$api = new VerifyContactEmailOtpAPI();
$api->handle();
