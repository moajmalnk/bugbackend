<?php
/**
 * Why: BugDates is the org operations calendar — programs, observances, holidays,
 * leave/WFH overlays, and hooks into BugCreative / BugToDo.
 */
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../config/utils.php';
require_once __DIR__ . '/../../utils/bug_dates_recurrence.php';
require_once __DIR__ . '/../../utils/leave_attendance.php';

class BugDatesController extends BaseAPI
{
    private const CATEGORIES = [
        'growth_program', 'observance', 'holiday', 'milestone', 'company_event',
    ];
    private const RECURRENCE = ['none', 'daily', 'weekly', 'monthly', 'yearly'];
    private const VISIBILITY = ['company', 'hr_only', 'admins'];
    private const STATUSES = ['approved', 'pending_approval', 'rejected'];

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

    private function can(object $decoded, string $key): bool
    {
        $pm = PermissionManager::getInstance();
        return $pm->hasPermissionOrAdmin(
            $decoded->user_id ?? '',
            $key,
            $decoded->role ?? null
        );
    }

    private function isAdmin(object $decoded): bool
    {
        return strtolower(trim((string)($decoded->role ?? ''))) === 'admin';
    }

    private function ensureReady(): bool
    {
        if (!br_bug_dates_tables_ready($this->conn)) {
            $this->sendJsonResponse(
                503,
                'BugDates is not set up. Run migration 095_bug_dates_events.sql.'
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

    private function sanitizeDate(?string $value): ?string
    {
        $clean = $this->sanitizeText($value, 10);
        if ($clean === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $clean)) {
            return null;
        }
        return $clean;
    }

    private function sanitizeTime(?string $value): ?string
    {
        $clean = $this->sanitizeText($value, 8);
        if ($clean === null) {
            return null;
        }
        if (preg_match('/^\d{1,2}:\d{2}$/', $clean)) {
            $clean .= ':00';
        }
        if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $clean)) {
            return null;
        }
        return $clean;
    }

