<?php
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../config/utils.php';

/**
 * Why: Admin hiring pipeline — store applicants, CVs, and stage progression
 * without coupling to employee users until hire.
 */
class RecruitmentController extends BaseAPI
{
    private const STATUSES = [
        'applied',
        'hr_screening',
        'staff_interview',
        'final_round',
        'offered',
        'rejected',
    ];

    private const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;

    private const ALLOWED_EXT = [
        'pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'heic', 'webp',
    ];

    private function requireAuth()
    {
        try {
            $decoded = $this->validateToken();
            if (!$decoded || !isset($decoded->user_id)) {
                $this->sendJsonResponse(401, 'Authentication failed');
                return null;
            }
            return $decoded;
        } catch (Throwable $e) {
            $this->sendJsonResponse(401, $e->getMessage() ?: 'Authentication failed');
            return null;
        }
    }

    private function requireView($decoded): bool
    {
        $pm = PermissionManager::getInstance();
        if (!$pm->hasPermissionOrAdmin(
            $decoded->user_id ?? '',
            'RECRUITMENT_VIEW',
            $decoded->role ?? null
        )) {
            $this->sendJsonResponse(403, 'Access denied');
            return false;
        }
        return true;
    }

    private function requireManage($decoded): bool
    {
        $pm = PermissionManager::getInstance();
        if (!$pm->hasPermissionOrAdmin(
            $decoded->user_id ?? '',
            'RECRUITMENT_MANAGE',
            $decoded->role ?? null
        )) {
            $this->sendJsonResponse(403, 'Access denied');
            return false;
        }
        return true;
    }

