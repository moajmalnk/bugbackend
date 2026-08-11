<?php
/**
 * Why: Persist mandatory employee onboarding (address, statutory files, banking, WFH)
 * in one atomic multipart submit so the frontend can unlock the dashboard.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../PermissionManager.php';
require_once __DIR__ . '/../../utils/validation.php';
require_once __DIR__ . '/../../utils/user_avatar.php';
require_once __DIR__ . '/../../utils/onboarding_contact_unique.php';
require_once __DIR__ . '/../../utils/employee_id.php';

class SubmitOnboardingAPI extends BaseAPI
{
    private const MAX_FILE_BYTES = 5 * 1024 * 1024;
    private const ALLOWED_EXT = ['pdf', 'jpg', 'jpeg', 'png', 'heic'];
    private const ALLOWED_MIME = [
        'application/pdf',
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/heic',
        'image/heif',
    ];

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

            // Why: Admins may complete/edit employee onboarding before HR verify,
            // without employee OTP — target via for_user_id.
            $forUserId = trim((string) ($_POST['for_user_id'] ?? $_POST['user_id'] ?? ''));
            $isAdminProxy = false;
            $userId = $requesterId;
            if ($forUserId !== '' && !hash_equals($forUserId, $requesterId)) {
                $pm = PermissionManager::getInstance();
                $isAdmin = $pm->hasPermissionOrAdmin($requesterId, 'USERS_EDIT', $legacyRole)
                    || $pm->hasPermissionOrAdmin($requesterId, 'USERS_VIEW', $legacyRole)
                    || strtolower((string) $legacyRole) === 'admin';
                if (!$isAdmin) {
                    $this->sendJsonResponse(403, 'Only admins can submit onboarding for another user');
                    return;
                }
                $isAdminProxy = true;
                $userId = $forUserId;
            }

            $cols = $this->usersColumnSet();
            if (!in_array('onboarding_completed', $cols, true)) {
                $this->sendJsonResponse(500, 'Onboarding columns missing. Run migration 058.');
                return;
            }

            $statusStmt = $this->conn->prepare(
                'SELECT onboarding_completed FROM users WHERE id = ? LIMIT 1'
            );
            $statusStmt->execute([$userId]);
            $row = $statusStmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $this->sendJsonResponse(404, 'User not found');
                return;
            }

            /** Why: Profile "Edit" / admin proxy keep existing files when omitted. */
            $existingDetails = $this->fetchDetails($userId);
            $isUpdate = (int) ($row['onboarding_completed'] ?? 0) === 1
                || ($isAdminProxy && !empty($existingDetails));

            $termsAccepted = $this->truthy($_POST['terms_accepted'] ?? '');
            $privacyAccepted = $this->truthy($_POST['privacy_accepted'] ?? '');
            if (!$termsAccepted || !$privacyAccepted) {
                $this->sendJsonResponse(400, 'Terms of Service and Privacy Policy must be accepted');
                return;
            }

            $mustSetPassword = in_array('must_set_password', $cols, true);
            $needsPassword = false;
            // Password is only required on first-time self-onboarding for new hires.
            // Admin proxy never forces password — employee sets it later if needed.
            if (!$isUpdate && !$isAdminProxy && $mustSetPassword) {
                $flagStmt = $this->conn->prepare(
                    'SELECT must_set_password FROM users WHERE id = ? LIMIT 1'
                );
                $flagStmt->execute([$userId]);
                $flagRow = $flagStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $needsPassword = (int) ($flagRow['must_set_password'] ?? 0) === 1;
            }

            $newPassword = isset($_POST['password']) ? (string) $_POST['password'] : '';
            $confirmPassword = isset($_POST['confirm_password'])
                ? (string) $_POST['confirm_password']
                : '';
            $hashedNewPassword = null;
            if ($needsPassword) {
                if (strlen($newPassword) < 6) {
                    $this->sendJsonResponse(400, 'Password must be at least 6 characters');
                    return;
                }
                if ($newPassword !== $confirmPassword) {
                    $this->sendJsonResponse(400, 'Password and confirmation do not match');
                    return;
                }
                $hashedNewPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            }

            $fields = $this->sanitizePayload($_POST);

            // Why: Admin-attested contacts skip employee WhatsApp/email OTP.
            if ($isAdminProxy) {
                $nowIso = gmdate('c');
                if (trim((string) ($fields['emergency_contact_verified_at'] ?? '')) === '') {
                    $fields['emergency_contact_verified_at'] = $nowIso;
                }
                if (trim((string) ($fields['contact_email_verified_at'] ?? '')) === '') {
                    $fields['contact_email_verified_at'] = $nowIso;
                }
            }

            $detailColsEarly = [];
            $colResEarly = $this->conn->query('SHOW COLUMNS FROM user_onboarding_details');
            if ($colResEarly) {
                while ($c = $colResEarly->fetch(PDO::FETCH_ASSOC)) {
                    $detailColsEarly[] = $c['Field'];
                }
            }
            $missing = $this->validateRequired($fields, $detailColsEarly);
            if (!empty($missing)) {
                $this->sendJsonResponse(400, 'Missing required fields: ' . implode(', ', $missing));
                return;
            }

            $emailConflict = br_onboarding_contact_email_conflict(
                $this->conn,
                (string) $fields['contact_email'],
                $userId
            );
            if ($emailConflict !== null) {
                $this->sendJsonResponse(409, $emailConflict);
                return;
            }
            $phoneConflict = br_onboarding_emergency_phone_conflict(
                $this->conn,
                (string) $fields['emergency_contact'],
                $userId
            );
            if ($phoneConflict !== null) {
                $this->sendJsonResponse(409, $phoneConflict);
                return;
            }

            $uploadDir = __DIR__ . '/../../uploads/statutory/';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
                $this->sendJsonResponse(500, 'Failed to create statutory upload directory');
                return;
            }

            $aadhaarPath = $this->storeUpload('aadhaar_file', $userId, $uploadDir, !$isUpdate);
            if ($aadhaarPath === false) {
                return;
            }
            if ($aadhaarPath === null && $isUpdate) {
                $aadhaarPath = $existingDetails['aadhaar_file_path'] ?? null;
            }
            if ($aadhaarPath === null || $aadhaarPath === '') {
                $this->sendJsonResponse(400, 'aadhaar_file is required');
                return;
            }

            $panPath = $this->storeUpload('pan_file', $userId, $uploadDir, false);
            if ($panPath === false) {
                return;
            }
            if ($panPath === null && $isUpdate && !empty($existingDetails['pan_file_path'])) {
                $panPath = $existingDetails['pan_file_path'];
            }
            // Offer letter is no longer collected in onboarding; keep null / existing.
            $offerPath = null;

            $avatarPath = $this->storeProfilePhoto($userId, !$isUpdate);
            if ($avatarPath === false) {
                return;
            }

            $this->conn->beginTransaction();

            $existsStmt = $this->conn->prepare(
                'SELECT id, offer_letter_path FROM user_onboarding_details WHERE user_id = ? LIMIT 1'
            );
            $existsStmt->execute([$userId]);
            $existingRow = $existsStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $existingId = $existingRow['id'] ?? null;
            if ($offerPath === null && $existingRow && !empty($existingRow['offer_letter_path'])) {
                $offerPath = $existingRow['offer_letter_path'];
            }

            $detailCols = [];
            $colRes = $this->conn->query('SHOW COLUMNS FROM user_onboarding_details');
            if ($colRes) {
                while ($c = $colRes->fetch(PDO::FETCH_ASSOC)) {
                    $detailCols[] = $c['Field'];
                }
            }
            $hasContactEmail = in_array('contact_email', $detailCols, true);

            if ($existingId) {
                $sql = 'UPDATE user_onboarding_details SET
                    emergency_contact = ?,';
                $params = [$fields['emergency_contact']];
                if ($hasContactEmail) {
                    $sql .= ' contact_email = ?,';
                    $params[] = $fields['contact_email'];
                }
                $sql .= ' house_name_number = ?, landmark = ?, city = ?,
                    post_office = ?, pin_code = ?, district = ?, state = ?, country = ?,
                    wfh_latitude = ?, wfh_longitude = ?,
                    aadhaar_number = ?, aadhaar_file_path = ?, pan_number = ?, pan_file_path = ?,
                    offer_letter_path = ?,
                    account_holder_name = ?, bank_name = ?, account_number = ?, ifsc_code = ?,
                    branch_name = ?, account_type = ?, upi_id = ?, upi_linked_phone = ?
                    WHERE user_id = ?';
                $params = array_merge($params, [
                    $fields['house_name_number'], $fields['landmark'],
                    $fields['city'], $fields['post_office'], $fields['pin_code'], $fields['district'],
                    $fields['state'], $fields['country'], $fields['wfh_latitude'], $fields['wfh_longitude'],
                    $fields['aadhaar_number'], $aadhaarPath, $fields['pan_number'], $panPath,
                    $offerPath,
                    $fields['account_holder_name'], $fields['bank_name'], $fields['account_number'],
                    $fields['ifsc_code'], $fields['branch_name'], $fields['account_type'],
                    $fields['upi_id'], $fields['upi_linked_phone'], $userId,
                ]);
            } else {
                if ($hasContactEmail) {
                    $sql = 'INSERT INTO user_onboarding_details (
                        user_id, emergency_contact, contact_email, house_name_number, landmark, city, post_office,
                        pin_code, district, state, country, wfh_latitude, wfh_longitude,
                        aadhaar_number, aadhaar_file_path, pan_number, pan_file_path,
                        offer_letter_path,
                        account_holder_name, bank_name, account_number, ifsc_code, branch_name,
                        account_type, upi_id, upi_linked_phone
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                    )';
                    $params = [
                        $userId,
                        $fields['emergency_contact'], $fields['contact_email'], $fields['house_name_number'], $fields['landmark'],
                        $fields['city'], $fields['post_office'], $fields['pin_code'], $fields['district'],
                        $fields['state'], $fields['country'], $fields['wfh_latitude'], $fields['wfh_longitude'],
                        $fields['aadhaar_number'], $aadhaarPath, $fields['pan_number'], $panPath,
                        $offerPath,
                        $fields['account_holder_name'], $fields['bank_name'], $fields['account_number'],
                        $fields['ifsc_code'], $fields['branch_name'], $fields['account_type'],
                        $fields['upi_id'], $fields['upi_linked_phone'],
                    ];
                } else {
                    $sql = 'INSERT INTO user_onboarding_details (
                        user_id, emergency_contact, house_name_number, landmark, city, post_office,
                        pin_code, district, state, country, wfh_latitude, wfh_longitude,
                        aadhaar_number, aadhaar_file_path, pan_number, pan_file_path,
                        offer_letter_path,
                        account_holder_name, bank_name, account_number, ifsc_code, branch_name,
                        account_type, upi_id, upi_linked_phone
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                    )';
                    $params = [
                        $userId,
                        $fields['emergency_contact'], $fields['house_name_number'], $fields['landmark'],
                        $fields['city'], $fields['post_office'], $fields['pin_code'], $fields['district'],
                        $fields['state'], $fields['country'], $fields['wfh_latitude'], $fields['wfh_longitude'],
                        $fields['aadhaar_number'], $aadhaarPath, $fields['pan_number'], $panPath,
                        $offerPath,
                        $fields['account_holder_name'], $fields['bank_name'], $fields['account_number'],
                        $fields['ifsc_code'], $fields['branch_name'], $fields['account_type'],
                        $fields['upi_id'], $fields['upi_linked_phone'],
                    ];
                }
            }

            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);

            $this->stampDetailVerifiedAts($userId, $fields, $detailCols);
            $this->persistDemographics($userId, $fields, $detailCols);
            $this->persistSocialProfiles($userId, $fields, $detailCols);

            try {
                br_ensure_employee_code($this->conn, $userId);
            } catch (Throwable $e) {
                error_log('submit_onboarding employee_code: ' . $e->getMessage());
            }

            $termsAt = $this->parseClientTimestamp($_POST['terms_accepted_at'] ?? null)
                ?? date('Y-m-d H:i:s');
            $privacyAt = $this->parseClientTimestamp($_POST['privacy_accepted_at'] ?? null)
                ?? date('Y-m-d H:i:s');

            $updateSql = 'UPDATE users SET onboarding_completed = 1';
            $updateParams = [];
            if ($avatarPath) {
                $writeCols = br_user_avatar_write_cols($cols);
                if ($writeCols === []) {
                    $this->conn->rollBack();
                    $this->sendJsonResponse(
                        500,
                        'Profile photo column missing — run migration 064_users_avatar.sql'
                    );
                    return;
                }
                $updateSql = br_user_avatar_append_update($updateSql, $updateParams, $avatarPath, $cols);
            }
            if (in_array('terms_accepted_at', $cols, true)) {
                $updateSql .= ', terms_accepted_at = ?';
                $updateParams[] = $termsAt;
            }
            if (in_array('privacy_accepted_at', $cols, true)) {
                $updateSql .= ', privacy_accepted_at = ?';
                $updateParams[] = $privacyAt;
            }
            // Keep original completion time on edits; only stamp on first submit.
            if (!$isUpdate && in_array('onboarding_completed_at', $cols, true)) {
                $updateSql .= ', onboarding_completed_at = NOW()';
            }
            if (in_array('onboarding_verification_status', $cols, true)) {
                $updateSql .= ", onboarding_verification_status = 'pending'";
            }
            if (in_array('onboarding_verified_at', $cols, true)) {
                $updateSql .= ', onboarding_verified_at = NULL';
            }
            if (in_array('onboarding_verified_by', $cols, true)) {
                $updateSql .= ', onboarding_verified_by = NULL';
            }
            if (in_array('onboarding_rejection_reason', $cols, true)) {
                $updateSql .= ', onboarding_rejection_reason = NULL';
            }
            if (in_array('onboarding_rejection_note', $cols, true)) {
                $updateSql .= ', onboarding_rejection_note = NULL';
            }
            if (in_array('onboarding_rejection_action', $cols, true)) {
                $updateSql .= ', onboarding_rejection_action = NULL';
            }
            if ($hashedNewPassword !== null) {
                $updateSql .= ', password = ?';
                $updateParams[] = $hashedNewPassword;
                if (in_array('must_set_password', $cols, true)) {
                    $updateSql .= ', must_set_password = 0';
                }
            }
            $updateSql .= ' WHERE id = ?';
            $updateParams[] = $userId;
            $updateStmt = $this->conn->prepare($updateSql);
            $updateStmt->execute($updateParams);

            $this->conn->commit();

            $userSelect = ['id', 'username', 'email', 'phone', 'role', 'role_id', 'onboarding_completed'];
            $userSelect = br_user_avatar_select_cols($userSelect, $cols);
            if (in_array('terms_accepted_at', $cols, true)) {
                $userSelect[] = 'terms_accepted_at';
            }
            if (in_array('privacy_accepted_at', $cols, true)) {
                $userSelect[] = 'privacy_accepted_at';
            }
            if (in_array('onboarding_completed_at', $cols, true)) {
                $userSelect[] = 'onboarding_completed_at';
            }
            if (in_array('onboarding_verification_status', $cols, true)) {
                $userSelect[] = 'onboarding_verification_status';
            }
            if (in_array('onboarding_verified_at', $cols, true)) {
                $userSelect[] = 'onboarding_verified_at';
            }
            $userStmt = $this->conn->prepare(
                'SELECT ' . implode(', ', $userSelect) . ' FROM users WHERE id = ? LIMIT 1'
            );
            $userStmt->execute([$userId]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $user = br_user_with_resolved_avatar($user);
            if (empty($user['avatar']) && $avatarPath) {
                $user['avatar'] = $avatarPath;
            }

            $payload = [
                'onboarding_completed' => 1,
                'onboarding_verification_status' => $user['onboarding_verification_status'] ?? 'pending',
                'terms_accepted_at' => $user['terms_accepted_at'] ?? null,
                'privacy_accepted_at' => $user['privacy_accepted_at'] ?? null,
                'onboarding_completed_at' => $user['onboarding_completed_at'] ?? null,
                'avatar' => $user['avatar'] ?? $avatarPath,
                'user' => $user,
                // Why: Skip heavy details re-fetch on edit — Profile invalidates & reloads.
                'details' => $isUpdate ? null : $this->fetchDetails($userId),
                'updated' => $isUpdate,
            ];

            // Why: Flush JSON to the client before email/WhatsApp so Save feels instant.
            $this->sendJsonThen(
                function () use ($isUpdate, $userId, $user) {
                    try {
                        require_once __DIR__ . '/../../utils/onboarding_notifications.php';
                        if ($isUpdate) {
                            br_notify_admins_onboarding_updated(
                                $this->conn,
                                $userId,
                                (string) ($user['username'] ?? '')
                            );
                        } else {
                            br_notify_admins_onboarding_submitted(
                                $this->conn,
                                $userId,
                                (string) ($user['username'] ?? '')
                            );
                        }
                    } catch (Throwable $e) {
                        error_log('submit_onboarding notify: ' . $e->getMessage());
                    }
                },
                200,
                $isUpdate ? 'Onboarding details updated successfully' : 'Onboarding completed successfully',
                $payload
            );
            return;
        } catch (Exception $e) {
            if ($this->conn && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('submit_onboarding error: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to submit onboarding: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $fields
     * @param list<string> $detailCols
     */
    private function stampDetailVerifiedAts(string $userId, array $fields, array $detailCols): void
    {
        $sets = [];
        $params = [];

        if (in_array('emergency_contact_verified_at', $detailCols, true)) {
            $raw = $fields['emergency_contact_verified_at'] ?? null;
            $at = $this->parseClientTimestamp($raw);
            // Why: Client may send an older audit stamp from edit-mode hydrate; still persist verified.
            if ($at === null && is_string($raw) && trim($raw) !== '') {
                $at = date('Y-m-d H:i:s');
            }
            if ($at !== null) {
                $sets[] = 'emergency_contact_verified_at = ?';
                $params[] = $at;
            }
        }
        if (in_array('contact_email_verified_at', $detailCols, true)) {
            $raw = $fields['contact_email_verified_at'] ?? null;
            $at = $this->parseClientTimestamp($raw);
            if ($at === null && is_string($raw) && trim($raw) !== '') {
                $at = date('Y-m-d H:i:s');
            }
            if ($at !== null) {
                $sets[] = 'contact_email_verified_at = ?';
                $params[] = $at;
            }
        }

        if (empty($sets)) {
            return;
        }

        $params[] = $userId;
        $stmt = $this->conn->prepare(
            'UPDATE user_onboarding_details SET ' . implode(', ', $sets) . ' WHERE user_id = ?'
        );
        $stmt->execute($params);
    }

    /**
     * Why: Persist DOB/gender/marital after the main details upsert so older DBs
     * without migration 066 still accept onboarding without failing the INSERT shape.
     *
     * @param array<string, mixed> $fields
     * @param list<string> $detailCols
     */
    private function persistDemographics(string $userId, array $fields, array $detailCols): void
    {
        $sets = [];
        $params = [];
        if (in_array('date_of_birth', $detailCols, true)) {
            $sets[] = 'date_of_birth = ?';
            $params[] = $fields['date_of_birth'] ?? null;
        }
        if (in_array('gender', $detailCols, true)) {
            $sets[] = 'gender = ?';
            $params[] = $fields['gender'] ?? null;
        }
        if (in_array('marital_status', $detailCols, true)) {
            $sets[] = 'marital_status = ?';
            $params[] = $fields['marital_status'] ?? null;
        }
        if (empty($sets)) {
            return;
        }
        $params[] = $userId;
        $stmt = $this->conn->prepare(
            'UPDATE user_onboarding_details SET ' . implode(', ', $sets) . ' WHERE user_id = ?'
        );
        $stmt->execute($params);
    }

    /**
     * Why: Git/LinkedIn columns are additive (071) — persist only when present.
     *
     * @param array<string, mixed> $fields
     * @param list<string> $detailCols
     */
    private function persistSocialProfiles(string $userId, array $fields, array $detailCols): void
    {
        $sets = [];
        $params = [];
        if (in_array('github_url', $detailCols, true)) {
            $sets[] = 'github_url = ?';
            $params[] = $fields['github_url'] ?? null;
        }
        if (in_array('linkedin_url', $detailCols, true)) {
            $sets[] = 'linkedin_url = ?';
            $params[] = $fields['linkedin_url'] ?? null;
        }
        if (empty($sets)) {
            return;
        }
        $params[] = $userId;
        $stmt = $this->conn->prepare(
            'UPDATE user_onboarding_details SET ' . implode(', ', $sets) . ' WHERE user_id = ?'
        );
        $stmt->execute($params);
    }

    private function parseClientTimestamp($raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $value = trim((string) $raw);
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }
        $now = time();
        if ($ts < $now - 60 * 24 * 60 * 60 || $ts > $now + 24 * 60 * 60) {
            return null;
        }
        return date('Y-m-d H:i:s', $ts);
    }

    private function usersColumnSet(): array
    {
        $cols = [];
        $res = $this->conn->query('SHOW COLUMNS FROM users');
        if ($res) {
            while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
                $cols[] = $row['Field'];
            }
        }
        return $cols;
    }

    private function truthy($value): bool
    {
        $v = strtolower(trim((string) $value));
        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }

    private function clamp(string $value, int $max): string
    {
        $clean = strip_tags(trim($value));
        if (function_exists('mb_substr')) {
            return mb_substr($clean, 0, $max);
        }
        return substr($clean, 0, $max);
    }

    private function digitsOnly(string $value, int $max): string
    {
        return substr(preg_replace('/\D+/', '', $value) ?? '', 0, $max);
    }

    /**
     * @return array<string, mixed>
     */
    private function sanitizePayload(array $post): array
    {
        $lat = isset($post['wfh_latitude']) && $post['wfh_latitude'] !== ''
            ? (float) $post['wfh_latitude']
            : null;
        $lng = isset($post['wfh_longitude']) && $post['wfh_longitude'] !== ''
            ? (float) $post['wfh_longitude']
            : null;

        if ($lat !== null && ($lat < -90 || $lat > 90)) {
            $lat = null;
        }
        if ($lng !== null && ($lng < -180 || $lng > 180)) {
            $lng = null;
        }

        return [
            'emergency_contact' => $this->digitsOnly((string) ($post['emergency_contact'] ?? ''), 15),
            'contact_email' => strtolower($this->clamp((string) ($post['contact_email'] ?? ''), 150)),
            'emergency_contact_verified_at' => (string) ($post['emergency_contact_verified_at'] ?? ''),
            'contact_email_verified_at' => (string) ($post['contact_email_verified_at'] ?? ''),
            'house_name_number' => $this->clamp((string) ($post['house_name_number'] ?? ''), 150),
            'landmark' => $this->clamp((string) ($post['landmark'] ?? ''), 200),
            'city' => $this->clamp((string) ($post['city'] ?? ''), 100),
            'post_office' => $this->clamp((string) ($post['post_office'] ?? ''), 100),
            'pin_code' => $this->digitsOnly((string) ($post['pin_code'] ?? ''), 10),
            'district' => $this->clamp((string) ($post['district'] ?? ''), 100),
            'state' => $this->clamp((string) ($post['state'] ?? ''), 100),
            'country' => $this->clamp((string) ($post['country'] ?? ''), 100),
            'wfh_latitude' => $lat,
            'wfh_longitude' => $lng,
            'aadhaar_number' => $this->digitsOnly((string) ($post['aadhaar_number'] ?? ''), 12),
            'pan_number' => strtoupper($this->clamp((string) ($post['pan_number'] ?? ''), 10)),
            'account_holder_name' => $this->clamp((string) ($post['account_holder_name'] ?? ''), 150),
            'bank_name' => $this->clamp((string) ($post['bank_name'] ?? ''), 150),
            'account_number' => $this->digitsOnly((string) ($post['account_number'] ?? ''), 40),
            'ifsc_code' => strtoupper($this->clamp((string) ($post['ifsc_code'] ?? ''), 20)),
            'branch_name' => $this->clamp((string) ($post['branch_name'] ?? ''), 150),
            'account_type' => $this->clamp((string) ($post['account_type'] ?? ''), 40),
            'upi_id' => $this->clamp((string) ($post['upi_id'] ?? ''), 100),
            'upi_linked_phone' => $this->digitsOnly((string) ($post['upi_linked_phone'] ?? ''), 15),
            'date_of_birth' => $this->sanitizeDate($post['date_of_birth'] ?? null),
            'gender' => $this->sanitizeGender($post['gender'] ?? null),
            'marital_status' => $this->sanitizeMaritalStatus($post['marital_status'] ?? null),
            'github_url' => $this->sanitizeProfileUrl($post['github_url'] ?? null, 'github'),
            'linkedin_url' => $this->sanitizeProfileUrl($post['linkedin_url'] ?? null, 'linkedin'),
        ];
    }

    private function sanitizeGitUsername($raw): ?string
    {
        $v = trim((string) ($raw ?? ''));
        if ($v === '') {
            return null;
        }
        $v = preg_replace('/\s+/', '', $v) ?? $v;
        $v = ltrim($v, '@');
        $v = $this->clamp($v, 100);
        return $v !== '' ? $v : null;
    }

    private function sanitizeGitEmail($raw): ?string
    {
        $v = strtolower($this->clamp((string) ($raw ?? ''), 150));
        if ($v === '') {
            return null;
        }
        if (!filter_var($v, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        return $v;
    }

    /**
     * @param 'github'|'linkedin' $kind
     */
    private function sanitizeProfileUrl($raw, string $kind): ?string
    {
        $v = trim((string) ($raw ?? ''));
        if ($v === '') {
            return null;
        }
        if (!preg_match('#^https?://#i', $v)) {
            $v = 'https://' . $v;
        }
        $v = $this->clamp($v, 255);
        if (!filter_var($v, FILTER_VALIDATE_URL)) {
            return null;
        }
        $host = strtolower((string) (parse_url($v, PHP_URL_HOST) ?? ''));
        if ($kind === 'github') {
            if ($host !== 'github.com' && $host !== 'www.github.com') {
                return null;
            }
        } elseif ($kind === 'linkedin') {
            if (
                $host !== 'linkedin.com'
                && $host !== 'www.linkedin.com'
                && !str_ends_with($host, '.linkedin.com')
            ) {
                return null;
            }
        }
        return $v;
    }

    private function sanitizeDate($raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $value = trim((string) $raw);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $parts = explode('-', $value);
        if (!checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
            return null;
        }
        // Must be in the past and reasonable age range (14–100 years)
        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }
        $age = (int) floor((time() - $ts) / (365.25 * 24 * 60 * 60));
        if ($age < 14 || $age > 100) {
            return null;
        }
        return $value;
    }

    private function sanitizeGender($raw): ?string
    {
        $v = strtolower(trim((string) ($raw ?? '')));
        $allowed = ['male', 'female', 'other', 'prefer_not_to_say'];
        return in_array($v, $allowed, true) ? $v : null;
    }

    private function sanitizeMaritalStatus($raw): ?string
    {
        $v = strtolower(trim((string) ($raw ?? '')));
        $allowed = ['single', 'married', 'divorced', 'widowed', 'other'];
        return in_array($v, $allowed, true) ? $v : null;
    }

    /**
     * @param array<string, mixed> $fields
     * @param list<string> $detailCols
     * @return string[]
     */
    private function validateRequired(array $fields, array $detailCols = []): array
    {
        $required = [
            'emergency_contact',
            'contact_email',
            'house_name_number',
            'city',
            'pin_code',
            'district',
            'state',
            'country',
            'aadhaar_number',
            'account_holder_name',
            'bank_name',
            'account_number',
            'ifsc_code',
            'branch_name',
            'account_type',
        ];
        $missing = [];
        foreach ($required as $key) {
            if (!isset($fields[$key]) || trim((string) $fields[$key]) === '') {
                $missing[] = $key;
            }
        }
        if (!empty($fields['contact_email']) && !filter_var((string) $fields['contact_email'], FILTER_VALIDATE_EMAIL)) {
            $missing[] = 'contact_email(invalid)';
        }
        if (!empty($fields['aadhaar_number']) && strlen((string) $fields['aadhaar_number']) !== 12) {
            $missing[] = 'aadhaar_number(must be 12 digits)';
        }
        if (in_array('date_of_birth', $detailCols, true) && empty($fields['date_of_birth'])) {
            $missing[] = 'date_of_birth';
        }
        if (in_array('gender', $detailCols, true) && empty($fields['gender'])) {
            $missing[] = 'gender';
        }
        if (in_array('marital_status', $detailCols, true) && empty($fields['marital_status'])) {
            $missing[] = 'marital_status';
        }
        if (!empty($fields['github_url']) && empty($this->sanitizeProfileUrl($fields['github_url'], 'github'))) {
            $missing[] = 'github_url(invalid)';
        }
        if (!empty($fields['linkedin_url']) && empty($this->sanitizeProfileUrl($fields['linkedin_url'], 'linkedin'))) {
            $missing[] = 'linkedin_url(invalid)';
        }
        return $missing;
    }

    /**
     * @return string|null|false Relative path on success, null if optional missing, false on error (response already sent)
     */
    private function storeUpload(string $field, string $userId, string $uploadDir, bool $required)
    {
        if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) {
            if ($required) {
                $this->sendJsonResponse(400, "{$field} is required");
                return false;
            }
            return null;
        }

        $file = $_FILES[$field];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            if ($required) {
                $this->sendJsonResponse(400, "{$field} is required");
                return false;
            }
            return null;
        }

        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            $this->sendJsonResponse(400, "Upload failed for {$field}");
            return false;
        }

        $original = (string) ($file['name'] ?? '');
        if (!validateFileExtension($original, self::ALLOWED_EXT)) {
            $this->sendJsonResponse(400, "{$field}: invalid file type. Allowed: PDF, JPG, PNG, HEIC");
            return false;
        }

        if (!validateFileSize((int) ($file['size'] ?? 0), self::MAX_FILE_BYTES)) {
            $this->sendJsonResponse(400, "{$field}: file too large (max 5MB)");
            return false;
        }

        $mime = (string) ($file['type'] ?? '');
        if ($mime !== '' && !in_array($mime, self::ALLOWED_MIME, true)) {
            // Some browsers omit HEIC MIME; extension check already passed.
            if (!in_array(strtolower(pathinfo($original, PATHINFO_EXTENSION)), ['heic', 'heif'], true)) {
                $this->sendJsonResponse(400, "{$field}: unsupported MIME type");
                return false;
            }
        }

        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $safeUser = preg_replace('/[^a-zA-Z0-9_-]/', '', $userId) ?: 'user';
        $filename = $safeUser . '_' . uniqid($field . '_', true) . '.' . $ext;
        $dest = rtrim($uploadDir, '/') . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            $this->sendJsonResponse(500, "Failed to store {$field}");
            return false;
        }

        return 'uploads/statutory/' . $filename;
    }

    /**
     * Why: Profile photo is required for first-time onboarding and is dual-written
     * to users.avatar + users.profile_picture when present. On edit it is optional.
     *
     * @return string|null|false Relative web path on success, null if optional missing, false on error
     */
    private function storeProfilePhoto(string $userId, bool $required = true)
    {
        $field = 'profile_photo';
        if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) {
            if ($required) {
                $this->sendJsonResponse(400, 'profile_photo is required');
                return false;
            }
            return null;
        }

        $file = $_FILES[$field];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            if ($required) {
                $this->sendJsonResponse(400, 'profile_photo is required');
                return false;
            }
            return null;
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            $this->sendJsonResponse(400, 'Upload failed for profile_photo');
            return false;
        }

        $original = (string) ($file['name'] ?? '');
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
        if (!in_array($ext, $allowedExt, true)) {
            $this->sendJsonResponse(400, 'profile_photo: use JPG, PNG, or WebP');
            return false;
        }

        if (!validateFileSize((int) ($file['size'] ?? 0), self::MAX_FILE_BYTES)) {
            $this->sendJsonResponse(400, 'profile_photo: file too large (max 5MB)');
            return false;
        }

        $mime = (string) ($file['type'] ?? '');
        $allowedMime = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
        if ($mime !== '' && !in_array($mime, $allowedMime, true)) {
            $this->sendJsonResponse(400, 'profile_photo: unsupported MIME type');
            return false;
        }

        $uploadDir = __DIR__ . '/../../uploads/profile_pictures/';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
            $this->sendJsonResponse(500, 'Failed to create profile picture directory');
            return false;
        }

        $safeUser = preg_replace('/[^a-zA-Z0-9_-]/', '', $userId) ?: 'user';
        $filename = $safeUser . '_' . time() . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
        $dest = rtrim($uploadDir, '/') . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            $this->sendJsonResponse(500, 'Failed to store profile_photo');
            return false;
        }

        // Why: Relative path works on Hostinger + local; frontend resolveAvatarUrl
        // maps this to the backend origin (legacy /BugRicer/backend/... 404s in prod).
        return 'uploads/profile_pictures/' . $filename;
    }

    private function fetchDetails(string $userId): ?array
    {
        $stmt = $this->conn->prepare(
            'SELECT * FROM user_onboarding_details WHERE user_id = ? LIMIT 1'
        );
        $stmt->execute([$userId]);
        $details = $stmt->fetch(PDO::FETCH_ASSOC);
        return $details ?: null;
    }
}

$api = new SubmitOnboardingAPI();
$api->handle();
