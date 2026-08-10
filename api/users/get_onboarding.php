<?php
/**
 * Why: Let the employee (or an admin) read onboarding PII/files metadata for Profile.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../PermissionManager.php';
require_once __DIR__ . '/../../utils/user_avatar.php';

class GetOnboardingAPI extends BaseAPI
{
    public function handle(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->sendJsonResponse(405, 'Method not allowed');
            return;
        }

        try {
            $decoded = $this->validateToken();
            $requesterId = (string) ($decoded->user_id ?? '');
            $legacyRole = isset($decoded->role) ? (string) $decoded->role : null;

            if ($requesterId === '') {
                $this->sendJsonResponse(401, 'Invalid token');
                return;
            }

            $targetId = isset($_GET['user_id']) ? trim((string) $_GET['user_id']) : $requesterId;
            if ($targetId === '') {
                $targetId = $requesterId;
            }

            $isSelf = hash_equals($requesterId, $targetId);
            $isAdmin = false;
            if (!$isSelf) {
                $pm = PermissionManager::getInstance();
                $isAdmin = $pm->hasPermissionOrAdmin($requesterId, 'USERS_VIEW', $legacyRole);
                if (!$isAdmin) {
                    $this->sendJsonResponse(403, 'Access denied');
                    return;
                }
            }

            $cols = [];
            $colRes = $this->conn->query('SHOW COLUMNS FROM users');
            if ($colRes) {
                while ($row = $colRes->fetch(PDO::FETCH_ASSOC)) {
                    $cols[] = $row['Field'];
                }
            }

            $select = ['id', 'username', 'email', 'role', 'role_id'];
            if (in_array('phone', $cols, true)) {
                $select[] = 'phone';
            }
            $select = br_user_avatar_select_cols($select, $cols);
            if (in_array('joining_date', $cols, true)) {
                $select[] = 'joining_date';
            }
            if (in_array('onboarding_completed', $cols, true)) {
                $select[] = 'onboarding_completed';
            }
            if (in_array('must_set_password', $cols, true)) {
                $select[] = 'must_set_password';
            }
            if (in_array('terms_accepted_at', $cols, true)) {
                $select[] = 'terms_accepted_at';
            }
            if (in_array('privacy_accepted_at', $cols, true)) {
                $select[] = 'privacy_accepted_at';
            }
            if (in_array('onboarding_completed_at', $cols, true)) {
                $select[] = 'onboarding_completed_at';
            }
            if (in_array('onboarding_verification_status', $cols, true)) {
                $select[] = 'onboarding_verification_status';
            }
            if (in_array('onboarding_verified_at', $cols, true)) {
                $select[] = 'onboarding_verified_at';
            }
            if (in_array('onboarding_verified_by', $cols, true)) {
                $select[] = 'onboarding_verified_by';
            }

            $userStmt = $this->conn->prepare(
                'SELECT ' . implode(', ', $select) . ' FROM users WHERE id = ? LIMIT 1'
            );
            $userStmt->execute([$targetId]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                $this->sendJsonResponse(404, 'User not found');
                return;
            }
            $user = br_user_with_resolved_avatar($user);

            $detailsStmt = $this->conn->prepare(
                'SELECT * FROM user_onboarding_details WHERE user_id = ? LIMIT 1'
            );
            $detailsStmt->execute([$targetId]);
            $details = $detailsStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            if ($details) {
                $details['has_aadhaar_file'] = !empty($details['aadhaar_file_path']);
                $details['has_pan_file'] = !empty($details['pan_file_path']);
                $details['has_offer_letter'] = !empty($details['offer_letter_path']);
                $details['has_nda'] = !empty($details['nda_path']);
            }

            $this->sendJsonResponse(200, 'Onboarding details retrieved', [
                'user' => $user,
                'details' => $details,
                'onboarding_completed' => isset($user['onboarding_completed'])
                    ? (int) $user['onboarding_completed']
                    : 0,
                'must_set_password' => isset($user['must_set_password'])
                    ? (int) $user['must_set_password']
                    : 0,
                'onboarding_verification_status' => $user['onboarding_verification_status'] ?? 'none',
                'onboarding_verified_at' => $user['onboarding_verified_at'] ?? null,
                'onboarding_completed_at' => $user['onboarding_completed_at'] ?? null,
                'terms_accepted_at' => $user['terms_accepted_at'] ?? null,
                'privacy_accepted_at' => $user['privacy_accepted_at'] ?? null,
            ]);
        } catch (Exception $e) {
            error_log('get_onboarding error: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to load onboarding details');
        }
    }
}

$api = new GetOnboardingAPI();
$api->handle();