    private function tablesReady(): bool
    {
        try {
            $this->conn->query('SELECT 1 FROM recruitment_applicants LIMIT 1');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function ensureReady(): bool
    {
        if (!$this->tablesReady()) {
            $this->sendJsonResponse(
                503,
                'BugRecruitment is not set up. Run migration 086_bug_recruitment.sql.'
            );
            return false;
        }
        return true;
    }

    private function sanitizeText(?string $value, int $maxLen): ?string
    {
        if ($value === null) {
            return null;
        }
        $clean = trim(strip_tags($value));
        if ($clean === '') {
            return null;
        }
        if (mb_strlen($clean) > $maxLen) {
            $clean = mb_substr($clean, 0, $maxLen);
        }
        return $clean;
    }

    private function sanitizePhone(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if ($digits === '') {
            return null;
        }
        return substr($digits, 0, 15);
    }

    private function sanitizeUrl(?string $value): ?string
    {
        $clean = $this->sanitizeText($value, 2000);
        if ($clean === null) {
            return null;
        }
        if (!preg_match('#^https?://#i', $clean)) {
            return null;
        }
        return $clean;
    }

    private function sanitizeCtc($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        $n = (float)$value;
        if ($n < 0) {
            return null;
        }
        return round($n, 2);
    }

    private function formatAttachment(array $row): array
    {
        return [
            'id' => $row['id'],
            'applicant_id' => $row['applicant_id'],
            'kind' => $row['kind'],
            'file_path' => $row['file_path'],
            'file_name' => $row['file_name'],
            'file_type' => $row['file_type'] ?? null,
            'file_size' => isset($row['file_size']) ? (int)$row['file_size'] : null,
            'created_at' => $row['created_at'] ?? null,
        ];
    }

    private function loadAttachments(string $applicantId): array
    {
        $stmt = $this->conn->prepare(
            'SELECT * FROM recruitment_attachments WHERE applicant_id = ? ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute([$applicantId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map([$this, 'formatAttachment'], $rows);
    }

    private function formatApplicant(array $row, ?array $attachments = null): array
    {
        $hasResumeFile = false;
        if ($attachments === null) {
            $hasResumeFile = !empty($row['has_resume_file']);
        } else {
            foreach ($attachments as $att) {
                if (($att['kind'] ?? '') === 'resume') {
                    $hasResumeFile = true;
                    break;
                }
            }
        }

        $drive = $row['resume_drive_link'] ?? null;
        return [
            'id' => $row['id'],
            'full_name' => $row['full_name'],
            'email' => $row['email'] ?? null,
            'phone' => $row['phone'] ?? null,
            'whatsapp' => $row['whatsapp'] ?? null,
            'department' => $row['department'] ?? null,
            'role_applied' => $row['role_applied'] ?? null,
            'experience' => $row['experience'] ?? null,
            'education' => $row['education'] ?? null,
            'status' => $row['status'],
            'current_ctc' => isset($row['current_ctc']) && $row['current_ctc'] !== null
                ? (float)$row['current_ctc']
                : null,
            'expected_ctc' => isset($row['expected_ctc']) && $row['expected_ctc'] !== null
                ? (float)$row['expected_ctc']
                : null,
            'resume_drive_link' => $drive,
            'notes' => $row['notes'] ?? null,
            'created_by' => $row['created_by'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            'has_resume' => $hasResumeFile || ($drive !== null && $drive !== ''),
            'has_resume_file' => $hasResumeFile,
            'has_drive_link' => $drive !== null && $drive !== '',
            'attachments' => $attachments,
        ];
    }

    public function listAll()
    {
        $decoded = $this->requireAuth();
        if (!$decoded || !$this->requireView($decoded) || !$this->ensureReady()) {
            return;
        }

        $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
        $status = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
        $department = isset($_GET['department']) ? trim((string)$_GET['department']) : '';
        $role = isset($_GET['role']) ? trim((string)$_GET['role']) : '';
        $hasResume = isset($_GET['has_resume']) ? trim((string)$_GET['has_resume']) : '';
        $sort = isset($_GET['sort']) ? trim((string)$_GET['sort']) : 'newest';
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;

        $where = ['a.deleted_at IS NULL'];
        $params = [];

        if ($q !== '') {
            $where[] = '(a.full_name LIKE ? OR a.email LIKE ? OR a.phone LIKE ? OR a.whatsapp LIKE ?
                OR a.department LIKE ? OR a.role_applied LIKE ? OR a.experience LIKE ?
                OR a.education LIKE ? OR a.notes LIKE ?)';
            $like = '%' . $q . '%';
            for ($i = 0; $i < 9; $i++) {
                $params[] = $like;
            }
        }

        if ($status !== '' && $status !== 'all') {
            if (in_array($status, self::STATUSES, true)) {
                $where[] = 'a.status = ?';
                $params[] = $status;
            }
        }

        if ($department !== '' && $department !== 'all') {
            $where[] = 'a.department = ?';
            $params[] = $this->sanitizeText($department, 100);
        }

        if ($role !== '' && $role !== 'all') {
            $where[] = 'a.role_applied = ?';
            $params[] = $this->sanitizeText($role, 150);
        }

        if ($hasResume === '1' || $hasResume === 'yes') {
            $where[] = '(
                (a.resume_drive_link IS NOT NULL AND a.resume_drive_link <> \'\')
                OR EXISTS (
                    SELECT 1 FROM recruitment_attachments ra
                    WHERE ra.applicant_id = a.id AND ra.kind = \'resume\'
                )
            )';
        } elseif ($hasResume === '0' || $hasResume === 'no') {
            $where[] = '(
                (a.resume_drive_link IS NULL OR a.resume_drive_link = \'\')
                AND NOT EXISTS (
                    SELECT 1 FROM recruitment_attachments ra
                    WHERE ra.applicant_id = a.id AND ra.kind = \'resume\'
                )
            )';
        }

        $whereSql = implode(' AND ', $where);

        $countStmt = $this->conn->prepare(
            "SELECT COUNT(*) FROM recruitment_applicants a WHERE {$whereSql}"
        );
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $orderSql = 'a.created_at DESC, a.id DESC';
        if ($sort === 'oldest') {
            $orderSql = 'a.created_at ASC, a.id ASC';
        } elseif ($sort === 'name') {
            $orderSql = 'a.full_name ASC, a.created_at DESC';
        }

        $sql = "SELECT a.*,
            EXISTS (
                SELECT 1 FROM recruitment_attachments ra
                WHERE ra.applicant_id = a.id AND ra.kind = 'resume'
            ) AS has_resume_file,
            (
                SELECT ra.file_path FROM recruitment_attachments ra
                WHERE ra.applicant_id = a.id AND ra.kind = 'resume'
                ORDER BY ra.created_at DESC LIMIT 1
            ) AS resume_file_path,
            (
                SELECT ra.file_name FROM recruitment_attachments ra
                WHERE ra.applicant_id = a.id AND ra.kind = 'resume'
                ORDER BY ra.created_at DESC LIMIT 1
            ) AS resume_file_name
            FROM recruitment_applicants a
            WHERE {$whereSql}
            ORDER BY {$orderSql}
            LIMIT {$limit} OFFSET {$offset}";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $items = array_map(function ($row) {
            $attachments = null;
            if (!empty($row['resume_file_path'])) {
                $attachments = [[
                    'id' => 'list-resume',
                    'applicant_id' => $row['id'],
                    'kind' => 'resume',
                    'file_path' => $row['resume_file_path'],
                    'file_name' => $row['resume_file_name'] ?? 'resume',
                    'file_type' => null,
                    'file_size' => null,
                    'created_at' => null,
                ]];
            }
            return $this->formatApplicant($row, $attachments);
        }, $rows);

        $facetStmt = $this->conn->query(
            "SELECT DISTINCT department FROM recruitment_applicants
             WHERE deleted_at IS NULL AND department IS NOT NULL AND department <> ''
             ORDER BY department ASC"
        );
        $departments = array_values(array_filter(array_column(
            $facetStmt ? ($facetStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [],
            'department'
        )));

        $roleStmt = $this->conn->query(
            "SELECT DISTINCT role_applied FROM recruitment_applicants
             WHERE deleted_at IS NULL AND role_applied IS NOT NULL AND role_applied <> ''
             ORDER BY role_applied ASC"
        );
        $roles = array_values(array_filter(array_column(
            $roleStmt ? ($roleStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [],
            'role_applied'
        )));

        $this->sendJsonResponse(200, 'OK', [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'facets' => [
                'departments' => $departments,
                'roles' => $roles,
            ],
        ]);
    }

    public function getOne()
    {
        $decoded = $this->requireAuth();
        if (!$decoded || !$this->requireView($decoded) || !$this->ensureReady()) {
            return;
        }

        $id = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
        if ($id === '' || !Utils::isValidUUID($id)) {
            $this->sendJsonResponse(400, 'Valid applicant id is required');
            return;
        }

        $stmt = $this->conn->prepare(
            'SELECT * FROM recruitment_applicants WHERE id = ? AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $this->sendJsonResponse(404, 'Applicant not found');
            return;
        }

        $attachments = $this->loadAttachments($id);
        $this->sendJsonResponse(200, 'OK', $this->formatApplicant($row, $attachments));
    }

    private function parsePayload(array $data): array
    {
        $fullName = $this->sanitizeText($data['full_name'] ?? null, 150);
        if ($fullName === null) {
            throw new InvalidArgumentException('Full name is required');
        }

        $status = isset($data['status']) ? trim((string)$data['status']) : 'applied';
        if (!in_array($status, self::STATUSES, true)) {
            $status = 'applied';
        }

        $email = $this->sanitizeText($data['email'] ?? null, 150);
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email address');
        }

        return [
            'full_name' => $fullName,
            'email' => $email,
            'phone' => $this->sanitizePhone($data['phone'] ?? null),
            'whatsapp' => $this->sanitizePhone($data['whatsapp'] ?? null),
            'department' => $this->sanitizeText($data['department'] ?? null, 100),
            'role_applied' => $this->sanitizeText($data['role_applied'] ?? null, 150),
            'experience' => $this->sanitizeText($data['experience'] ?? null, 255),
            'education' => $this->sanitizeText($data['education'] ?? null, 255),
            'status' => $status,
            'current_ctc' => $this->sanitizeCtc($data['current_ctc'] ?? null),
            'expected_ctc' => $this->sanitizeCtc($data['expected_ctc'] ?? null),
            'resume_drive_link' => $this->sanitizeUrl($data['resume_drive_link'] ?? null),
            'notes' => $this->sanitizeText($data['notes'] ?? null, 5000),
        ];
    }

    public function create()
    {
        $decoded = $this->requireAuth();
        if (!$decoded || !$this->requireManage($decoded) || !$this->ensureReady()) {
            return;
        }

        $data = $this->getRequestData() ?: [];
        try {
            $payload = $this->parsePayload($data);
        } catch (InvalidArgumentException $e) {
            $this->sendJsonResponse(400, $e->getMessage());
            return;
        }

        $id = Utils::generateUUID();
        $stmt = $this->conn->prepare(
            'INSERT INTO recruitment_applicants (
                id, full_name, email, phone, whatsapp, department, role_applied,
                experience, education, status, current_ctc, expected_ctc,
                resume_drive_link, notes, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $payload['full_name'],
            $payload['email'],
            $payload['phone'],
            $payload['whatsapp'],
            $payload['department'],
            $payload['role_applied'],
            $payload['experience'],
            $payload['education'],
            $payload['status'],
            $payload['current_ctc'],
            $payload['expected_ctc'],
            $payload['resume_drive_link'],
            $payload['notes'],
            $decoded->user_id,
        ]);

        $get = $this->conn->prepare(
            'SELECT * FROM recruitment_applicants WHERE id = ? LIMIT 1'
        );
        $get->execute([$id]);
        $row = $get->fetch(PDO::FETCH_ASSOC);
        $this->sendJsonResponse(201, 'Applicant created', $this->formatApplicant($row, []));
    }

    public function update()
    {
        $decoded = $this->requireAuth();
        if (!$decoded || !$this->requireManage($decoded) || !$this->ensureReady()) {
            return;
        }

        $data = $this->getRequestData() ?: [];
        $id = isset($data['id']) ? trim((string)$data['id']) : '';
        if ($id === '' || !Utils::isValidUUID($id)) {
            $this->sendJsonResponse(400, 'Valid applicant id is required');
            return;
        }

        $check = $this->conn->prepare(
            'SELECT * FROM recruitment_applicants WHERE id = ? AND deleted_at IS NULL LIMIT 1'
        );
        $check->execute([$id]);
        $existing = $check->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            $this->sendJsonResponse(404, 'Applicant not found');
            return;
        }

        // Stage-only updates from the kanban card
        if (isset($data['status']) && count($data) <= 2) {
            $status = trim((string)$data['status']);
            if (!in_array($status, self::STATUSES, true)) {
                $this->sendJsonResponse(400, 'Invalid status');
                return;
            }
            $stmt = $this->conn->prepare(
                'UPDATE recruitment_applicants SET status = ? WHERE id = ? AND deleted_at IS NULL'
            );
            $stmt->execute([$status, $id]);
            $existing['status'] = $status;
            $attachments = $this->loadAttachments($id);
            $this->sendJsonResponse(200, 'Status updated', $this->formatApplicant($existing, $attachments));
            return;
        }

        $merged = array_merge($existing, $data);
        try {
            $payload = $this->parsePayload($merged);
        } catch (InvalidArgumentException $e) {
            $this->sendJsonResponse(400, $e->getMessage());
            return;
        }

        $stmt = $this->conn->prepare(
            'UPDATE recruitment_applicants SET
                full_name = ?, email = ?, phone = ?, whatsapp = ?,
                department = ?, role_applied = ?, experience = ?, education = ?,
                status = ?, current_ctc = ?, expected_ctc = ?,
                resume_drive_link = ?, notes = ?
             WHERE id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([
            $payload['full_name'],
            $payload['email'],
            $payload['phone'],
            $payload['whatsapp'],
            $payload['department'],
            $payload['role_applied'],
            $payload['experience'],
            $payload['education'],
            $payload['status'],
            $payload['current_ctc'],
            $payload['expected_ctc'],
            $payload['resume_drive_link'],
            $payload['notes'],
            $id,
        ]);

        $get = $this->conn->prepare(
            'SELECT * FROM recruitment_applicants WHERE id = ? LIMIT 1'
        );
        $get->execute([$id]);
        $row = $get->fetch(PDO::FETCH_ASSOC);
        $attachments = $this->loadAttachments($id);
        $this->sendJsonResponse(200, 'Applicant updated', $this->formatApplicant($row, $attachments));
    }

    public function delete()
    {
        $decoded = $this->requireAuth();
        if (!$decoded || !$this->requireManage($decoded) || !$this->ensureReady()) {
            return;
        }

        $data = $this->getRequestData() ?: [];
        $id = isset($data['id']) ? trim((string)$data['id']) : (isset($_GET['id']) ? trim((string)$_GET['id']) : '');
        if ($id === '' || !Utils::isValidUUID($id)) {
            $this->sendJsonResponse(400, 'Valid applicant id is required');
            return;
        }

        $stmt = $this->conn->prepare(
            'UPDATE recruitment_applicants SET deleted_at = NOW()
             WHERE id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$id]);
        if ($stmt->rowCount() < 1) {
            $this->sendJsonResponse(404, 'Applicant not found');
            return;
        }

        $this->sendJsonResponse(200, 'Applicant deleted');
    }