    private function decodeJsonField($raw)
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : null;
        }
        return null;
    }

    private function formatEvent(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'title' => $row['title'],
            'description' => $row['description'] ?? null,
            'category' => $row['category'],
            'recurrence_type' => $row['recurrence_type'],
            'recurrence_days' => $this->decodeJsonField($row['recurrence_days'] ?? null),
            'start_date' => $row['start_date'],
            'end_date' => $row['end_date'] ?? null,
            'start_time' => $row['start_time'] ?? null,
            'end_time' => $row['end_time'] ?? null,
            'location_or_link' => $row['location_or_link'] ?? null,
            'is_office_closed' => (bool)(int)($row['is_office_closed'] ?? 0),
            'auto_hooks' => $this->decodeJsonField($row['auto_hooks'] ?? null),
            'visibility' => $row['visibility'] ?? 'company',
            'status' => $row['status'],
            'created_by' => $row['created_by'],
            'created_by_name' => $row['created_by_name'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function canSeeVisibility(object $decoded, string $visibility): bool
    {
        $v = strtolower($visibility);
        if ($v === 'company') {
            return true;
        }
        if ($v === 'hr_only') {
            return $this->can($decoded, 'LEAVE_MANAGE')
                || $this->can($decoded, 'ATTENDANCE_MANAGE')
                || $this->can($decoded, 'BUGDATES_MANAGE');
        }
        if ($v === 'admins') {
            return $this->isAdmin($decoded) || $this->can($decoded, 'BUGDATES_MANAGE');
        }
        return false;
    }

    private function fetchEvent(int $id): ?array
    {
        $stmt = $this->conn->prepare(
            "SELECT e.*, u.username AS created_by_name
             FROM bug_dates_events e
             LEFT JOIN users u ON u.id = e.created_by
             WHERE e.id = ?
             LIMIT 1"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function holidays()
    {
        $decoded = $this->requireAuth();
        if (!$decoded) {
            return;
        }
        $from = $this->sanitizeDate($_GET['from'] ?? null);
        $to = $this->sanitizeDate($_GET['to'] ?? null);
        if (!$from || !$to) {
            $this->sendJsonResponse(400, 'from and to (YYYY-MM-DD) are required');
            return;
        }
        $includeSundays = !isset($_GET['include_sundays']) || (string)$_GET['include_sundays'] !== '0';
        $dates = br_bug_dates_closed_dates($this->conn, $from, $to, $includeSundays);
        $this->sendJsonResponse(200, 'OK', [
            'from' => $from,
            'to' => $to,
            'dates' => $dates,
        ]);
    }

    public function calendar()
    {
        $decoded = $this->requireAuth();
        if (!$decoded) {
            return;
        }
        $canView = $this->can($decoded, 'BUGDATES_VIEW')
            || $this->can($decoded, 'LEAVE_VIEW')
            || $this->isAdmin($decoded);
        if (!$canView) {
            $this->sendJsonResponse(403, 'Access denied');
            return;
        }
        if (!$this->ensureReady()) {
            return;
        }

        $from = $this->sanitizeDate($_GET['from'] ?? null);
        $to = $this->sanitizeDate($_GET['to'] ?? null);
        if (!$from || !$to) {
            $this->sendJsonResponse(400, 'from and to (YYYY-MM-DD) are required');
            return;
        }

        $categoryFilter = [];
        if (!empty($_GET['categories'])) {
            $raw = is_array($_GET['categories'])
                ? $_GET['categories']
                : explode(',', (string)$_GET['categories']);
            foreach ($raw as $c) {
                $c = strtolower(trim((string)$c));
                if (in_array($c, array_merge(self::CATEGORIES, [
                    'leave', 'wfh', 'birthday', 'anniversary', 'project_milestone',
                ]), true)) {
                    $categoryFilter[] = $c;
                }
            }
        }
        $want = static function (string $layer) use ($categoryFilter): bool {
            return empty($categoryFilter) || in_array($layer, $categoryFilter, true);
        };

        $items = [];
        $viewerId = (string)$decoded->user_id;
        $canSeeLeaveDetails = $this->can($decoded, 'LEAVE_MANAGE')
            || $this->can($decoded, 'ATTENDANCE_MANAGE');

        // 1) Catalog events
        if ($want('growth_program') || $want('observance') || $want('holiday')
            || $want('milestone') || $want('company_event') || empty($categoryFilter)
        ) {
            $stmt = $this->conn->query(
                "SELECT e.*, u.username AS created_by_name
                 FROM bug_dates_events e
                 LEFT JOIN users u ON u.id = e.created_by
                 WHERE e.status = 'approved'
                 ORDER BY e.start_date ASC, e.id ASC"
            );
            $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            foreach ($rows as $row) {
                if (!$this->canSeeVisibility($decoded, (string)($row['visibility'] ?? 'company'))) {
                    continue;
                }
                $cat = (string)$row['category'];
                if (!$want($cat) && !empty($categoryFilter)) {
                    continue;
                }
                $occurrences = br_bug_dates_expand_occurrences($row, $from, $to);
                $event = $this->formatEvent($row);
                foreach ($occurrences as $occ) {
                    $items[] = array_merge($event, [
                        'source' => 'event',
                        'occurrence_date' => $occ,
                        'layer' => $cat,
                    ]);
                }
            }
        }

        // 2) Leave overlays
        if ($want('leave') || empty($categoryFilter)) {
            $items = array_merge($items, $this->leaveOverlayItems(
                $from,
                $to,
                $viewerId,
                $canSeeLeaveDetails
            ));
        }

        // 3) WFH overlays
        if ($want('wfh') || empty($categoryFilter)) {
            $items = array_merge($items, $this->wfhOverlayItems(
                $from,
                $to,
                $viewerId,
                $canSeeLeaveDetails
            ));
        }

        // 4) Birthdays / anniversaries
        if ($want('birthday') || $want('anniversary') || empty($categoryFilter)) {
            $items = array_merge(
                $items,
                $this->personalMilestoneItems($from, $to, $want)
            );
        }

        // 5) Project timeline milestones
        if ($want('project_milestone') || $want('milestone') || empty($categoryFilter)) {
            $items = array_merge($items, $this->projectMilestoneItems($from, $to));
        }

        usort($items, static function ($a, $b) {
            $da = (string)($a['occurrence_date'] ?? '');
            $db = (string)($b['occurrence_date'] ?? '');
            if ($da === $db) {
                return strcmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
            }
            return strcmp($da, $db);
        });

        $this->sendJsonResponse(200, 'OK', [
            'from' => $from,
            'to' => $to,
            'items' => $items,
            'count' => count($items),
        ]);
    }

    /**
     * @return list<array>
     */
    private function leaveOverlayItems(
        string $from,
        string $to,
        string $viewerId,
        bool $canSeeDetails
    ): array {
        if (!br_leave_tables_ready($this->conn)) {
            return [];
        }
        $hasHalf = false;
        try {
            $c = $this->conn->query("SHOW COLUMNS FROM leave_requests LIKE 'is_half_day'");
            $hasHalf = (bool)($c && $c->fetch(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            $hasHalf = false;
        }

        $halfCols = $hasHalf ? ', lr.is_half_day, lr.half_day_type' : '';
        $stmt = $this->conn->prepare(
            "SELECT lr.id, lr.user_id, lr.start_date, lr.end_date, lr.days_count, lr.reason, lr.status,
                    lt.code AS leave_type_code, lt.name AS leave_type_name, u.username
                    {$halfCols}
             FROM leave_requests lr
             LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
             LEFT JOIN users u ON u.id = lr.user_id
             WHERE lr.status IN ('pending', 'approved')
               AND lr.start_date <= ?
               AND lr.end_date >= ?
             ORDER BY lr.start_date ASC"
        );
        $stmt->execute([$to, $from]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $tz = new DateTimeZone('Asia/Kolkata');
        $items = [];
        foreach ($rows as $row) {
            $start = max($from, (string)$row['start_date']);
            $end = min($to, (string)$row['end_date']);
            if ($start > $end) {
                continue;
            }
            $isSelf = (string)$row['user_id'] === $viewerId;
            $showReason = $isSelf || $canSeeDetails;
            $cursor = DateTime::createFromFormat('Y-m-d', $start, $tz);
            $endDt = DateTime::createFromFormat('Y-m-d', $end, $tz);
            if (!$cursor || !$endDt) {
                continue;
            }
            while ($cursor <= $endDt) {
                $occ = $cursor->format('Y-m-d');
                $typeName = (string)($row['leave_type_name'] ?? 'Leave');
                $username = (string)($row['username'] ?? 'Teammate');
                $item = [
                    'source' => 'leave',
                    'layer' => 'leave',
                    'occurrence_date' => $occ,
                    'title' => $showReason
                        ? "{$username} — {$typeName}"
                        : "{$username} — Away",
                    'category' => 'attendance',
                    'leave_request_id' => (int)$row['id'],
                    'user_id' => $row['user_id'],
                    'username' => $username,
                    'leave_type_code' => $row['leave_type_code'] ?? null,
                    'leave_type_name' => $typeName,
                    'status' => $row['status'],
                    'is_half_day' => $hasHalf ? (bool)(int)($row['is_half_day'] ?? 0) : false,
                    'half_day_type' => $hasHalf ? ($row['half_day_type'] ?? null) : null,
                ];
                if ($showReason) {
                    $item['reason'] = $row['reason'] ?? null;
                    $item['description'] = $row['reason'] ?? null;
                }
                $items[] = $item;
                $cursor->modify('+1 day');
            }
        }
        return $items;
    }

    /**
     * @return list<array>
     */
    private function wfhOverlayItems(
        string $from,
        string $to,
        string $viewerId,
        bool $canSeeDetails
    ): array {
        try {
            $t = $this->conn->query("SHOW TABLES LIKE 'attendance_wfh_requests'");
            if (!$t || !$t->fetch(PDO::FETCH_NUM)) {
                return [];
            }
        } catch (Throwable $e) {
            return [];
        }

        $stmt = $this->conn->prepare(
            "SELECT w.id, w.user_id, w.request_date, w.status, w.user_note, u.username
             FROM attendance_wfh_requests w
             LEFT JOIN users u ON u.id = w.user_id
             WHERE w.status IN ('pending', 'approved')
               AND w.request_date BETWEEN ? AND ?
             ORDER BY w.request_date ASC"
        );
        $stmt->execute([$from, $to]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $items = [];
        foreach ($rows as $row) {
            $isSelf = (string)$row['user_id'] === $viewerId;
            $showNote = $isSelf || $canSeeDetails;
            $username = (string)($row['username'] ?? 'Teammate');
            $item = [
                'source' => 'wfh',
                'layer' => 'wfh',
                'occurrence_date' => $row['request_date'],
                'title' => "{$username} — WFH",
                'category' => 'attendance',
                'wfh_request_id' => (int)$row['id'],
                'user_id' => $row['user_id'],
                'username' => $username,
                'status' => $row['status'],
            ];
            if ($showNote) {
                $item['description'] = $row['user_note'] ?? null;
            }
            $items[] = $item;
        }
        return $items;
    }

    /**
     * @param callable(string):bool $want
     * @return list<array>
     */
    private function personalMilestoneItems(string $from, string $to, callable $want): array
    {
        $items = [];
        $tz = new DateTimeZone('Asia/Kolkata');
        $fromDt = DateTimeImmutable::createFromFormat('Y-m-d', $from, $tz);
        $toDt = DateTimeImmutable::createFromFormat('Y-m-d', $to, $tz);
        if (!$fromDt || !$toDt) {
            return [];
        }

        // Birthdays (no year)
        if ($want('birthday')) {
            try {
                $t = $this->conn->query("SHOW TABLES LIKE 'user_onboarding_details'");
                if ($t && $t->fetch(PDO::FETCH_NUM)) {
                    $stmt = $this->conn->query(
                        "SELECT u.id, u.username, d.date_of_birth
                         FROM users u
                         INNER JOIN user_onboarding_details d ON d.user_id = u.id
                         WHERE d.date_of_birth IS NOT NULL
                         ORDER BY u.username ASC"
                    );
                    $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
                    foreach ($rows as $row) {
                        $dob = substr((string)$row['date_of_birth'], 0, 10);
                        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
                            continue;
                        }
                        $md = substr($dob, 5);
                        $cursor = $fromDt;
                        while ($cursor <= $toDt) {
                            if ($cursor->format('m-d') === $md) {
                                $items[] = [
                                    'source' => 'birthday',
                                    'layer' => 'birthday',
                                    'occurrence_date' => $cursor->format('Y-m-d'),
                                    'title' => ($row['username'] ?? 'Teammate') . ' — Birthday',
                                    'category' => 'milestone',
                                    'user_id' => $row['id'],
                                    'username' => $row['username'] ?? null,
                                ];
                            }
                            $cursor = $cursor->modify('+1 day');
                        }
                    }
                }
            } catch (Throwable $e) {
                error_log('BugDates birthdays: ' . $e->getMessage());
            }
        }

        // Work anniversaries from joining_date
        if ($want('anniversary')) {
            try {
                $hasJoining = br_users_has_joining_date($this->conn);
                if ($hasJoining) {
                    $stmt = $this->conn->query(
                        "SELECT id, username, joining_date
                         FROM users
                         WHERE joining_date IS NOT NULL
                         ORDER BY username ASC"
                    );
                    $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
                    foreach ($rows as $row) {
                        $jd = substr((string)$row['joining_date'], 0, 10);
                        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $jd)) {
                            continue;
                        }
                        $md = substr($jd, 5);
                        $joinYear = (int)substr($jd, 0, 4);
                        $cursor = $fromDt;
                        while ($cursor <= $toDt) {
                            if ($cursor->format('m-d') === $md) {
                                $years = (int)$cursor->format('Y') - $joinYear;
                                if ($years >= 1) {
                                    $items[] = [
                                        'source' => 'anniversary',
                                        'layer' => 'anniversary',
                                        'occurrence_date' => $cursor->format('Y-m-d'),
                                        'title' => ($row['username'] ?? 'Teammate')
                                            . " — {$years}y Work Anniversary",
                                        'category' => 'milestone',
                                        'user_id' => $row['id'],
                                        'username' => $row['username'] ?? null,
                                        'years' => $years,
                                    ];
                                }
                            }
                            $cursor = $cursor->modify('+1 day');
                        }
                    }
                }
            } catch (Throwable $e) {
                error_log('BugDates anniversaries: ' . $e->getMessage());
            }
        }

        return $items;
    }

    /**
     * @return list<array>
     */
    private function projectMilestoneItems(string $from, string $to): array
    {
        $fields = ['deadline_date', 'expected_publish_date'];
        $existing = [];
        try {
            $cols = $this->conn->query('SHOW COLUMNS FROM projects');
            $all = [];
            if ($cols) {
                while ($c = $cols->fetch(PDO::FETCH_ASSOC)) {
                    $all[] = $c['Field'];
                }
            }
            foreach ($fields as $f) {
                if (in_array($f, $all, true)) {
                    $existing[] = $f;
                }
            }
        } catch (Throwable $e) {
            return [];
        }
        if (empty($existing)) {
            return [];
        }

        $select = array_merge(['id', 'name'], $existing);
        $conditions = [];
        foreach ($existing as $f) {
            $conditions[] = "({$f} IS NOT NULL AND {$f} BETWEEN ? AND ?)";
        }
        $sql = 'SELECT ' . implode(', ', $select)
            . ' FROM projects WHERE ' . implode(' OR ', $conditions)
            . ' ORDER BY name ASC';
        $params = [];
        foreach ($existing as $_) {
            $params[] = $from;
            $params[] = $to;
        }
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }

        $labels = [
            'deadline_date' => 'Deadline',
            'expected_publish_date' => 'Expected Publish',
        ];
        $items = [];
        foreach ($rows as $row) {
            foreach ($existing as $f) {
                $date = substr(trim((string)($row[$f] ?? '')), 0, 10);
                if ($date === '' || $date < $from || $date > $to) {
                    continue;
                }
                $items[] = [
                    'source' => 'project_milestone',
                    'layer' => 'project_milestone',
                    'occurrence_date' => $date,
                    'title' => ($row['name'] ?? 'Project') . ' — ' . ($labels[$f] ?? $f),
                    'category' => 'milestone',
                    'project_id' => $row['id'],
                    'project_name' => $row['name'] ?? null,
                    'milestone_key' => $f,
                ];
            }
        }
        return $items;
    }

    public function listEvents()
    {
        $decoded = $this->requireAuth();
        if (!$decoded) {
            return;
        }
        $canView = $this->can($decoded, 'BUGDATES_VIEW')
            || $this->can($decoded, 'LEAVE_VIEW')
            || $this->isAdmin($decoded);
        if (!$canView) {
            $this->sendJsonResponse(403, 'Access denied');
            return;
        }
        if (!$this->ensureReady()) {
            return;
        }

        $status = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
        $category = isset($_GET['category']) ? trim((string)$_GET['category']) : '';
        $sql = "SELECT e.*, u.username AS created_by_name
                FROM bug_dates_events e
                LEFT JOIN users u ON u.id = e.created_by
                WHERE 1=1";
        $params = [];
        if ($status !== '' && in_array($status, self::STATUSES, true)) {
            $sql .= ' AND e.status = ?';
            $params[] = $status;
        }
        if ($category !== '' && in_array($category, self::CATEGORIES, true)) {
            $sql .= ' AND e.category = ?';
            $params[] = $category;
        }
        $sql .= ' ORDER BY e.start_date DESC, e.id DESC';
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            if (!$this->canSeeVisibility($decoded, (string)($row['visibility'] ?? 'company'))) {
                continue;
            }
            $out[] = $this->formatEvent($row);
        }
        $this->sendJsonResponse(200, 'OK', $out);
    }

    public function getEvent()
    {
        $decoded = $this->requireAuth();
        if (!$decoded || !$this->can($decoded, 'BUGDATES_VIEW') || !$this->ensureReady()) {
            if ($decoded && !$this->can($decoded, 'BUGDATES_VIEW')) {
                $this->sendJsonResponse(403, 'Access denied');
            }
            return;
        }
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) {
            $this->sendJsonResponse(400, 'id is required');
            return;
        }
        $row = $this->fetchEvent($id);
        if (!$row || !$this->canSeeVisibility($decoded, (string)($row['visibility'] ?? 'company'))) {
            $this->sendJsonResponse(404, 'Event not found');
            return;
        }
        $this->sendJsonResponse(200, 'OK', $this->formatEvent($row));
    }

    /**
     * @return array{ok:bool,payload?:array,error?:string}
     */
    private function parseEventPayload(array $data, bool $isUpdate = false): array
    {
        $title = $this->sanitizeText($data['title'] ?? null, 255);
        if (!$isUpdate && ($title === null || $title === '')) {
            return ['ok' => false, 'error' => 'title is required'];
        }
        $category = strtolower(trim((string)($data['category'] ?? '')));
        if (!$isUpdate && !in_array($category, self::CATEGORIES, true)) {
            return ['ok' => false, 'error' => 'Valid category is required'];
        }
        $recurrence = strtolower(trim((string)($data['recurrence_type'] ?? 'none')));
        if (!in_array($recurrence, self::RECURRENCE, true)) {
            $recurrence = 'none';
        }
        $startDate = $this->sanitizeDate($data['start_date'] ?? null);
        if (!$isUpdate && !$startDate) {
            return ['ok' => false, 'error' => 'start_date is required'];
        }
        $endDate = $this->sanitizeDate($data['end_date'] ?? null);
        $visibility = strtolower(trim((string)($data['visibility'] ?? 'company')));
        if (!in_array($visibility, self::VISIBILITY, true)) {
            $visibility = 'company';
        }
        $status = strtolower(trim((string)($data['status'] ?? 'approved')));
        if (!in_array($status, self::STATUSES, true)) {
            $status = 'approved';
        }

        $days = $data['recurrence_days'] ?? null;
        if (is_string($days)) {
            $decoded = json_decode($days, true);
            $days = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($days)) {
            $days = null;
        }
        $hooks = $data['auto_hooks'] ?? null;
        if (is_string($hooks)) {
            $decoded = json_decode($hooks, true);
            $hooks = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($hooks)) {
            $hooks = null;
        }

        return [
            'ok' => true,
            'payload' => [
                'title' => $title,
                'description' => $this->sanitizeText($data['description'] ?? null, 5000),
                'category' => $category !== '' ? $category : null,
                'recurrence_type' => $recurrence,
                'recurrence_days' => $days,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'start_time' => $this->sanitizeTime($data['start_time'] ?? null),
                'end_time' => $this->sanitizeTime($data['end_time'] ?? null),
                'location_or_link' => $this->sanitizeText($data['location_or_link'] ?? null, 2000),
                'is_office_closed' => !empty($data['is_office_closed']) ? 1 : 0,
                'auto_hooks' => $hooks,
                'visibility' => $visibility,
                'status' => $status,
            ],
        ];
    }

    public function createEvent($data = null)
    {
        $decoded = $this->requireAuth();
        if (!$decoded || !$this->ensureReady()) {
            return;
        }
        if (!$this->can($decoded, 'BUGDATES_MANAGE')) {
            $this->sendJsonResponse(403, 'Access denied');
            return;
        }
        if (!is_array($data)) {
            $data = $this->getRequestData() ?: [];
        }
        $parsed = $this->parseEventPayload($data, false);
        if (!$parsed['ok']) {
            $this->sendJsonResponse(400, $parsed['error'] ?? 'Invalid payload');
            return;
        }
        $p = $parsed['payload'];
        if (!$this->isAdmin($decoded) && $p['status'] === 'approved') {
            // Non-admin managers still create as approved when they have MANAGE
        }
        $stmt = $this->conn->prepare(
            "INSERT INTO bug_dates_events (
                title, description, category, recurrence_type, recurrence_days,
                start_date, end_date, start_time, end_time, location_or_link,
                is_office_closed, auto_hooks, visibility, status, created_by
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $p['title'],
            $p['description'],
            $p['category'],
            $p['recurrence_type'],
            $p['recurrence_days'] !== null ? json_encode($p['recurrence_days']) : null,
            $p['start_date'],
            $p['end_date'],
            $p['start_time'],
            $p['end_time'],
            $p['location_or_link'],
            $p['is_office_closed'],
            $p['auto_hooks'] !== null ? json_encode($p['auto_hooks']) : null,
            $p['visibility'],
            $p['status'],
            (string)$decoded->user_id,
        ]);
        $id = (int)$this->conn->lastInsertId();
        $row = $this->fetchEvent($id);
        $this->sendJsonResponse(201, 'Event created', $this->formatEvent($row));
    }

    public function updateEvent($data = null)
    {
        $decoded = $this->requireAuth();
        if (!$decoded || !$this->ensureReady()) {
            return;
        }
        if (!$this->can($decoded, 'BUGDATES_MANAGE')) {
            $this->sendJsonResponse(403, 'Access denied');
            return;
        }
        if (!is_array($data)) {
            $data = $this->getRequestData() ?: [];
        }
        $id = isset($data['id']) ? (int)$data['id'] : 0;
        if ($id <= 0) {
            $this->sendJsonResponse(400, 'id is required');
            return;
        }
        $existing = $this->fetchEvent($id);
        if (!$existing) {
            $this->sendJsonResponse(404, 'Event not found');
            return;
        }
        $parsed = $this->parseEventPayload($data, true);
        if (!$parsed['ok']) {
            $this->sendJsonResponse(400, $parsed['error'] ?? 'Invalid payload');
            return;
        }
        $p = $parsed['payload'];
        $title = $p['title'] ?? $existing['title'];
        $category = $p['category'] ?? $existing['category'];
        $startDate = $p['start_date'] ?? $existing['start_date'];
        $stmt = $this->conn->prepare(
            "UPDATE bug_dates_events SET
                title = ?, description = ?, category = ?, recurrence_type = ?,
                recurrence_days = ?, start_date = ?, end_date = ?, start_time = ?,
                end_time = ?, location_or_link = ?, is_office_closed = ?,
                auto_hooks = ?, visibility = ?, status = ?
             WHERE id = ?"
        );
        $stmt->execute([
            $title,
            array_key_exists('description', $data)
                ? $p['description']
                : ($existing['description'] ?? null),
            $category,
            $p['recurrence_type'],
            $p['recurrence_days'] !== null
                ? json_encode($p['recurrence_days'])
                : ($existing['recurrence_days'] ?? null),
            $startDate,
            array_key_exists('end_date', $data) ? $p['end_date'] : ($existing['end_date'] ?? null),
            array_key_exists('start_time', $data) ? $p['start_time'] : ($existing['start_time'] ?? null),
            array_key_exists('end_time', $data) ? $p['end_time'] : ($existing['end_time'] ?? null),
            array_key_exists('location_or_link', $data)
                ? $p['location_or_link']
                : ($existing['location_or_link'] ?? null),
            array_key_exists('is_office_closed', $data)
                ? $p['is_office_closed']
                : (int)$existing['is_office_closed'],
            $p['auto_hooks'] !== null
                ? json_encode($p['auto_hooks'])
                : ($existing['auto_hooks'] ?? null),
            $p['visibility'],
            array_key_exists('status', $data) ? $p['status'] : $existing['status'],
            $id,
        ]);
        $row = $this->fetchEvent($id);
        $this->sendJsonResponse(200, 'Event updated', $this->formatEvent($row));
    }

    public function deleteEvent($data = null)
    {
        $decoded = $this->requireAuth();
        if (!$decoded || !$this->ensureReady()) {
            return;
        }
        if (!$this->can($decoded, 'BUGDATES_MANAGE')) {
            $this->sendJsonResponse(403, 'Access denied');
            return;
        }
        if (!is_array($data)) {
            $data = $this->getRequestData() ?: [];
        }
        $id = isset($data['id']) ? (int)$data['id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
        if ($id <= 0) {
            $this->sendJsonResponse(400, 'id is required');
            return;
        }
        $existing = $this->fetchEvent($id);
        if (!$existing) {
            $this->sendJsonResponse(404, 'Event not found');
            return;
        }
        $stmt = $this->conn->prepare('DELETE FROM bug_dates_events WHERE id = ?');
        $stmt->execute([$id]);
        $this->sendJsonResponse(200, 'Event deleted', ['id' => $id]);
    }

    public function reviewEvent($data = null)
    {
        $decoded = $this->requireAuth();
        if (!$decoded || !$this->ensureReady()) {
            return;
        }
        if (!$this->can($decoded, 'BUGDATES_MANAGE')) {
            $this->sendJsonResponse(403, 'Access denied');
            return;
        }
        if (!is_array($data)) {
            $data = $this->getRequestData() ?: [];
        }
        $id = isset($data['id']) ? (int)$data['id'] : 0;
        $action = strtolower(trim((string)($data['action'] ?? '')));
        if ($id <= 0 || !in_array($action, ['approve', 'reject'], true)) {
            $this->sendJsonResponse(400, 'id and action (approve|reject) are required');
            return;
        }
        $status = $action === 'approve' ? 'approved' : 'rejected';
        $stmt = $this->conn->prepare('UPDATE bug_dates_events SET status = ? WHERE id = ?');
        $stmt->execute([$status, $id]);
        $row = $this->fetchEvent($id);
        if (!$row) {
            $this->sendJsonResponse(404, 'Event not found');
            return;
        }
        $this->sendJsonResponse(200, 'Event ' . $status, $this->formatEvent($row));
    }

    public function listSessions()
    {
        $decoded = $this->requireAuth();
        if (!$decoded) {
            return;
        }
        $canView = $this->can($decoded, 'BUGDATES_VIEW')
            || $this->can($decoded, 'LEAVE_VIEW')
            || $this->isAdmin($decoded);
        if (!$canView) {
            $this->sendJsonResponse(403, 'Access denied');
            return;
        }
        if (!$this->ensureReady()) {
            return;
        }
        $eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
        $from = $this->sanitizeDate($_GET['from'] ?? null);
        $to = $this->sanitizeDate($_GET['to'] ?? null);
        $sql = "SELECT s.*, u.username AS host_name, e.title AS event_title
                FROM growth_program_sessions s
                LEFT JOIN users u ON u.id = s.host_user_id
                LEFT JOIN bug_dates_events e ON e.id = s.event_id
                WHERE 1=1";
        $params = [];
        if ($eventId > 0) {
            $sql .= ' AND s.event_id = ?';
            $params[] = $eventId;
        }
        if ($from) {
            $sql .= ' AND s.session_date >= ?';
            $params[] = $from;
        }
        if ($to) {
            $sql .= ' AND s.session_date <= ?';
            $params[] = $to;
        }
        $sql .= ' ORDER BY s.session_date DESC, s.id DESC';
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = array_map(static function (array $row) {
            return [
                'id' => (int)$row['id'],
                'event_id' => (int)$row['event_id'],
                'event_title' => $row['event_title'] ?? null,
                'session_date' => $row['session_date'],
                'host_user_id' => $row['host_user_id'] ?? null,
                'host_name' => $row['host_name'] ?? null,
                'agenda_topic' => $row['agenda_topic'] ?? null,
                'summary_notes' => $row['summary_notes'] ?? null,
                'recording_or_drive_link' => $row['recording_or_drive_link'] ?? null,
                'weekly_report_task_id' => $row['weekly_report_task_id'] ?? null,
                'created_at' => $row['created_at'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
            ];
        }, $rows);
        $this->sendJsonResponse(200, 'OK', $out);
    }

    public function saveSession($data = null)
    {
        $decoded = $this->requireAuth();
        if (!$decoded || !$this->ensureReady()) {
            return;
        }
        if (!$this->can($decoded, 'BUGDATES_MANAGE') && !$this->can($decoded, 'BUGDATES_VIEW')) {
            $this->sendJsonResponse(403, 'Access denied');
            return;
        }
        if (!is_array($data)) {
            $data = $this->getRequestData() ?: [];
        }
        $eventId = isset($data['event_id']) ? (int)$data['event_id'] : 0;
        $sessionDate = $this->sanitizeDate($data['session_date'] ?? null);
        if ($eventId <= 0 || !$sessionDate) {
            $this->sendJsonResponse(400, 'event_id and session_date are required');
            return;
        }
        $event = $this->fetchEvent($eventId);
        if (!$event || ($event['category'] ?? '') !== 'growth_program') {
            $this->sendJsonResponse(400, 'event_id must reference a growth_program event');
            return;
        }

        $host = $this->sanitizeText($data['host_user_id'] ?? null, 36);
        $agenda = $this->sanitizeText($data['agenda_topic'] ?? null, 255);
        $notes = $this->sanitizeText($data['summary_notes'] ?? null, 10000);
        $link = $this->sanitizeText($data['recording_or_drive_link'] ?? null, 2000);

        $find = $this->conn->prepare(
            'SELECT id FROM growth_program_sessions WHERE event_id = ? AND session_date = ? LIMIT 1'
        );
        $find->execute([$eventId, $sessionDate]);
        $existingId = $find->fetchColumn();

        if ($existingId) {
            $stmt = $this->conn->prepare(
                "UPDATE growth_program_sessions SET
                    host_user_id = ?, agenda_topic = ?, summary_notes = ?,
                    recording_or_drive_link = ?
                 WHERE id = ?"
            );
            $stmt->execute([$host, $agenda, $notes, $link, (int)$existingId]);
            $sessionId = (int)$existingId;
        } else {
            $stmt = $this->conn->prepare(
                "INSERT INTO growth_program_sessions
                 (event_id, session_date, host_user_id, agenda_topic, summary_notes, recording_or_drive_link)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$eventId, $sessionDate, $host, $agenda, $notes, $link]);
            $sessionId = (int)$this->conn->lastInsertId();
        }

        // Auto weekly report todo for Saturday-style programs when requested
        $autoTodo = !empty($data['generate_todo']);
        $hooks = $this->decodeJsonField($event['auto_hooks'] ?? null) ?: [];
        if ($autoTodo || (!empty($hooks['todo']) && $hooks['todo'] === 'weekly_report')) {
            $this->maybeCreateSessionTodo(
                $decoded,
                $event,
                $sessionDate,
                $sessionId,
                (string)($hooks['todo'] ?? 'weekly_report')
            );
        }

        $stmt = $this->conn->prepare(
            "SELECT s.*, u.username AS host_name, e.title AS event_title
             FROM growth_program_sessions s
             LEFT JOIN users u ON u.id = s.host_user_id
             LEFT JOIN bug_dates_events e ON e.id = s.event_id
             WHERE s.id = ?"
        );
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->sendJsonResponse(200, 'Session saved', [
            'id' => (int)$row['id'],
            'event_id' => (int)$row['event_id'],
            'event_title' => $row['event_title'] ?? null,
            'session_date' => $row['session_date'],
            'host_user_id' => $row['host_user_id'] ?? null,
            'host_name' => $row['host_name'] ?? null,
            'agenda_topic' => $row['agenda_topic'] ?? null,
            'summary_notes' => $row['summary_notes'] ?? null,
            'recording_or_drive_link' => $row['recording_or_drive_link'] ?? null,
            'weekly_report_task_id' => $row['weekly_report_task_id'] ?? null,
        ]);
    }

    public function generateCreative($data = null)
    {
        $decoded = $this->requireAuth();
        if (!$decoded || !$this->ensureReady()) {
            return;
        }
        if (!$this->can($decoded, 'CREATIVE_CREATE') && !$this->can($decoded, 'BUGDATES_MANAGE')) {
            $this->sendJsonResponse(403, 'Access denied — need CREATIVE_CREATE or BUGDATES_MANAGE');
            return;
        }
        if (!is_array($data)) {
            $data = $this->getRequestData() ?: [];
        }
        $eventId = isset($data['event_id']) ? (int)$data['event_id'] : 0;
        $occurrence = $this->sanitizeDate($data['occurrence_date'] ?? null);
        if ($eventId <= 0 || !$occurrence) {
            $this->sendJsonResponse(400, 'event_id and occurrence_date are required');
            return;
        }
        $event = $this->fetchEvent($eventId);
        if (!$event) {
            $this->sendJsonResponse(404, 'Event not found');
            return;
        }

        // Dedupe
        $existing = $this->findHook($eventId, $occurrence, 'creative_card');
        if ($existing) {
            $this->sendJsonResponse(200, 'Creative card already exists', [
                'hook' => $existing,
                'asset_id' => $existing['target_id'] ?? null,
                'already_exists' => true,
            ]);
            return;
        }

        try {
            $this->conn->query('SELECT 1 FROM creative_assets LIMIT 1');
        } catch (Throwable $e) {
            $this->sendJsonResponse(503, 'BugCreative is not set up');
            return;
        }

        $assetId = Utils::generateUUID();
        $title = $this->sanitizeText(
            ($data['title'] ?? null) ?: ($event['title'] . ' — Creative Card'),
            255
        );
        $hookContent = $this->sanitizeText(
            ($data['hook_content'] ?? null)
                ?: ('Campaign creative for ' . $event['title'] . ' on ' . $occurrence),
            5000
        );
        $material = $this->sanitizeText($data['material_type'] ?? 'Poster', 32) ?: 'Poster';
        $allowedMaterial = [
            'Poster', 'Reel', 'Carousel', 'Mockup Web', 'Mockup App',
            'Tips', 'Document', 'Logo', 'Brochure', 'Other',
        ];
        if (!in_array($material, $allowedMaterial, true)) {
            $material = 'Poster';
        }

        $stmt = $this->conn->prepare(
            "INSERT INTO creative_assets (
                id, project_id, creator_id, title, material_type, platform,
                hook_content, asset_source, drive_link, uploaded_file_path,
                preview_thumbnail_url, status, scheduled_date, published_date
             ) VALUES (?, NULL, ?, ?, ?, 'Insta', ?, 'link', NULL, NULL, NULL, 'Draft', ?, NULL)"
        );
        $stmt->execute([
            $assetId,
            (string)$decoded->user_id,
            $title,
            $material,
            $hookContent,
            $occurrence,
        ]);

        $this->recordHook(
            $eventId,
            $occurrence,
            'creative_card',
            'creative_assets',
            $assetId,
            (string)$decoded->user_id
        );

        $this->sendJsonResponse(201, 'Creative card queued', [
            'asset_id' => $assetId,
            'title' => $title,
            'scheduled_date' => $occurrence,
            'status' => 'Draft',
            'already_exists' => false,
        ]);
    }

    public function generateTodo($data = null)
    {
        $decoded = $this->requireAuth();
        if (!$decoded || !$this->ensureReady()) {
            return;
        }
        if (!$this->can($decoded, 'BUGDATES_MANAGE') && !$this->can($decoded, 'TASKS_CREATE')) {
            $this->sendJsonResponse(403, 'Access denied');
            return;
        }
        if (!is_array($data)) {
            $data = $this->getRequestData() ?: [];
        }
        $eventId = isset($data['event_id']) ? (int)$data['event_id'] : 0;
        $occurrence = $this->sanitizeDate($data['occurrence_date'] ?? null);
        if ($eventId <= 0 || !$occurrence) {
            $this->sendJsonResponse(400, 'event_id and occurrence_date are required');
            return;
        }
        $event = $this->fetchEvent($eventId);
        if (!$event) {
            $this->sendJsonResponse(404, 'Event not found');
            return;
        }

        $existing = $this->findHook($eventId, $occurrence, 'shared_task');
        if ($existing) {
            $this->sendJsonResponse(200, 'Shared task already exists', [
                'hook' => $existing,
                'task_id' => $existing['target_id'] ?? null,
                'already_exists' => true,
            ]);
            return;
        }

        $taskId = $this->createSharedTaskForEvent($decoded, $event, $occurrence, $data);
        if ($taskId === null) {
            return;
        }

        $this->recordHook(
            $eventId,
            $occurrence,
            'shared_task',
            'shared_tasks',
            (string)$taskId,
            (string)$decoded->user_id
        );

        $this->sendJsonResponse(201, 'Shared task created', [
            'task_id' => $taskId,
            'already_exists' => false,
        ]);
    }

    private function maybeCreateSessionTodo(
        object $decoded,
        array $event,
        string $sessionDate,
        int $sessionId,
        string $todoKind
    ): void {
        $existing = $this->findHook((int)$event['id'], $sessionDate, 'shared_task');
        if ($existing) {
            if (!empty($existing['target_id'])) {
                $this->conn->prepare(
                    'UPDATE growth_program_sessions SET weekly_report_task_id = ? WHERE id = ?'
                )->execute([(string)$existing['target_id'], $sessionId]);
            }
            return;
        }
        $title = $todoKind === 'weekly_report'
            ? 'Weekly Growth Glimpse report / asset wrap-up — ' . $sessionDate
            : 'Growth Glimpse session follow-up — ' . $sessionDate;
        $taskId = $this->createSharedTaskForEvent($decoded, $event, $sessionDate, [
            'title' => $title,
            'description' => 'Auto-generated from BugDates growth program session.',
        ]);
        if ($taskId === null) {
            return;
        }
        $this->recordHook(
            (int)$event['id'],
            $sessionDate,
            'shared_task',
            'shared_tasks',
            (string)$taskId,
            (string)$decoded->user_id
        );
        $this->conn->prepare(
            'UPDATE growth_program_sessions SET weekly_report_task_id = ? WHERE id = ?'
        )->execute([(string)$taskId, $sessionId]);
    }

    /**
     * @return int|string|null
     */
    private function createSharedTaskForEvent(
        object $decoded,
        array $event,
        string $occurrence,
        array $data,
        bool $silent = false
    ) {
        try {
            $this->conn->query('SELECT 1 FROM shared_tasks LIMIT 1');
        } catch (Throwable $e) {
            if (!$silent) {
                $this->sendJsonResponse(503, 'BugToDo shared tasks are not set up');
            }
            return null;
        }

        $assignee = $this->sanitizeText($data['assigned_to'] ?? null, 36)
            ?: (string)$decoded->user_id;
        $title = $this->sanitizeText(
            ($data['title'] ?? null) ?: ($event['title'] . ' — ' . $occurrence),
            255
        );
        $description = $this->sanitizeText(
            ($data['description'] ?? null)
                ?: ('Auto-generated from BugDates: ' . ($event['title'] ?? '')),
            5000
        );

        $stmt = $this->conn->prepare(
            "INSERT INTO shared_tasks (
                title, description, created_by, assigned_to,
                project_id, due_date, status, priority
             ) VALUES (?, ?, ?, ?, NULL, ?, 'pending', 'medium')"
        );
        $stmt->execute([
            $title,
            $description,
            (string)$decoded->user_id,
            $assignee,
            $occurrence,
        ]);
        $taskId = $this->conn->lastInsertId();

        try {
            $this->conn->prepare(
                'INSERT INTO shared_task_assignees (shared_task_id, assigned_to) VALUES (?, ?)'
            )->execute([$taskId, $assignee]);
        } catch (Throwable $e) {
            // table may not exist on older deploys
        }

        return $taskId;
    }

    private function findHook(int $eventId, string $occurrence, string $hookType): ?array
    {
        try {
            $stmt = $this->conn->prepare(
                "SELECT * FROM bug_dates_hooks
                 WHERE event_id = ? AND occurrence_date = ? AND hook_type = ?
                 LIMIT 1"
            );
            $stmt->execute([$eventId, $occurrence, $hookType]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function recordHook(
        int $eventId,
        string $occurrence,
        string $hookType,
        ?string $targetTable,
        ?string $targetId,
        ?string $createdBy
    ): void {
        try {
            $stmt = $this->conn->prepare(
                "INSERT INTO bug_dates_hooks
                 (event_id, occurrence_date, hook_type, target_table, target_id, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   target_table = VALUES(target_table),
                   target_id = VALUES(target_id)"
            );
            $stmt->execute([
                $eventId,
                $occurrence,
                $hookType,
                $targetTable,
                $targetId,
                $createdBy,
            ]);
        } catch (Throwable $e) {
            error_log('BugDates recordHook: ' . $e->getMessage());
        }
    }

    /**
     * Cron / CLI: auto-generate hooks for today's observances & Saturday programs.
     */
    public function runHooks(?string $forDate = null)
    {
        if (!$this->ensureReady()) {
            return;
        }
        $tz = new DateTimeZone('Asia/Kolkata');
        $date = $forDate ?: (new DateTime('now', $tz))->format('Y-m-d');
        $date = $this->sanitizeDate($date);
        if (!$date) {
            $this->sendJsonResponse(400, 'Invalid date');
            return;
        }

        $stmt = $this->conn->query(
            "SELECT e.*, u.username AS created_by_name
             FROM bug_dates_events e
             LEFT JOIN users u ON u.id = e.created_by
             WHERE e.status = 'approved'"
        );
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        $created = ['creative' => 0, 'todo' => 0, 'skipped' => 0];

        // Use first admin as system actor
        $adminStmt = $this->conn->query(
            "SELECT id FROM users WHERE role = 'admin' ORDER BY created_at ASC LIMIT 1"
        );
        $adminId = $adminStmt ? $adminStmt->fetchColumn() : null;
        if (!$adminId) {
            $this->sendJsonResponse(500, 'No admin user for system hooks');
            return;
        }
        $actor = (object)['user_id' => $adminId, 'role' => 'admin'];

        foreach ($rows as $row) {
            $hits = br_bug_dates_expand_occurrences($row, $date, $date);
            if (empty($hits)) {
                continue;
            }
            $hooks = $this->decodeJsonField($row['auto_hooks'] ?? null) ?: [];
            if (!empty($hooks['creative'])) {
                if ($this->findHook((int)$row['id'], $date, 'creative_card')) {
                    $created['skipped']++;
                } else {
                    $assetId = Utils::generateUUID();
                    try {
                        $ins = $this->conn->prepare(
                            "INSERT INTO creative_assets (
                                id, project_id, creator_id, title, material_type, platform,
                                hook_content, asset_source, status, scheduled_date
                             ) VALUES (?, NULL, ?, ?, 'Poster', 'Insta', ?, 'link', 'Draft', ?)"
                        );
                        $ins->execute([
                            $assetId,
                            $adminId,
                            $row['title'] . ' — Creative Card',
                            'Auto-queued from BugDates for ' . $date,
                            $date,
                        ]);
                        $this->recordHook(
                            (int)$row['id'],
                            $date,
                            'creative_card',
                            'creative_assets',
                            $assetId,
                            $adminId
                        );
                        $created['creative']++;
                    } catch (Throwable $e) {
                        error_log('runHooks creative: ' . $e->getMessage());
                    }
                }
            }
            if (!empty($hooks['todo'])) {
                if ($this->findHook((int)$row['id'], $date, 'shared_task')) {
                    $created['skipped']++;
                } else {
                    $taskId = $this->createSharedTaskForEvent($actor, $row, $date, [
                        'title' => $row['title'] . ' — follow-up ' . $date,
                    ], true);
                    if ($taskId !== null) {
                        $this->recordHook(
                            (int)$row['id'],
                            $date,
                            'shared_task',
                            'shared_tasks',
                            (string)$taskId,
                            $adminId
                        );
                        $created['todo']++;
                    }
                }
            }
        }

        $this->sendJsonResponse(200, 'Hooks processed', [
            'date' => $date,
            'result' => $created,
        ]);
    }
}
