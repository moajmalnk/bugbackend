<?php
/**
 * Why: Admin-only monthly performance reviews with dynamic templates.
 * Active employees only (users.account_active = 1); department chosen per review.
 */
require_once __DIR__ . '/../BaseAPI.php';

class ReviewsController extends BaseAPI
{
    private const QUESTION_TYPES = ['rating_1_5', 'short_text', 'long_text', 'multi_select', 'boolean'];
    private const STATUSES = ['draft', 'completed'];

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

    private function requireManage($decoded): bool
    {
        $pm = PermissionManager::getInstance();
        if (!$pm->hasPermissionOrAdmin(
            $decoded->user_id ?? '',
            'PERFORMANCE_REVIEWS_MANAGE',
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
            $stmt = $this->conn->query("SHOW TABLES LIKE 'performance_reviews'");
            if (!$stmt) {
                return false;
            }
            $row = $stmt->fetch(PDO::FETCH_NUM);
            return $row !== false && !empty($row[0]);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Why: Production may deploy API files before an admin runs 057 manually.
     * Auto-apply the migration once (same pattern as Shorts/Clients).
     * Also normalize collation to match users (utf8mb4_general_ci) to avoid JOIN mix errors.
     */
    private function ensureSchema(): void
    {
        $migration = realpath(__DIR__ . '/../../migrations/057_performance_reviews.sql');
        if (!$this->tablesReady()) {
            if (!$migration || !is_readable($migration)) {
                error_log('ReviewsController::ensureSchema: migration file missing');
                return;
            }
            try {
                $sql = file_get_contents($migration);
                if ($sql === false || trim($sql) === '') {
                    return;
                }
                $sql = preg_replace('/^\s*--.*$/m', '', $sql);
                foreach (array_filter(array_map('trim', explode(';', (string)$sql))) as $statement) {
                    if ($statement === '') {
                        continue;
                    }
                    $this->conn->exec($statement);
                }
            } catch (Throwable $e) {
                error_log('ReviewsController::ensureSchema: ' . $e->getMessage());
            }
        }

        // Why: Tables created earlier with unicode_ci break JOINs against users (general_ci).
        foreach (['review_templates', 'review_questions', 'performance_reviews', 'review_answers'] as $table) {
            try {
                $this->conn->exec(
                    "ALTER TABLE `{$table}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"
                );
            } catch (Throwable $e) {
                // Ignore if table missing or already matching
            }
        }
    }

    private function ensureReady(): bool
    {
        $this->ensureSchema();
        if (!$this->tablesReady()) {
            $this->sendJsonResponse(
                503,
                'Performance reviews not set up. Run migration 057_performance_reviews.sql on the database.'
            );
            return false;
        }
        return true;
    }

    private function readJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function sanitizeText(string $value, int $maxLen = 10000): string
    {
        $clean = trim(strip_tags($value));
        if (mb_strlen($clean) > $maxLen) {
            $clean = mb_substr($clean, 0, $maxLen);
        }
        return $clean;
    }

    private function formatQuestion(array $row): array
    {
        $options = null;
        if (!empty($row['options_json'])) {
            $decoded = json_decode((string)$row['options_json'], true);
            $options = is_array($decoded) ? $decoded : null;
        }
        return [
            'id' => (int)$row['id'],
            'template_id' => (int)$row['template_id'],
            'section_name' => $row['section_name'] ?? '',
            'question_text' => $row['question_text'] ?? '',
            'question_type' => $row['question_type'] ?? 'short_text',
            'options_json' => $row['options_json'] ?? null,
            'options' => $options,
            'is_required' => (int)($row['is_required'] ?? 0) === 1,
            'display_order' => (int)($row['display_order'] ?? 0),
        ];
    }

    private function formatReview(array $row, ?array $answers = null): array
    {
        $out = [
            'id' => (int)$row['id'],
            'employee_id' => $row['employee_id'],
            'employee_username' => $row['employee_username'] ?? null,
            'employee_email' => $row['employee_email'] ?? null,
            'employee_role' => $row['employee_role'] ?? null,
            'reviewer_id' => $row['reviewer_id'],
            'reviewer_username' => $row['reviewer_username'] ?? null,
            'department' => $row['department'] ?? '',
            'review_month' => $row['review_month'],
            'review_date' => $row['review_date'],
            'status' => $row['status'],
            'overall_rating' => isset($row['overall_rating']) && $row['overall_rating'] !== null
                ? (float)$row['overall_rating']
                : null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
        if ($answers !== null) {
            $out['answers'] = $answers;
        }
        return $out;
    }

    private function isEmployeeActive(string $employeeId): bool
    {
        $sql = "SELECT id FROM users WHERE id = ? AND (account_active IS NULL OR account_active = 1) LIMIT 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$employeeId]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    /** GET active users for employee dropdown */
    public function getActiveUsers()
    {
        $decoded = $this->requireAuth();
        if (!$decoded || !$this->requireManage($decoded)) {
            return;
        }

        $hasCol = true;
        try {
            $chk = $this->conn->query("SHOW COLUMNS FROM users LIKE 'account_active'");
            $hasCol = $chk && $chk->rowCount() > 0;
        } catch (Throwable $e) {
            $hasCol = false;
        }

        $sql = $hasCol
            ? "SELECT id, username, email, role FROM users WHERE account_active = 1 ORDER BY username ASC"
            : "SELECT id, username, email, role FROM users ORDER BY username ASC";
        $stmt = $this->conn->query($sql);
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $this->sendJsonResponse(200, 'OK', array_map(static function ($r) {
            return [
                'id' => $r['id'],
                'username' => $r['username'],
                'email' => $r['email'] ?? null,
                'role' => $r['role'] ?? null,
            ];
        }, $rows));
    }

    /** GET active template + ordered questions */
    public function getTemplate()
    {
        $decoded = $this->requireAuth();
        if (!$decoded || !$this->requireManage($decoded) || !$this->ensureReady()) {
            return;
        }

        $stmt = $this->conn->query(
            "SELECT * FROM review_templates WHERE is_active = 1 ORDER BY id ASC LIMIT 1"
        );
        $template = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        if (!$template) {
            $this->sendJsonResponse(404, 'No active review template found');
            return;
        }

        $qStmt = $this->conn->prepare(
            "SELECT * FROM review_questions WHERE template_id = ? ORDER BY display_order ASC, id ASC"
        );
        $qStmt->execute([(int)$template['id']]);
        $questions = $qStmt->fetchAll(PDO::FETCH_ASSOC);

        $this->sendJsonResponse(200, 'OK', [
            'id' => (int)$template['id'],
            'title' => $template['title'],
            'is_active' => (int)$template['is_active'] === 1,
            'created_by' => $template['created_by'] ?? null,
            'created_at' => $template['created_at'] ?? null,
            'updated_at' => $template['updated_at'] ?? null,
            'questions' => array_map([$this, 'formatQuestion'], $questions),
        ]);
    }

    /** POST create or update a question */
    public function saveQuestion()
    {
        $decoded = $this->requireAuth();
        if (!$decoded || !$this->requireManage($decoded) || !$this->ensureReady()) {
            return;
        }

        $body = $this->readJsonBody();
        $id = isset($body['id']) ? (int)$body['id'] : 0;
        $templateId = isset($body['template_id']) ? (int)$body['template_id'] : 0;
        $sectionName = $this->sanitizeText((string)($body['section_name'] ?? ''), 100);
        $questionText = $this->sanitizeText((string)($body['question_text'] ?? ''), 2000);
        $questionType = (string)($body['question_type'] ?? 'short_text');
        $isRequired = !empty($body['is_required']) ? 1 : 0;
        $displayOrder = isset($body['display_order']) ? (int)$body['display_order'] : 0;

        if ($questionText === '') {
            $this->sendJsonResponse(400, 'question_text is required');
            return;
        }
        if (!in_array($questionType, self::QUESTION_TYPES, true)) {
            $this->sendJsonResponse(400, 'Invalid question_type');
            return;
        }

        $optionsJson = null;
        if ($questionType === 'multi_select') {
            $opts = $body['options'] ?? $body['options_json'] ?? null;
            if (is_string($opts)) {
                $decodedOpts = json_decode($opts, true);
                $opts = is_array($decodedOpts) ? $decodedOpts : [];
            }
            if (!is_array($opts)) {
                $opts = [];
            }
            $opts = array_values(array_filter(array_map(function ($o) {
                return $this->sanitizeText((string)$o, 200);
            }, $opts), static fn($o) => $o !== ''));
            $optionsJson = json_encode($opts, JSON_UNESCAPED_UNICODE);
        }

        if ($id > 0) {
            $chk = $this->conn->prepare("SELECT id, template_id FROM review_questions WHERE id = ? LIMIT 1");
            $chk->execute([$id]);
            $existing = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                $this->sendJsonResponse(404, 'Question not found');
                return;
            }
            $templateId = (int)$existing['template_id'];
            $upd = $this->conn->prepare(
                "UPDATE review_questions
                 SET section_name = ?, question_text = ?, question_type = ?, options_json = ?,
                     is_required = ?, display_order = ?
                 WHERE id = ?"
            );
            $upd->execute([$sectionName, $questionText, $questionType, $optionsJson, $isRequired, $displayOrder, $id]);
        } else {
            if ($templateId <= 0) {
                $tStmt = $this->conn->query(
                    "SELECT id FROM review_templates WHERE is_active = 1 ORDER BY id ASC LIMIT 1"
                );
                $t = $tStmt ? $tStmt->fetch(PDO::FETCH_ASSOC) : false;
                if (!$t) {
                    $this->sendJsonResponse(404, 'No active template');
                    return;
                }
                $templateId = (int)$t['id'];
            }
            if ($displayOrder <= 0) {
                $maxStmt = $this->conn->prepare(
                    "SELECT COALESCE(MAX(display_order), 0) AS mx FROM review_questions WHERE template_id = ?"
                );
                $maxStmt->execute([$templateId]);
                $displayOrder = ((int)($maxStmt->fetch(PDO::FETCH_ASSOC)['mx'] ?? 0)) + 10;
            }
            $ins = $this->conn->prepare(
                "INSERT INTO review_questions
                 (template_id, section_name, question_text, question_type, options_json, is_required, display_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $ins->execute([$templateId, $sectionName, $questionText, $questionType, $optionsJson, $isRequired, $displayOrder]);
            $id = (int)$this->conn->lastInsertId();
        }

        $get = $this->conn->prepare("SELECT * FROM review_questions WHERE id = ? LIMIT 1");
        $get->execute([$id]);
        $row = $get->fetch(PDO::FETCH_ASSOC);
        $this->sendJsonResponse(200, 'Question saved', $this->formatQuestion($row));
    }

    /** POST delete question */
    public function deleteQuestion()
    {
        $decoded = $this->requireAuth();
        if (!$decoded || !$this->requireManage($decoded) || !$this->ensureReady()) {
            return;
        }

        $body = $this->readJsonBody();
        $id = isset($body['id']) ? (int)$body['id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
        if ($id <= 0) {
            $this->sendJsonResponse(400, 'id is required');
            return;
        }

        $chk = $this->conn->prepare("SELECT id FROM review_questions WHERE id = ? LIMIT 1");
        $chk->execute([$id]);
        if (!$chk->fetch(PDO::FETCH_ASSOC)) {
            $this->sendJsonResponse(404, 'Question not found');
            return;
        }

        // CASCADE removes answers; also delete explicitly for older MySQL without FK
        $this->conn->prepare("DELETE FROM review_answers WHERE question_id = ?")->execute([$id]);
        $this->conn->prepare("DELETE FROM review_questions WHERE id = ?")->execute([$id]);
        $this->sendJsonResponse(200, 'Question deleted');
    }

    /** GET list reviews with filters + pagination */
    public function listReviews()
    {
        $decoded = $this->requireAuth();
        if (!$decoded || !$this->requireManage($decoded) || !$this->ensureReady()) {
            return;
        }

        try {
            $employeeId = isset($_GET['employee_id']) ? trim((string)$_GET['employee_id']) : '';
            $reviewMonth = isset($_GET['review_month']) ? trim((string)$_GET['review_month']) : '';
            $department = isset($_GET['department']) ? trim((string)$_GET['department']) : '';
            $status = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
            $search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;

            $where = ['1=1'];
            $params = [];

            if ($employeeId !== '') {
                $where[] = 'pr.employee_id = ?';
                $params[] = $employeeId;
            }
            if ($reviewMonth !== '' && preg_match('/^\d{4}-\d{2}$/', $reviewMonth)) {
                $where[] = 'pr.review_month = ?';
                $params[] = $reviewMonth;
            }
            if ($department !== '') {
                $where[] = 'pr.department = ?';
                $params[] = $department;
            }
            if ($status !== '' && in_array($status, self::STATUSES, true)) {
                $where[] = 'pr.status = ?';
                $params[] = $status;
            }
            if ($search !== '') {
                $where[] = '(eu.username LIKE ? OR eu.email LIKE ?)';
                $like = '%' . $search . '%';
                $params[] = $like;
                $params[] = $like;
            }

            $whereSql = implode(' AND ', $where);
            $from = "FROM performance_reviews pr
                     LEFT JOIN users eu ON eu.id = pr.employee_id COLLATE utf8mb4_general_ci
                     LEFT JOIN users ru ON ru.id = pr.reviewer_id COLLATE utf8mb4_general_ci
                     WHERE {$whereSql}";

            $countStmt = $this->conn->prepare("SELECT COUNT(*) AS c {$from}");
            $countStmt->execute($params);
            $total = (int)($countStmt->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

            $sql = "SELECT pr.*,
                           eu.username AS employee_username,
                           eu.email AS employee_email,
                           eu.role AS employee_role,
                           ru.username AS reviewer_username
                    {$from}
                    ORDER BY pr.created_at DESC, pr.id DESC
                    LIMIT {$limit} OFFSET {$offset}";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $items = [];
            foreach ($rows as $r) {
                $items[] = $this->formatReview($r);
            }

            $this->sendJsonResponse(200, 'OK', [
                'items' => $items,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
            ]);
        } catch (Throwable $e) {
            error_log('listReviews error: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to list reviews: ' . $e->getMessage());
        }
    }

    /** POST create draft review for active employee */
    public function createReview()
    {
        $decoded = $this->requireAuth();
        if (!$decoded || !$this->requireManage($decoded) || !$this->ensureReady()) {
            return;
        }

        $body = $this->readJsonBody();
        $employeeId = trim((string)($body['employee_id'] ?? ''));
        $department = $this->sanitizeText((string)($body['department'] ?? ''), 100);
        $reviewMonth = trim((string)($body['review_month'] ?? ''));
        $reviewDate = trim((string)($body['review_date'] ?? ''));

        if ($employeeId === '') {
            $this->sendJsonResponse(400, 'employee_id is required');
            return;
        }
        if ($department === '') {
            $this->sendJsonResponse(400, 'department is required');
            return;
        }
        if (!preg_match('/^\d{4}-\d{2}$/', $reviewMonth)) {
            $this->sendJsonResponse(400, 'review_month must be YYYY-MM');
            return;
        }
        if ($reviewDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $reviewDate)) {
            $reviewDate = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
        }

        if (!$this->isEmployeeActive($employeeId)) {
            $this->sendJsonResponse(400, 'Employee must be an active user account');
            return;
        }

        $dup = $this->conn->prepare(
            "SELECT id FROM performance_reviews WHERE employee_id = ? AND review_month = ? LIMIT 1"
        );
        $dup->execute([$employeeId, $reviewMonth]);
        if ($dup->fetch(PDO::FETCH_ASSOC)) {
            $this->sendJsonResponse(409, 'A review already exists for this employee and month');
            return;
        }

        $reviewerId = (string)$decoded->user_id;
        $ins = $this->conn->prepare(
            "INSERT INTO performance_reviews
             (employee_id, reviewer_id, department, review_month, review_date, status)
             VALUES (?, ?, ?, ?, ?, 'draft')"
        );
        try {
            $ins->execute([$employeeId, $reviewerId, $department, $reviewMonth, $reviewDate]);
        } catch (PDOException $e) {
            if ((int)$e->getCode() === 23000) {
                $this->sendJsonResponse(409, 'A review already exists for this employee and month');
                return;
            }
            throw $e;
        }

        $id = (int)$this->conn->lastInsertId();
        $this->getReviewById($id);
    }

    /** GET single review + answers */
    public function getReview()
    {
        $decoded = $this->requireAuth();
        if (!$decoded || !$this->requireManage($decoded) || !$this->ensureReady()) {
            return;
        }

        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) {
            $this->sendJsonResponse(400, 'id is required');
            return;
        }
        $this->getReviewById($id);
    }

    private function getReviewById(int $id): void
    {
        $stmt = $this->conn->prepare(
            "SELECT pr.*,
                    eu.username AS employee_username,
                    eu.email AS employee_email,
                    eu.role AS employee_role,
                    ru.username AS reviewer_username
             FROM performance_reviews pr
             LEFT JOIN users eu ON eu.id = pr.employee_id COLLATE utf8mb4_general_ci
             LEFT JOIN users ru ON ru.id = pr.reviewer_id COLLATE utf8mb4_general_ci
             WHERE pr.id = ?
             LIMIT 1"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $this->sendJsonResponse(404, 'Review not found');
            return;
        }

        $aStmt = $this->conn->prepare(
            "SELECT ra.*, rq.section_name, rq.question_text, rq.question_type, rq.options_json, rq.is_required, rq.display_order
             FROM review_answers ra
             INNER JOIN review_questions rq ON rq.id = ra.question_id
             WHERE ra.review_id = ?
             ORDER BY rq.display_order ASC, rq.id ASC"
        );
        $aStmt->execute([$id]);
        $answerRows = $aStmt->fetchAll(PDO::FETCH_ASSOC);
        $answers = array_map(static function ($a) {
            return [
                'id' => (int)$a['id'],
                'review_id' => (int)$a['review_id'],
                'question_id' => (int)$a['question_id'],
                'answer_text' => $a['answer_text'],
                'section_name' => $a['section_name'] ?? '',
                'question_text' => $a['question_text'] ?? '',
                'question_type' => $a['question_type'] ?? '',
                'is_required' => (int)($a['is_required'] ?? 0) === 1,
                'display_order' => (int)($a['display_order'] ?? 0),
            ];
        }, $answerRows);

        $this->sendJsonResponse(200, 'OK', $this->formatReview($row, $answers));
    }

    /** POST save answers + optional status update */
    public function saveAnswers()
    {
        $decoded = $this->requireAuth();
        if (!$decoded || !$this->requireManage($decoded) || !$this->ensureReady()) {
            return;
        }

        $body = $this->readJsonBody();
        $reviewId = isset($body['review_id']) ? (int)$body['review_id'] : (isset($body['id']) ? (int)$body['id'] : 0);
        if ($reviewId <= 0) {
            $this->sendJsonResponse(400, 'review_id is required');
            return;
        }

        $chk = $this->conn->prepare("SELECT * FROM performance_reviews WHERE id = ? LIMIT 1");
        $chk->execute([$reviewId]);
        $review = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$review) {
            $this->sendJsonResponse(404, 'Review not found');
            return;
        }

        // Keep meta fields updatable on save
        $department = isset($body['department'])
            ? $this->sanitizeText((string)$body['department'], 100)
            : ($review['department'] ?? '');
        $reviewDate = isset($body['review_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$body['review_date'])
            ? (string)$body['review_date']
            : $review['review_date'];
        $newStatus = isset($body['status']) ? (string)$body['status'] : $review['status'];
        if (!in_array($newStatus, self::STATUSES, true)) {
            $newStatus = $review['status'];
        }

        $answers = $body['answers'] ?? [];
        if (!is_array($answers)) {
            $answers = [];
        }

        // Load questions for validation / rating average
        $qStmt = $this->conn->query(
            "SELECT rq.* FROM review_questions rq
             INNER JOIN review_templates rt ON rt.id = rq.template_id AND rt.is_active = 1
             ORDER BY rq.display_order ASC, rq.id ASC"
        );
        $questions = $qStmt ? $qStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $qById = [];
        foreach ($questions as $q) {
            $qById[(int)$q['id']] = $q;
        }

        $answerMap = [];
        foreach ($answers as $a) {
            if (!is_array($a)) {
                continue;
            }
            $qid = (int)($a['question_id'] ?? 0);
            if ($qid <= 0 || !isset($qById[$qid])) {
                continue;
            }
            $raw = $a['answer_text'] ?? $a['value'] ?? '';
            if (is_array($raw)) {
                $raw = json_encode(array_values($raw), JSON_UNESCAPED_UNICODE);
            }
            $text = $this->sanitizeText((string)$raw, 20000);
            $type = $qById[$qid]['question_type'];
            if ($type === 'rating_1_5' && $text !== '') {
                $n = (float)$text;
                if ($n < 1 || $n > 5) {
                    $this->sendJsonResponse(400, "Rating for question {$qid} must be between 1 and 5");
                    return;
                }
                $text = (string)$n;
            }
            if ($type === 'boolean' && $text !== '') {
                $text = in_array(strtolower($text), ['1', 'true', 'yes'], true) ? 'true' : 'false';
            }
            $answerMap[$qid] = $text;
        }

        if ($newStatus === 'completed') {
            foreach ($questions as $q) {
                if ((int)$q['is_required'] !== 1) {
                    continue;
                }
                $qid = (int)$q['id'];
                $val = $answerMap[$qid] ?? null;
                if ($val === null || trim((string)$val) === '') {
                    // Also accept previously saved answer
                    $prev = $this->conn->prepare(
                        "SELECT answer_text FROM review_answers WHERE review_id = ? AND question_id = ? LIMIT 1"
                    );
                    $prev->execute([$reviewId, $qid]);
                    $prevRow = $prev->fetch(PDO::FETCH_ASSOC);
                    $prevVal = $prevRow['answer_text'] ?? '';
                    if ($val === null && trim((string)$prevVal) !== '') {
                        continue;
                    }
                    if (trim((string)$val) === '' && trim((string)$prevVal) === '') {
                        $this->sendJsonResponse(400, 'Please answer all required questions before completing');
                        return;
                    }
                }
            }
        }

        $this->conn->beginTransaction();
        try {
            $upsert = $this->conn->prepare(
                "INSERT INTO review_answers (review_id, question_id, answer_text)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE answer_text = VALUES(answer_text)"
            );
            foreach ($answerMap as $qid => $text) {
                $upsert->execute([$reviewId, $qid, $text]);
            }

            $overall = null;
            if ($newStatus === 'completed') {
                $ratingStmt = $this->conn->prepare(
                    "SELECT ra.answer_text
                     FROM review_answers ra
                     INNER JOIN review_questions rq ON rq.id = ra.question_id
                     WHERE ra.review_id = ? AND rq.question_type = 'rating_1_5'
                       AND ra.answer_text IS NOT NULL AND ra.answer_text != ''"
                );
                $ratingStmt->execute([$reviewId]);
                $ratings = [];
                while ($r = $ratingStmt->fetch(PDO::FETCH_ASSOC)) {
                    $n = (float)$r['answer_text'];
                    if ($n >= 1 && $n <= 5) {
                        $ratings[] = $n;
                    }
                }
                if (count($ratings) > 0) {
                    $overall = round(array_sum($ratings) / count($ratings), 2);
                }
            }

            $upd = $this->conn->prepare(
                "UPDATE performance_reviews
                 SET department = ?, review_date = ?, status = ?, overall_rating = ?
                 WHERE id = ?"
            );
            $upd->execute([
                $department,
                $reviewDate,
                $newStatus,
                $overall !== null ? $overall : ($newStatus === 'completed' ? $review['overall_rating'] : null),
                $reviewId,
            ]);

            $this->conn->commit();
        } catch (Throwable $e) {
            $this->conn->rollBack();
            error_log('saveAnswers error: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to save answers');
            return;
        }

        $this->getReviewById($reviewId);
    }

    /** POST delete review */
    public function deleteReview()
    {
        $decoded = $this->requireAuth();
        if (!$decoded || !$this->requireManage($decoded) || !$this->ensureReady()) {
            return;
        }

        $body = $this->readJsonBody();
        $id = isset($body['id']) ? (int)$body['id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
        if ($id <= 0) {
            $this->sendJsonResponse(400, 'id is required');
            return;
        }

        $chk = $this->conn->prepare("SELECT id FROM performance_reviews WHERE id = ? LIMIT 1");
        $chk->execute([$id]);
        if (!$chk->fetch(PDO::FETCH_ASSOC)) {
            $this->sendJsonResponse(404, 'Review not found');
            return;
        }

        $this->conn->prepare("DELETE FROM review_answers WHERE review_id = ?")->execute([$id]);
        $this->conn->prepare("DELETE FROM performance_reviews WHERE id = ?")->execute([$id]);
        $this->sendJsonResponse(200, 'Review deleted');
    }

    /**
     * Why: Aggregate Blockers/Challenges answers by month for the monthly team review doc replacement.
     */
    public function getChallengesSummary()
    {
        $decoded = $this->requireAuth();
        if (!$decoded || !$this->requireManage($decoded) || !$this->ensureReady()) {
            return;
        }

        $reviewMonth = isset($_GET['review_month']) ? trim((string)$_GET['review_month']) : '';

        $where = [
            "(rq.section_name LIKE '%Blocker%' OR rq.section_name LIKE '%Challenge%')",
            "(u.account_active IS NULL OR u.account_active = 1)",
            "ra.answer_text IS NOT NULL",
            "TRIM(ra.answer_text) != ''",
            "ra.answer_text NOT IN ('true','false','[]')",
        ];
        $params = [];
        if ($reviewMonth !== '' && preg_match('/^\d{4}-\d{2}$/', $reviewMonth)) {
            $where[] = 'pr.review_month = ?';
            $params[] = $reviewMonth;
        }

        $whereSql = implode(' AND ', $where);
        $sql = "SELECT pr.review_month,
                       pr.department,
                       pr.employee_id,
                       u.username AS employee_username,
                       rq.section_name,
                       rq.question_text,
                       rq.question_type,
                       ra.answer_text,
                       pr.status
                FROM review_answers ra
                INNER JOIN performance_reviews pr ON pr.id = ra.review_id
                INNER JOIN review_questions rq ON rq.id = ra.question_id
                INNER JOIN users u ON u.id = pr.employee_id COLLATE utf8mb4_general_ci
                WHERE {$whereSql}
                ORDER BY pr.review_month DESC, u.username ASC, rq.display_order ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $grouped = [];
        foreach ($rows as $r) {
            $month = $r['review_month'];
            if (!isset($grouped[$month])) {
                $grouped[$month] = [
                    'review_month' => $month,
                    'entries' => [],
                ];
            }
            $grouped[$month]['entries'][] = [
                'employee_id' => $r['employee_id'],
                'employee_username' => $r['employee_username'],
                'department' => $r['department'],
                'section_name' => $r['section_name'],
                'question_text' => $r['question_text'],
                'question_type' => $r['question_type'],
                'answer_text' => $r['answer_text'],
                'status' => $r['status'],
            ];
        }

        $this->sendJsonResponse(200, 'OK', [
            'months' => array_values($grouped),
        ]);
    }
}