    public function upload()
    {
        $decoded = $this->requireAuth();
        if (!$decoded || !$this->requireManage($decoded) || !$this->ensureReady()) {
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(405, 'Method not allowed');
            return;
        }

        $applicantId = isset($_POST['applicant_id']) ? trim((string)$_POST['applicant_id']) : '';
        if ($applicantId === '' || !Utils::isValidUUID($applicantId)) {
            $this->sendJsonResponse(400, 'Valid applicant_id is required');
            return;
        }

        $kind = isset($_POST['kind']) ? trim((string)$_POST['kind']) : 'resume';
        if (!in_array($kind, ['resume', 'supporting'], true)) {
            $kind = 'resume';
        }

        $check = $this->conn->prepare(
            'SELECT id FROM recruitment_applicants WHERE id = ? AND deleted_at IS NULL LIMIT 1'
        );
        $check->execute([$applicantId]);
        if (!$check->fetch()) {
            $this->sendJsonResponse(404, 'Applicant not found');
            return;
        }

        if (!isset($_FILES['file']) && !isset($_FILES['files'])) {
            $this->sendJsonResponse(400, 'No file uploaded');
            return;
        }

        $files = [];
        if (isset($_FILES['file']) && is_array($_FILES['file'])) {
            $files[] = $_FILES['file'];
        } elseif (isset($_FILES['files'])) {
            $raw = $_FILES['files'];
            if (is_array($raw['name'])) {
                $count = count($raw['name']);
                for ($i = 0; $i < $count; $i++) {
                    $files[] = [
                        'name' => $raw['name'][$i],
                        'type' => $raw['type'][$i],
                        'tmp_name' => $raw['tmp_name'][$i],
                        'error' => $raw['error'][$i],
                        'size' => $raw['size'][$i],
                    ];
                }
            } else {
                $files[] = $raw;
            }
        }

        $uploadDir = __DIR__ . '/../../uploads/recruitment/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $saved = [];
        foreach ($files as $file) {
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            $size = (int)($file['size'] ?? 0);
            if ($size <= 0 || $size > self::MAX_UPLOAD_BYTES) {
                continue;
            }

            $original = basename((string)($file['name'] ?? 'file'));
            $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
            if (!in_array($ext, self::ALLOWED_EXT, true)) {
                continue;
            }

            $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $original) ?: ('file.' . $ext);
            $targetPath = $uploadDir . uniqid('rec_', true) . '_' . $safeName;
            if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                continue;
            }

