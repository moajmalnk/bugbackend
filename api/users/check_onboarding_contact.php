<?php
/**
 * Why: Onboarding must reject an email / WhatsApp number that belongs to another
 * account *before* an OTP is sent, so the wizard checks availability as the
 * employee types. Same rules as the send/submit endpoints (which still re-check).
 *
 * POST { type: "email" | "phone", value: string, for_user_id?: string }
 * 200 { available: bool, message: string|null }
 */
header('Content-Type: application/json');
header('Cache-Control: private, no-store');

require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../PermissionManager.php';
require_once __DIR__ . '/../../utils/onboarding_contact_unique.php';

class CheckOnboardingContactAPI extends BaseAPI
{
    public function handle(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

            $data = json_decode(file_get_contents('php://input'), true);
            if (!is_array($data)) {
                $data = $_POST;
            }

            $type = (string) ($data['type'] ?? '');
            $value = trim((string) ($data['value'] ?? ''));
            if (!in_array($type, ['email', 'phone'], true) || $value === '' || strlen($value) > 150) {
                $this->sendJsonResponse(422, 'Provide type (email or phone) and a value');
                return;
            }

            // Admin filling onboarding for an employee checks against that employee.
            $userId = $requesterId;
            $forUserId = trim((string) ($data['for_user_id'] ?? ''));
            if ($forUserId !== '' && !hash_equals($forUserId, $requesterId)) {
                $pm = PermissionManager::getInstance();
                $isAdmin = $pm->hasPermissionOrAdmin($requesterId, 'USERS_EDIT', $legacyRole)
                    || $pm->hasPermissionOrAdmin($requesterId, 'USERS_VIEW', $legacyRole)
                    || strtolower((string) $legacyRole) === 'admin';
                if (!$isAdmin) {
                    $this->sendJsonResponse(403, 'Forbidden');
                    return;
                }
                $userId = $forUserId;
            }

            $conflict = $type === 'email'
                ? br_onboarding_contact_email_conflict($this->conn, strtolower($value), $userId)
                : br_onboarding_emergency_phone_conflict($this->conn, $value, $userId);

            $this->sendJsonResponse(200, $conflict === null ? 'Available' : 'Unavailable', [
                'available' => $conflict === null,
                'message' => $conflict,
            ]);
        } catch (Throwable $e) {
            error_log(json_encode([
                'event' => 'onboarding.contact_check.failed',
                'error' => $e->getMessage(),
            ]));
            $this->sendJsonResponse(500, 'Could not check availability');
        }
    }
}

$api = new CheckOnboardingContactAPI();
$api->handle();
