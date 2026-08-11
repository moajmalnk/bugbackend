<?php
/**
 * Why: Admins mark submitted onboarding documents as verified (or rejected)
 * so employees leave the "Verification pending" state.
 * Reject requires a structured reason so the employee gets clear next steps
 * via email, WhatsApp, and push.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../PermissionManager.php';
require_once __DIR__ . '/../../utils/onboarding_rejection_reasons.php';

class VerifyOnboardingAPI extends BaseAPI
{
    public function handle(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(405, 'Method not allowed');
            return;
        }

        try {
            $decoded = $this->validateToken();
            $adminId = (string) ($decoded->user_id ?? '');
            $legacyRole = isset($decoded->role) ? (string) $decoded->role : null;

            if ($adminId === '') {
                $this->sendJsonResponse(401, 'Invalid token');
                return;
            }

            $pm = PermissionManager::getInstance();
            if (!$pm->hasPermissionOrAdmin($adminId, 'USERS_VIEW', $legacyRole)) {
                $this->sendJsonResponse(403, 'Only admins can verify onboarding');
                return;
            }

            $data = json_decode(file_get_contents('php://input'), true);
            if (!is_array($data)) {
                $data = $_POST;
            }

            $targetId = trim((string) ($data['user_id'] ?? ''));
            $action = strtolower(trim((string) ($data['action'] ?? 'verify')));
            if ($targetId === '') {
                $this->sendJsonResponse(400, 'user_id is required');
                return;
            }
            if (!in_array($action, ['verify', 'reject'], true)) {
                $this->sendJsonResponse(400, 'action must be verify or reject');
                return;
            }

            // Prefer multi-select array; keep string / comma-separated for older clients.
            $reasonCodes = $data['rejection_reasons'] ?? $data['rejection_reason'] ?? $data['reason'] ?? [];
            $reasonNote = trim((string) ($data['rejection_note'] ?? $data['note'] ?? ''));
            if (mb_strlen($reasonNote) > 500) {
                $reasonNote = mb_substr($reasonNote, 0, 500);
            }

            $resolvedReason = null;
            if ($action === 'reject') {
                $resolvedReason = br_resolve_onboarding_rejection_reason($reasonCodes, $reasonNote);
                if ($resolvedReason === null) {
                    $this->sendJsonResponse(
                        400,
                        'Select at least one rejection reason. For “Other”, add a short note for the employee.'
                    );
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
            if (!in_array('onboarding_verification_status', $cols, true)) {
                $this->sendJsonResponse(500, 'Verification columns missing. Run migration 060.');
                return;
            }

            $check = $this->conn->prepare(
                'SELECT id, onboarding_completed, onboarding_verification_status FROM users WHERE id = ? LIMIT 1'
            );
            $check->execute([$targetId]);
            $user = $check->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                $this->sendJsonResponse(404, 'User not found');
                return;
            }
            if ((int) ($user['onboarding_completed'] ?? 0) !== 1) {
                $this->sendJsonResponse(400, 'User has not completed onboarding yet');
                return;
            }

            $status = $action === 'verify' ? 'verified' : 'rejected';
            $sql = "UPDATE users SET onboarding_verification_status = ?";
            $params = [$status];
            if (in_array('onboarding_verified_at', $cols, true)) {
                $sql .= ', onboarding_verified_at = NOW()';
            }
            if (in_array('onboarding_verified_by', $cols, true)) {
                $sql .= ', onboarding_verified_by = ?';
                $params[] = $adminId;
            }

            if ($action === 'reject') {
                if (in_array('onboarding_rejection_reason', $cols, true)) {
                    $sql .= ', onboarding_rejection_reason = ?';
                    $params[] = $resolvedReason['code'];
                }
                if (in_array('onboarding_rejection_note', $cols, true)) {
                    $sql .= ', onboarding_rejection_note = ?';
                    $params[] = $reasonNote !== '' ? $reasonNote : null;
                }
                if (in_array('onboarding_rejection_action', $cols, true)) {
                    $sql .= ', onboarding_rejection_action = ?';
                    $params[] = $resolvedReason['action'];
                }
            } else {
                if (in_array('onboarding_rejection_reason', $cols, true)) {
                    $sql .= ', onboarding_rejection_reason = NULL';
                }
                if (in_array('onboarding_rejection_note', $cols, true)) {
                    $sql .= ', onboarding_rejection_note = NULL';
                }
                if (in_array('onboarding_rejection_action', $cols, true)) {
                    $sql .= ', onboarding_rejection_action = NULL';
                }
            }

            $sql .= ' WHERE id = ?';
            $params[] = $targetId;

            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);

            $adminName = null;
            try {
                $an = $this->conn->prepare('SELECT username FROM users WHERE id = ? LIMIT 1');
                $an->execute([$adminId]);
                $adminName = trim((string) ($an->fetchColumn() ?: '')) ?: null;
            } catch (Throwable $e) {
                $adminName = null;
            }

            $payload = [
                'user_id' => $targetId,
                'onboarding_verification_status' => $status,
                'onboarding_verified_by' => $adminId,
            ];
            if ($resolvedReason) {
                $payload['onboarding_rejection_reason'] = $resolvedReason['code'];
                $payload['onboarding_rejection_label'] = $resolvedReason['label'];
                $payload['onboarding_rejection_action'] = $resolvedReason['action'];
                if ($reasonNote !== '') {
                    $payload['onboarding_rejection_note'] = $reasonNote;
                }
            }

            $okMessage = $action === 'verify'
                ? 'Onboarding verified successfully'
                : 'Onboarding marked as rejected';

            // Why: Flush first — employee email/WhatsApp/push must not block Confirm verify.
            $this->sendJsonThen(
                function () use ($targetId, $status, $adminName, $resolvedReason) {
                    try {
                        require_once __DIR__ . '/../../utils/onboarding_notifications.php';
                        br_notify_employee_onboarding_decision(
                            $this->conn,
                            $targetId,
                            $status,
                            $adminName,
                            $resolvedReason
                        );
                    } catch (Throwable $e) {
                        error_log('verify_onboarding notify: ' . $e->getMessage());
                    }
                },
                200,
                $okMessage,
                $payload
            );
            return;
        } catch (Exception $e) {
            error_log('verify_onboarding error: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to update verification status');
        }
    }
}

$api = new VerifyOnboardingAPI();
$api->handle();