            $relativePath = 'uploads/recruitment/' . basename($targetPath);
            $attachmentId = Utils::generateUUID();

            // Replace existing resume when uploading a new primary CV
            if ($kind === 'resume') {
                $old = $this->conn->prepare(
                    "SELECT id, file_path FROM recruitment_attachments
                     WHERE applicant_id = ? AND kind = 'resume'"
                );
                $old->execute([$applicantId]);
                $oldRows = $old->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($oldRows as $oldRow) {
                    $del = $this->conn->prepare(
                        'DELETE FROM recruitment_attachments WHERE id = ?'
                    );
                    $del->execute([$oldRow['id']]);
                    $abs = __DIR__ . '/../../' . ltrim((string)$oldRow['file_path'], '/');
                    if (is_file($abs)) {
                        @unlink($abs);
                    }
                }
            }

            $stmt = $this->conn->prepare(
                'INSERT INTO recruitment_attachments
                    (id, applicant_id, kind, file_path, file_name, file_type, file_size)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $attachmentId,
                $applicantId,
                $kind,
                $relativePath,
                $original,
                $file['type'] ?? null,
                $size,
            ]);

            $saved[] = [
                'id' => $attachmentId,
                'applicant_id' => $applicantId,
                'kind' => $kind,
                'file_path' => $relativePath,
                'file_name' => $original,
                'file_type' => $file['type'] ?? null,
                'file_size' => $size,
            ];
        }

        if (count($saved) === 0) {
            $this->sendJsonResponse(400, 'No valid files uploaded (PDF/DOC/JPG/PNG, max 5MB)');
            return;
        }

        $this->sendJsonResponse(200, 'Upload complete', [
            'attachments' => $saved,
            'applicant' => $this->formatApplicant(
                $this->fetchApplicantRow($applicantId),
                $this->loadAttachments($applicantId)
            ),
        ]);
    }

    private function fetchApplicantRow(string $id): array
    {
        $stmt = $this->conn->prepare(
            'SELECT * FROM recruitment_applicants WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['id' => $id, 'full_name' => '', 'status' => 'applied'];
    }
}
