<?php
/**
 * Why: Persist mandatory employee onboarding (address, statutory files, banking, WFH)
 * in one atomic multipart submit so the frontend can unlock the dashboard.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../utils/validation.php';

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
            $userId = (string) ($decoded->user_id ?? '');
            if ($userId === '') {
                $this->sendJsonResponse(401, 'Invalid token');
                return;
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

            if ((int) $row['onboarding_completed'] === 1) {
                $details = $this->fetchDetails($userId);
                $this->sendJsonResponse(200, 'Onboarding already completed', [
                    'onboarding_completed' => 1,
                    'terms_accepted_at' => null,
                    'privacy_accepted_at' => null,
                    'details' => $details,
                ]);
                return;
            }

            $termsAccepted = $this->truthy($_POST['terms_accepted'] ?? '');
            $privacyAccepted = $this->truthy($_POST['privacy_accepted'] ?? '');
            if (!$termsAccepted || !$privacyAccepted) {
                $this->sendJsonResponse(400, 'Terms of Service and Privacy Policy must be accepted');
                return;
            }

            $mustSetPassword = in_array('must_set_password', $cols, true);
            $needsPassword = false;
            if ($mustSetPassword) {
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
            $missing = $this->validateRequired($fields);
            if (!empty($missing)) {
                $this->sendJsonResponse(400, 'Missing required fields: ' . implode(', ', $missing));
                return;
            }

            $uploadDir = __DIR__ . '/../../uploads/statutory/';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
                $this->sendJsonResponse(500, 'Failed to create statutory upload directory');
                return;
            }

            $aadhaarPath = $this->storeUpload('aadhaar_file', $userId, $uploadDir, true);
            if ($aadhaarPath === false) {
                return;
            }
            $panPath = $this->storeUpload('pan_file', $userId, $uploadDir, false);
            if ($panPath === false) {
                return;
            }
            // Offer letter is no longer collected in onboarding; keep null / existing.
            $offerPath = null;

            $avatarPath = $this->storeProfilePhoto($userId);
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

            $termsAt = $this->parseClientTimestamp($_POST['terms_accepted_at'] ?? null)
                ?? date('Y-m-d H:i:s');
            $privacyAt = $this->parseClientTimestamp($_POST['privacy_accepted_at'] ?? null)
                ?? date('Y-m-d H:i:s');

            $updateSql = 'UPDATE users SET onboarding_completed = 1';
            $updateParams = [];
            if ($avatarPath && in_array('avatar', $cols, true)) {
                $updateSql .= ', avatar = ?';
                $updateParams[] = $avatarPath;
            }
            if (in_array('terms_accepted_at', $cols, true)) {
                $updateSql .= ', terms_accepted_at = ?';
                $updateParams[] = $termsAt;
            }
            if (in_array('privacy_accepted_at', $cols, true)) {
                $updateSql .= ', privacy_accepted_at = ?';
                $updateParams[] = $privacyAt;
            }
            if (in_array('onboarding_completed_at', $cols, true)) {
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
            if (in_array('avatar', $cols, true)) {
                $userSelect[] = 'avatar';
            }
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

            $this->sendJsonResponse(200, 'Onboarding completed successfully', [
                'onboarding_completed' => 1,
                'onboarding_verification_status' => $user['onboarding_verification_status'] ?? 'pending',
                'terms_accepted_at' => $user['terms_accepted_at'] ?? null,
                'privacy_accepted_at' => $user['privacy_accepted_at'] ?? null,
                'onboarding_completed_at' => $user['onboarding_completed_at'] ?? null,
                'avatar' => $user['avatar'] ?? $avatarPath,
                'user' => $user,
                'details' => $this->fetchDetails($userId),
            ]);
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
            $at = $this->parseClientTimestamp($fields['emergency_contact_verified_at'] ?? null);
            if ($at !== null) {
                $sets[] = 'emergency_contact_verified_at = ?';
                $params[] = $at;
            }
        }
        if (in_array('contact_email_verified_at', $detailCols, true)) {
            $at = $this->parseClientTimestamp($fields['contact_email_verified_at'] ?? null);
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
        ];
    }

    /**
     * @param array<string, mixed> $fields
     * @return string[]
     */
    private function validateRequired(array $fields): array
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
     * Why: Profile photo is required for onboarding and lives on users.avatar
     * (same path convention as messaging update_profile_picture).
     *
     * @return string|false Relative web path on success, false on error (response already sent)
     */
    private function storeProfilePhoto(string $userId)
    {
        $field = 'profile_photo';
        if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) {
            $this->sendJsonResponse(400, 'profile_photo is required');
            return false;
        }

        $file = $_FILES[$field];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $this->sendJsonResponse(400, 'profile_photo is required');
            return false;
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

        return '/BugRicer/backend/uploads/profile_pictures/' . $filename;
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
