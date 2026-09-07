<?php
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../PermissionManager.php';
require_once __DIR__ . '/../../utils/work_submission_ot.php';
require_once __DIR__ . '/../../utils/leave_attendance.php';

class UserWorkStatsController extends BaseAPI {
    private function splitTaskLines($text) {
        $raw = explode("\n", (string)$text);
        $cleaned = [];
        $skipOvertimeReasonBlock = false;
        foreach ($raw as $line) {
            $line = trim($line);
            if ($line === '') continue;

            if (stripos($line, '[OVERTIME APPROVAL REQUEST]') === 0) {
                $skipOvertimeReasonBlock = true;
                continue;
            }
            if ($skipOvertimeReasonBlock && preg_match('/^(Requested Extra Hours:|Reason:)/i', $line)) {
                continue;
            }
            if (preg_match('/^\[BREAK\]/i', $line)) {
                continue;
            }
            $skipOvertimeReasonBlock = false;
            $cleaned[] = $line;
        }
        return $cleaned;
    }

    private function parseBreakEntries($breakEntriesRaw, $notesRaw) {
        $entries = [];
        if (is_string($breakEntriesRaw) && trim($breakEntriesRaw) !== '') {
            $decoded = json_decode($breakEntriesRaw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $entry) {
                    $entry = trim((string)$entry);
                    if ($entry !== '') $entries[] = $entry;
                }
            }
        }
        if (empty($entries)) {
            $noteMatches = [];
            if (preg_match_all('/^\[BREAK\].*$/im', (string)$notesRaw, $noteMatches)) {
                foreach (($noteMatches[0] ?? []) as $line) {
                    $line = trim((string)$line);
                    if ($line !== '') $entries[] = $line;
                }
            }
        }
        return array_values(array_unique($entries));
    }

    private function getBreakMinutesFromEntries($entries) {
        $total = 0;
        if (!is_array($entries)) return 0;
        foreach ($entries as $entry) {
            if (preg_match('/\((\d+)\s*min\)/i', (string)$entry, $matches)) {
                $total += (int)$matches[1];
            }
        }
        return $total;
    }

    private function parsePlannedProjectIds($raw) {
        if ($raw === null || $raw === '') return [];
        if (is_array($raw)) {
            return array_values(array_filter($raw, function ($value) {
                return $value !== null && $value !== '';
            }));
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return array_values(array_filter($decoded, function ($value) {
                    return $value !== null && $value !== '';
                }));
            }
            $trimmed = trim($raw);
            return $trimmed !== '' ? [$trimmed] : [];
        }
        return [];
    }

    private function fetchProjectNameMap(array $ids) {
        $ids = array_values(array_unique(array_filter($ids, function ($id) {
            return $id !== null && $id !== '';
        })));
        if (empty($ids)) return [];

        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->conn->prepare("SELECT id, name FROM projects WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $map = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $id = (string)$row['id'];
                $name = (string)$row['name'];
                $map[$id] = $name;
            }
            return $map;
        } catch (Exception $e) {
            error_log('UserWorkStatsController::fetchProjectNameMap error: ' . $e->getMessage());
            return [];
        }
    }

    private function buildProjectNameMapFromSubmissions(array $submissions) {
        $ids = [];
        foreach ($submissions as $submission) {
            foreach ($this->parsePlannedProjectIds($submission['planned_projects'] ?? null) as $id) {
                $ids[(string)$id] = true;
            }
            $updates = $this->parseProjectUpdates($submission['project_updates'] ?? null);
            foreach ($updates as $update) {
                if (!empty($update['project_id'])) {
                    $ids[(string)$update['project_id']] = true;
                }
            }
        }
        return $this->fetchProjectNameMap(array_keys($ids));
    }

    private function lookupProjectName($id, array $projectNameMap) {
        $key = (string)$id;
        if (isset($projectNameMap[$key])) {
            return $projectNameMap[$key];
        }
        foreach ($projectNameMap as $projectId => $name) {
            if (strcasecmp((string)$projectId, $key) === 0) {
                return (string)$name;
            }
        }
        return $key;
    }

    private function resolveProjectNames($raw, array $projectNameMap) {
        $names = [];
        foreach ($this->parsePlannedProjectIds($raw) as $id) {
            $resolved = $this->lookupProjectName($id, $projectNameMap);
            if ($resolved === '' || $resolved === null) {
                continue;
            }
            if (
                preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $resolved) &&
                strcasecmp($resolved, (string)$id) === 0
            ) {
                continue;
            }
            $names[] = $resolved;
        }
        return array_values(array_unique($names));
    }

    private function parseProjectUpdates($raw, array $projectNameMap = []) {
        if ($raw === null || $raw === '') {
            return [];
        }
        $items = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item) || empty($item['project_id'])) {
                continue;
            }
            $projectId = (string)$item['project_id'];
            $out[] = [
                'project_id' => $projectId,
                'project_name' => $this->lookupProjectName($projectId, $projectNameMap),
                'status' => (string)($item['status'] ?? 'not_started'),
                'progress_percentage' => max(0, min(100, (int)($item['progress_percentage'] ?? 0))),
                'notes' => trim((string)($item['notes'] ?? '')),
            ];
        }
        return $out;
    }

    private function getCalendarMonthPeriodAtOffset(int $monthsAgo, DateTimeZone $istTimezone): array {
        $anchor = new DateTime('now', $istTimezone);
        $periodStartDate = new DateTime($anchor->format('Y-m-01'), $istTimezone);
        if ($monthsAgo > 0) {
            $periodStartDate->modify("-{$monthsAgo} months");
        }
        $periodEndDate = clone $periodStartDate;
        $periodEndDate->modify('last day of this month');

        return [
            'start' => $periodStartDate->format('Y-m-d'),
            'end' => $periodEndDate->format('Y-m-d'),
            'name' => $this->formatCalendarMonthName($periodStartDate, $periodEndDate),
            'range' => $this->formatCalendarMonthRange($periodStartDate, $periodEndDate),
        ];
    }

    /** Calendar month for a YYYY-MM key. */
    private function getCalendarMonthPeriodForKey(string $ym, DateTimeZone $istTimezone): array {
        $periodStartDate = new DateTime($ym . '-01', $istTimezone);
        $periodEndDate = clone $periodStartDate;
        $periodEndDate->modify('last day of this month');

        return [
            'start' => $periodStartDate->format('Y-m-d'),
            'end' => $periodEndDate->format('Y-m-d'),
            'name' => $this->formatCalendarMonthName($periodStartDate, $periodEndDate),
            'range' => $this->formatCalendarMonthRange($periodStartDate, $periodEndDate),
        ];
    }

    /**
     * Resolve analytics primary period + lookback from month / months query params.
     * - month=YYYY-MM → that calendar month as primary
     * - month=all → primary spans the full lookback window (default 12 months)
     * - omitted → current calendar month (existing behavior)
     */
    private function resolveAnalyticsPeriods(DateTimeZone $istTimezone): array {
        $monthParam = isset($_GET['month']) ? trim((string)$_GET['month']) : '';
        $monthsRequested = isset($_GET['months']);
        $lookbackMonths = $monthsRequested
            ? max(1, min(12, (int)$_GET['months']))
            : ($monthParam === 'all' ? 12 : 3);

        if ($monthParam === 'all') {
            $endPeriod = $this->getCalendarMonthPeriodAtOffset(0, $istTimezone);
            $startPeriod = $this->getCalendarMonthPeriodAtOffset($lookbackMonths - 1, $istTimezone);
            $startDate = new DateTime($startPeriod['start'], $istTimezone);
            $endDate = new DateTime($endPeriod['end'], $istTimezone);
            $currentPeriod = [
                'start' => $startPeriod['start'],
                'end' => $endPeriod['end'],
                'name' => 'All months',
                'range' => $this->formatCalendarMonthRange($startDate, $endDate),
            ];
            return [
                'current' => $currentPeriod,
                'lookback_months' => $lookbackMonths,
                'lookback_start' => $startPeriod['start'],
                'month' => 'all',
            ];
        }

        if (preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
            $currentPeriod = $this->getCalendarMonthPeriodForKey($monthParam, $istTimezone);
            $anchor = new DateTime($monthParam . '-01', $istTimezone);
            $lookbackStartDate = clone $anchor;
            if ($lookbackMonths > 1) {
                $lookbackStartDate->modify('-' . ($lookbackMonths - 1) . ' months');
            }
            return [
                'current' => $currentPeriod,
                'lookback_months' => $lookbackMonths,
                'lookback_start' => $lookbackStartDate->format('Y-m-d'),
                'month' => $monthParam,
            ];
        }

        $currentPeriod = $this->getCalendarMonthPeriodAtOffset(0, $istTimezone);
        $lookbackPeriod = $this->getCalendarMonthPeriodAtOffset($lookbackMonths - 1, $istTimezone);
        return [
            'current' => $currentPeriod,
            'lookback_months' => $lookbackMonths,
            'lookback_start' => $lookbackPeriod['start'],
            'month' => $currentPeriod['start'] ? substr($currentPeriod['start'], 0, 7) : '',
        ];
    }

    private function formatCalendarMonthName(DateTime $start, DateTime $end): string {
        if ($start->format('Y-m') === $end->format('Y-m')) {
            return $start->format('F Y');
        }

        return $start->format('M d') . ' – ' . $end->format('M d, Y');
    }

    private function formatCalendarMonthRange(DateTime $start, DateTime $end): string {
        return $start->format('M d') . ' – ' . $end->format('M d');
    }

    private function buildDailySubmissionEntry(array $submission, array $projectNameMap = []) {
        $date = $submission['submission_date'] ?? null;
        $completedLines = $this->splitTaskLines($submission['completed_tasks'] ?? '');
        $pendingLines = $this->splitTaskLines($submission['pending_tasks'] ?? '');
        $ongoingLines = $this->splitTaskLines($submission['ongoing_tasks'] ?? '');
        $upcomingLines = $this->splitTaskLines($submission['notes'] ?? '');

        $breakEntries = $this->parseBreakEntries($submission['break_entries'] ?? null, $submission['notes'] ?? '');
        $breakMinutes = (int)($submission['total_break_minutes'] ?? 0);
        if ($breakMinutes <= 0) {
            $breakMinutes = $this->getBreakMinutesFromEntries($breakEntries);
        }

        $projectIds = $this->parsePlannedProjectIds($submission['planned_projects'] ?? null);
        $projectNames = $this->resolveProjectNames($submission['planned_projects'] ?? null, $projectNameMap);

        return [
            'id' => isset($submission['id']) ? (int)$submission['id'] : null,
            'date' => $date,
            'submission_date' => $date,
            'user_id' => $submission['user_id'] ?? null,
            'username' => $submission['username'] ?? null,
            'role' => $submission['role'] ?? null,
            'created_at' => $submission['created_at'] ?? null,
            'updated_at' => $submission['updated_at'] ?? null,
            'submitted_at' => $submission['updated_at'] ?? $submission['created_at'] ?? null,
            'check_in_time' => $submission['check_in_time'] ?? null,
            'hours' => (float)($submission['hours_today'] ?? 0),
            'hours_today' => (float)($submission['hours_today'] ?? 0),
            'overtime_hours' => br_effective_overtime_hours_for_stats($submission),
            'requested_extra_hours' => (float)($submission['requested_extra_hours'] ?? 0),
            'approval_reason' => $submission['approval_reason'] ?? null,
            'extra_hours_approval_status' => $submission['extra_hours_approval_status'] ?? null,
            'extra_hours_approved_amount' => isset($submission['extra_hours_approved_amount'])
                ? (float)$submission['extra_hours_approved_amount']
                : null,
            'break_minutes' => $breakMinutes,
            'break_entries' => $breakEntries,
            'start_time' => $submission['start_time'] ?? null,
            'completed_count' => count($completedLines),
            'pending_count' => count($pendingLines),
            'ongoing_count' => count($ongoingLines),
            'upcoming_count' => count($upcomingLines),
            'completed_tasks' => $submission['completed_tasks'] ?? '',
            'pending_tasks' => $submission['pending_tasks'] ?? '',
            'ongoing_tasks' => $submission['ongoing_tasks'] ?? '',
            'upcoming_tasks' => $submission['notes'] ?? '',
            'planned_work' => $submission['planned_work'] ?? null,
            'planned_work_status' => $submission['planned_work_status'] ?? null,
            'planned_work_notes' => $submission['planned_work_notes'] ?? null,
            'planned_projects' => $projectIds,
            'project_names' => $projectNames,
            'project_updates' => $this->parseProjectUpdates($submission['project_updates'] ?? null, $projectNameMap),
            'day_status' => $submission['day_status'] ?? 'worked',
            'leave_type_code' => $submission['leave_type_code'] ?? null,
            'leave_type_name' => $submission['leave_type_name'] ?? null,
            'leave_request_id' => isset($submission['leave_request_id']) ? (int)$submission['leave_request_id'] : null,
            'leave_reason' => $submission['leave_reason'] ?? null,
            'tasks' => [
                'completed' => $completedLines,
                'pending' => $pendingLines,
                'ongoing' => $ongoingLines,
                'upcoming' => $upcomingLines,
            ],
        ];
    }

    private function assertCanViewTeamPeriodDetails($decoded): void {
        $currentUserId = $decoded->user_id;
        $pm = PermissionManager::getInstance();
        if (
            !$pm->hasPermission($currentUserId, 'SUPER_ADMIN') &&
            !$pm->hasPermission($currentUserId, 'USERS_VIEW')
        ) {
            $this->sendJsonResponse(403, 'Access denied');
            exit;
        }
    }

    private function ensureWorkSubmissionOtApprovalColumns() {
        $alters = [
            ['extra_hours_approval_status', "ALTER TABLE work_submissions ADD COLUMN extra_hours_approval_status VARCHAR(24) NOT NULL DEFAULT 'none' AFTER approval_reason"],
            ['extra_hours_approved_amount', 'ALTER TABLE work_submissions ADD COLUMN extra_hours_approved_amount DECIMAL(6,2) NULL DEFAULT NULL AFTER extra_hours_approval_status'],
            ['extra_hours_reviewed_by', 'ALTER TABLE work_submissions ADD COLUMN extra_hours_reviewed_by INT UNSIGNED NULL DEFAULT NULL AFTER extra_hours_approved_amount'],
            ['extra_hours_reviewed_at', 'ALTER TABLE work_submissions ADD COLUMN extra_hours_reviewed_at DATETIME NULL DEFAULT NULL AFTER extra_hours_reviewed_by'],
            ['extra_hours_admin_note', 'ALTER TABLE work_submissions ADD COLUMN extra_hours_admin_note TEXT NULL DEFAULT NULL AFTER extra_hours_reviewed_at'],
        ];
        foreach ($alters as $pair) {
            try {
                $check = $this->conn->query("SHOW COLUMNS FROM work_submissions LIKE '" . $pair[0] . "'");
                if ($check->rowCount() === 0) {
                    $this->conn->exec($pair[1]);
                }
            } catch (Exception $e) {
                // ignore
            }
        }
    }

    private function wsLiveAnd(string $alias = ''): string
    {
        return br_work_submission_live_and($this->conn, $alias);
    }

    public function getUserWorkStats($userId) {
        try {
            // Validate token
            $decoded = $this->validateToken();
            if (!$decoded || !isset($decoded->user_id)) {
                $this->sendJsonResponse(401, 'Invalid token or user_id missing');
                return;
            }

            // Check if user has permission to view stats
            $currentUserId = $decoded->user_id;
            
            // Allow users to view their own stats, or SUPER_ADMIN/USERS_VIEW to view others
            if ($currentUserId !== $userId) {
                $pm = PermissionManager::getInstance();
                
                // Check for SUPER_ADMIN or USERS_VIEW permission
                if (!$pm->hasPermission($currentUserId, 'SUPER_ADMIN') && 
                    !$pm->hasPermission($currentUserId, 'USERS_VIEW')) {
                    $this->sendJsonResponse(403, 'Access denied');
                    return;
                }
            }

            $this->ensureWorkSubmissionOtApprovalColumns();

            $wantFull = isset($_GET['full']) && in_array(strtolower((string)$_GET['full']), ['1', 'true', 'yes', 'all'], true);
            $requestedMonths = isset($_GET['months']) ? (int)$_GET['months'] : 0;

            // Current calendar month (1st through last day)
            $istTimezone = new DateTimeZone('Asia/Kolkata');
            $currentPeriod = $this->getCalendarMonthPeriodAtOffset(0, $istTimezone);
            $periodStart = $currentPeriod['start'];
            $periodEnd = $currentPeriod['end'];
            $periodName = $currentPeriod['name'];
            $periodRange = $currentPeriod['range'];

            $joiningDate = br_user_joining_date($this->conn, (string)$userId);
            $monthsFromJoin = 6;
            if ($joiningDate) {
                try {
                    $joinAnchor = new DateTime(substr($joiningDate, 0, 7) . '-01', $istTimezone);
                    $nowAnchor = new DateTime('now', $istTimezone);
                    $nowAnchor->modify('first day of this month');
                    $diff = $joinAnchor->diff($nowAnchor);
                    $monthsFromJoin = max(1, ((int)$diff->y * 12) + (int)$diff->m + 1);
                } catch (Throwable $e) {
                    $monthsFromJoin = 6;
                }
            }
            // Safety cap (5 years)
            $monthsFromJoin = min(60, $monthsFromJoin);

            if ($wantFull) {
                $trendMonthCount = $monthsFromJoin;
            } elseif ($requestedMonths > 0) {
                $trendMonthCount = min(60, max(1, $requestedMonths));
            } else {
                $trendMonthCount = 6;
            }
            
            // Get work submissions for the current custom period
            $wsLive = $this->wsLiveAnd();
            $stmt = $this->conn->prepare("
                SELECT 
                    submission_date,
                    hours_today,
                    start_time
                FROM work_submissions 
                WHERE user_id = ? 
                AND submission_date >= ? 
                AND submission_date <= ?
                {$wsLive}
                ORDER BY submission_date DESC
            ");
            
            $stmt->execute([$userId, $periodStart, $periodEnd]);
            $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calculate statistics (include paid leave as 8h workdays)
            $submissionHoursByDate = [];
            foreach ($submissions as $submission) {
                $d = (string)($submission['submission_date'] ?? '');
                if ($d === '') {
                    continue;
                }
                $submissionHoursByDate[$d] = (float)($submission['hours_today'] ?? 0);
            }
            $leaveMapCurrent = br_leave_day_map($this->conn, (string)$userId, (string)$periodStart, (string)$periodEnd);
            $leaveTotals = br_apply_leave_to_work_totals($submissionHoursByDate, $leaveMapCurrent);
            $totalHours = $leaveTotals['hours'];
            $totalDays = $leaveTotals['days'];
            $monthName = $periodName;
            
            // Get task counts from work_submissions table for current period
            $taskStmt = $this->conn->prepare("
                SELECT 
                    completed_tasks,
                    pending_tasks,
                    ongoing_tasks,
                    notes,
                    overtime_hours,
                    requested_extra_hours,
                    approval_reason,
                    extra_hours_approval_status,
                    extra_hours_approved_amount,
                    break_entries,
                    total_break_minutes
                FROM work_submissions 
                WHERE user_id = ? 
                AND submission_date >= ? 
                AND submission_date <= ?
                {$wsLive}
            ");
            $taskStmt->execute([$userId, $periodStart, $periodEnd]);
            $submissionTasks = $taskStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Count tasks using the same logic as frontend countItems function
            $completed = 0;
            $pending = 0;
            $ongoing = 0;
            $upcoming = 0;
            $overtimeHours = 0.0;
            $requestedExtraHours = 0.0;
            $approvalRequests = 0;
            $breakMinutes = 0;
            
            foreach ($submissionTasks as $submission) {
                // Count completed tasks (non-empty lines)
                $completedLines = $this->splitTaskLines($submission['completed_tasks'] ?? '');
                $completed += count($completedLines);
                
                // Count pending tasks (non-empty lines)
                $pendingLines = $this->splitTaskLines($submission['pending_tasks'] ?? '');
                $pending += count($pendingLines);
                
                // Count ongoing tasks (non-empty lines)
                $ongoingLines = $this->splitTaskLines($submission['ongoing_tasks'] ?? '');
                $ongoing += count($ongoingLines);
                
                // Count upcoming tasks (notes field, non-empty lines)
                $upcomingLines = $this->splitTaskLines($submission['notes'] ?? '');
                $upcoming += count($upcomingLines);

                $overtimeHours += br_effective_overtime_hours_for_stats($submission);
                $requested = (float)($submission['requested_extra_hours'] ?? 0);
                $requestedExtraHours += $requested;
                if ($requested > 0 || trim((string)($submission['approval_reason'] ?? '')) !== '') {
                    $approvalRequests++;
                }

                $entryList = $this->parseBreakEntries($submission['break_entries'] ?? null, $submission['notes'] ?? '');
                $rowBreakMinutes = (int)($submission['total_break_minutes'] ?? 0);
                if ($rowBreakMinutes <= 0) {
                    $rowBreakMinutes = $this->getBreakMinutesFromEntries($entryList);
                }
                $breakMinutes += $rowBreakMinutes;
            }
            
            $currentTaskData = [
                'completed' => $completed,
                'pending' => $pending,
                'ongoing' => $ongoing,
                'upcoming' => $upcoming
            ];
            
            // Calendar months for trend analysis (default 6; full = from joining month)
            $trendData = [];
            for ($i = 0; $i < $trendMonthCount; $i++) {
                if ($i === 0) {
                    $periodStartStr = $periodStart;
                    $periodEndStr = $periodEnd;
                    $trendPeriodName = $periodName;
                    $periodRangeLabel = $periodRange;
                } else {
                    $monthPeriod = $this->getCalendarMonthPeriodAtOffset($i, $istTimezone);
                    $periodStartStr = $monthPeriod['start'];
                    $periodEndStr = $monthPeriod['end'];
                    $trendPeriodName = $monthPeriod['name'];
                    $periodRangeLabel = $monthPeriod['range'];
                }

                // Skip months before joining date when showing full history
                if ($joiningDate && $periodEndStr < $joiningDate) {
                    continue;
                }
                
                // Get work submission data for this period
                $stmt = $this->conn->prepare("
                    SELECT 
                        COUNT(*) as days,
                        SUM(hours_today) as hours
                    FROM work_submissions 
                    WHERE user_id = ? 
                    AND submission_date >= ?
                    AND submission_date <= ?
                    {$wsLive}
                ");
                $stmt->execute([$userId, $periodStartStr, $periodEndStr]);
                $periodData = $stmt->fetch(PDO::FETCH_ASSOC);

                // Pull per-day hours so paid leave can be credited into trend totals
                $hoursStmt = $this->conn->prepare("
                    SELECT submission_date, hours_today
                    FROM work_submissions
                    WHERE user_id = ?
                    AND submission_date >= ?
                    AND submission_date <= ?
                    {$wsLive}
                ");
                $hoursStmt->execute([$userId, $periodStartStr, $periodEndStr]);
                $periodHourRows = $hoursStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $periodHoursByDate = [];
                foreach ($periodHourRows as $row) {
                    $d = (string)($row['submission_date'] ?? '');
                    if ($d === '') {
                        continue;
                    }
                    $periodHoursByDate[$d] = (float)($row['hours_today'] ?? 0);
                }
                $periodLeaveMap = br_leave_day_map($this->conn, (string)$userId, (string)$periodStartStr, (string)$periodEndStr);
                $periodLeaveTotals = br_apply_leave_to_work_totals($periodHoursByDate, $periodLeaveMap);
                $periodData['days'] = $periodLeaveTotals['days'];
                $periodData['hours'] = $periodLeaveTotals['hours'];
                
                // Get task counts from work_submissions table for this period
                $taskStmt = $this->conn->prepare("
                    SELECT 
                        completed_tasks,
                        pending_tasks,
                        ongoing_tasks,
                        notes,
                        overtime_hours,
                        requested_extra_hours,
                        approval_reason,
                        extra_hours_approval_status,
                        extra_hours_approved_amount,
                        break_entries,
                        total_break_minutes
                    FROM work_submissions 
                    WHERE user_id = ? 
                    AND submission_date >= ? 
                    AND submission_date <= ?
                    {$wsLive}
                ");
                $taskStmt->execute([$userId, $periodStartStr, $periodEndStr]);
                $periodSubmissionTasks = $taskStmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Count tasks using the same logic as frontend countItems function
                $periodCompleted = 0;
                $periodPending = 0;
                $periodOngoing = 0;
                $periodUpcoming = 0;
                $periodOvertime = 0.0;
                $periodRequestedExtra = 0.0;
                $periodApprovalRequests = 0;
                $periodBreakMinutes = 0;
                
                foreach ($periodSubmissionTasks as $submission) {
                    // Count completed tasks (non-empty lines)
                    $completedLines = $this->splitTaskLines($submission['completed_tasks'] ?? '');
                    $periodCompleted += count($completedLines);
                    
                    // Count pending tasks (non-empty lines)
                    $pendingLines = $this->splitTaskLines($submission['pending_tasks'] ?? '');
                    $periodPending += count($pendingLines);
                    
                    // Count ongoing tasks (non-empty lines)
                    $ongoingLines = $this->splitTaskLines($submission['ongoing_tasks'] ?? '');
                    $periodOngoing += count($ongoingLines);
                    
                    // Count upcoming tasks (notes field, non-empty lines)
                    $upcomingLines = $this->splitTaskLines($submission['notes'] ?? '');
                    $periodUpcoming += count($upcomingLines);

                    $periodOvertime += br_effective_overtime_hours_for_stats($submission);
                    $periodRequested = (float)($submission['requested_extra_hours'] ?? 0);
                    $periodRequestedExtra += $periodRequested;
                    if ($periodRequested > 0 || trim((string)($submission['approval_reason'] ?? '')) !== '') {
                        $periodApprovalRequests++;
                    }
                    $periodBreakEntries = $this->parseBreakEntries($submission['break_entries'] ?? null, $submission['notes'] ?? '');
                    $periodBreakRowMinutes = (int)($submission['total_break_minutes'] ?? 0);
                    if ($periodBreakRowMinutes <= 0) {
                        $periodBreakRowMinutes = $this->getBreakMinutesFromEntries($periodBreakEntries);
                    }
                    $periodBreakMinutes += $periodBreakRowMinutes;
                }
                
                $taskData = [
                    'completed' => $periodCompleted,
                    'pending' => $periodPending,
                    'ongoing' => $periodOngoing,
                    'upcoming' => $periodUpcoming
                ];
                
                $trendData[] = [
                    'period' => $periodStartStr,
                    'period_name' => $trendPeriodName,
                    'period_range' => $periodRangeLabel,
                    'days' => (int)($periodData['days'] ?? 0),
                    'hours' => (float)($periodData['hours'] ?? 0),
                    'overtime_hours' => round($periodOvertime, 2),
                    'requested_extra_hours' => round($periodRequestedExtra, 2),
                    'approval_requests' => (int)$periodApprovalRequests,
                    'break_minutes' => (int)$periodBreakMinutes,
                    'task_counts' => [
                        'completed' => (int)($taskData['completed'] ?? 0),
                        'pending' => (int)($taskData['pending'] ?? 0),
                        'ongoing' => (int)($taskData['ongoing'] ?? 0),
                        'upcoming' => (int)($taskData['upcoming'] ?? 0)
                    ]
                ];
            }
            
            // Get user info
            $stmt = $this->conn->prepare("SELECT username, role FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            $returnedMonths = count($trendData);
            $hasMoreTrend = !$wantFull && $monthsFromJoin > $returnedMonths;
            
            $stats = [
                'user_id' => $userId,
                'username' => $user['username'] ?? 'Unknown',
                'role' => $user['role'] ?? 'user',
                'joining_date' => $joiningDate,
                'trend_scope' => $wantFull ? 'full' : 'recent',
                'trend_months' => $returnedMonths,
                'available_trend_months' => $monthsFromJoin,
                'has_more_trend' => $hasMoreTrend,
                'current_period' => [
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'period_name' => $monthName,
                    'period_range' => $periodRange,
                    'days' => $totalDays,
                    'hours' => round($totalHours, 1),
                    'overtime_hours' => round($overtimeHours, 2),
                    'requested_extra_hours' => round($requestedExtraHours, 2),
                    'approval_requests' => (int)$approvalRequests,
                    'break_minutes' => (int)$breakMinutes,
                    'task_counts' => [
                        'completed' => (int)($currentTaskData['completed'] ?? 0),
                        'pending' => (int)($currentTaskData['pending'] ?? 0),
                        'ongoing' => (int)($currentTaskData['ongoing'] ?? 0),
                        'upcoming' => (int)($currentTaskData['upcoming'] ?? 0)
                    ]
                ],
                'period_trend' => $trendData,
                'last_updated' => date('Y-m-d H:i:s')
            ];
            
            error_log("🔍 UserWorkStatsController::getUserWorkStats - User: " . $userId . ", Current month: " . $totalDays . " days, " . $totalHours . " hours");
            
            $this->sendJsonResponse(200, 'Work statistics retrieved successfully', $stats);
            
        } catch (Exception $e) {
            error_log('UserWorkStatsController error: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to retrieve work statistics');
        }
    }

    public function getPeriodDetails($userId, $periodStart, $periodEnd) {
        try {
            // Validate token
            $decoded = $this->validateToken();
            if (!$decoded || !isset($decoded->user_id)) {
                $this->sendJsonResponse(401, 'Invalid token or user_id missing');
                return;
            }

            // Check if user has permission to view stats
            $currentUserId = $decoded->user_id;
            
            // Allow users to view their own stats, or SUPER_ADMIN/USERS_VIEW to view others
            if ($currentUserId !== $userId) {
                $pm = PermissionManager::getInstance();
                
                // Check for SUPER_ADMIN or USERS_VIEW permission
                if (!$pm->hasPermission($currentUserId, 'SUPER_ADMIN') && 
                    !$pm->hasPermission($currentUserId, 'USERS_VIEW')) {
                    $this->sendJsonResponse(403, 'Access denied');
                    return;
                }
            }

            $this->ensureWorkSubmissionOtApprovalColumns();

            $wsLive = $this->wsLiveAnd();
            // Get all work submissions for the period with all details
            $stmt = $this->conn->prepare("
                SELECT 
                    id,
                    user_id,
                    submission_date,
                    created_at,
                    updated_at,
                    check_in_time,
                    hours_today,
                    start_time,
                    overtime_hours,
                    requested_extra_hours,
                    approval_reason,
                    extra_hours_approval_status,
                    extra_hours_approved_amount,
                    break_entries,
                    total_break_minutes,
                    completed_tasks,
                    pending_tasks,
                    ongoing_tasks,
                    notes,
                    planned_projects,
                    planned_work,
                    planned_work_status,
                    planned_work_notes
                FROM work_submissions 
                WHERE user_id = ? 
                AND submission_date >= ? 
                AND submission_date <= ?
                {$wsLive}
                ORDER BY submission_date DESC
            ");
            
            $stmt->execute([$userId, $periodStart, $periodEnd]);
            $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Process submissions to extract tasks
            $allCompleted = [];
            $allPending = [];
            $allOngoing = [];
            $allUpcoming = [];
            $allNotes = [];
            $dailyBreakdown = [];
            $totalOvertimeHours = 0.0;
            $totalRequestedExtraHours = 0.0;
            $totalBreakMinutes = 0;
            $totalApprovalRequests = 0;

            $projectNameMap = $this->buildProjectNameMapFromSubmissions($submissions);

            foreach ($submissions as $submission) {
                $date = $submission['submission_date'];
                
                // Extract completed tasks
                $completedLines = $this->splitTaskLines($submission['completed_tasks'] ?? '');
                foreach ($completedLines as $task) {
                    $allCompleted[] = ['date' => $date, 'task' => $task];
                }

                // Extract pending tasks
                $pendingLines = $this->splitTaskLines($submission['pending_tasks'] ?? '');
                foreach ($pendingLines as $task) {
                    $allPending[] = ['date' => $date, 'task' => $task];
                }

                // Extract ongoing tasks
                $ongoingLines = $this->splitTaskLines($submission['ongoing_tasks'] ?? '');
                foreach ($ongoingLines as $task) {
                    $allOngoing[] = ['date' => $date, 'task' => $task];
                }

                // Extract upcoming tasks (from notes field)
                $upcomingLines = $this->splitTaskLines($submission['notes'] ?? '');
                foreach ($upcomingLines as $task) {
                    $allUpcoming[] = ['date' => $date, 'task' => $task];
                }

                // Extract work notes
                if (!empty($submission['notes'])) {
                    $notesLines = $this->splitTaskLines($submission['notes']);
                    foreach ($notesLines as $note) {
                        $allNotes[] = ['date' => $date, 'note' => $note];
                    }
                }

                $breakEntries = $this->parseBreakEntries($submission['break_entries'] ?? null, $submission['notes'] ?? '');
                $breakMinutes = (int)($submission['total_break_minutes'] ?? 0);
                if ($breakMinutes <= 0) {
                    $breakMinutes = $this->getBreakMinutesFromEntries($breakEntries);
                }
                $totalOvertimeHours += br_effective_overtime_hours_for_stats($submission);
                $requestedExtra = (float)($submission['requested_extra_hours'] ?? 0);
                $totalRequestedExtraHours += $requestedExtra;
                if ($requestedExtra > 0 || trim((string)($submission['approval_reason'] ?? '')) !== '') {
                    $totalApprovalRequests++;
                }
                $totalBreakMinutes += $breakMinutes;

                $dailyBreakdown[] = $this->buildDailySubmissionEntry($submission, $projectNameMap);
            }

            // Merge approved leave days that have no work submission
            $leaveMap = br_leave_day_map($this->conn, (string)$userId, (string)$periodStart, (string)$periodEnd);
            $submissionDates = [];
            foreach ($dailyBreakdown as &$entry) {
                $d = (string)($entry['date'] ?? '');
                if ($d !== '') {
                    $submissionDates[$d] = true;
                }
                if (isset($leaveMap[$d])) {
                    $entry['day_status'] = 'leave';
                    $entry['leave_type_code'] = $leaveMap[$d]['leave_type_code'];
                    $entry['leave_type_name'] = $leaveMap[$d]['leave_type_name'];
                    $entry['leave_request_id'] = $leaveMap[$d]['leave_request_id'];
                    $entry['leave_reason'] = $leaveMap[$d]['leave_reason'] ?? null;
                    $credited = br_leave_info_credited_hours($leaveMap[$d]);
                    if ($credited > (float)($entry['hours_today'] ?? 0)) {
                        $entry['hours_today'] = $credited;
                        $entry['hours'] = $credited;
                    }
                }
            }
            unset($entry);

            foreach ($leaveMap as $leaveDate => $leaveInfo) {
                if (isset($submissionDates[$leaveDate])) {
                    continue;
                }
                $credited = br_leave_info_credited_hours($leaveInfo);
                $dailyBreakdown[] = $this->buildDailySubmissionEntry([
                    'id' => null,
                    'submission_date' => $leaveDate,
                    'user_id' => $userId,
                    'hours_today' => $credited,
                    'day_status' => 'leave',
                    'leave_type_code' => $leaveInfo['leave_type_code'],
                    'leave_type_name' => $leaveInfo['leave_type_name'],
                    'leave_request_id' => $leaveInfo['leave_request_id'],
                    'leave_reason' => $leaveInfo['leave_reason'] ?? null,
                ], $projectNameMap);
            }

            usort($dailyBreakdown, static function ($a, $b) {
                return strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? ''));
            });

            $hoursByDate = [];
            foreach ($submissions as $submission) {
                $d = (string)($submission['submission_date'] ?? '');
                if ($d === '') {
                    continue;
                }
                $hoursByDate[$d] = (float)($submission['hours_today'] ?? 0);
            }
            $leaveBreakdown = br_leave_credit_breakdown($hoursByDate, $leaveMap);

            $details = [
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'summary' => [
                    'hours' => $leaveBreakdown['hours'],
                    'days' => $leaveBreakdown['days'],
                    'work_hours' => $leaveBreakdown['work_hours'],
                    'work_days' => $leaveBreakdown['work_days'],
                    'leave_hours' => $leaveBreakdown['leave_hours'],
                    'leave_days' => $leaveBreakdown['leave_days'],
                    'official_leave_hours' => $leaveBreakdown['official_leave_hours'],
                    'official_leave_days' => $leaveBreakdown['official_leave_days'],
                    'other_leave_hours' => $leaveBreakdown['other_leave_hours'],
                    'other_leave_days' => $leaveBreakdown['other_leave_days'],
                    'overtime_hours' => round($totalOvertimeHours, 2),
                    'requested_extra_hours' => round($totalRequestedExtraHours, 2),
                    'approval_requests' => (int)$totalApprovalRequests,
                    'break_minutes' => (int)$totalBreakMinutes,
                    'net_hours' => round($leaveBreakdown['hours'] + $totalOvertimeHours, 2),
                ],
                'submissions' => $dailyBreakdown,
                'leave_days' => array_values(array_map(static function ($date, $info) {
                    return array_merge(['date' => $date], $info);
                }, array_keys($leaveMap), array_values($leaveMap))),
                'tasks' => [
                    'completed' => $allCompleted,
                    'pending' => $allPending,
                    'ongoing' => $allOngoing,
                    'upcoming' => $allUpcoming
                ],
                'notes' => $allNotes,
                'project_name_map' => $projectNameMap,
            ];

            $this->sendJsonResponse(200, 'Period details retrieved successfully', $details);
            
        } catch (Exception $e) {
            error_log('UserWorkStatsController::getPeriodDetails error: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to retrieve period details');
        }
    }

    public function getTeamPeriodDetails($periodStart, $periodEnd) {
        try {
            $decoded = $this->validateToken();
            if (!$decoded || !isset($decoded->user_id)) {
                $this->sendJsonResponse(401, 'Invalid token or user_id missing');
                return;
            }

            $this->assertCanViewTeamPeriodDetails($decoded);
            $this->ensureWorkSubmissionOtApprovalColumns();

            $wsLive = $this->wsLiveAnd('ws');
            $stmt = $this->conn->prepare("
                SELECT
                    ws.*,
                    u.username,
                    u.role
                FROM work_submissions ws
                INNER JOIN users u ON u.id = ws.user_id
                WHERE ws.submission_date >= ?
                AND ws.submission_date <= ?
                {$wsLive}
                ORDER BY ws.submission_date DESC, u.username ASC
            ");
            $stmt->execute([$periodStart, $periodEnd]);
            $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $dailyBreakdown = [];
            $allCompleted = [];
            $allPending = [];
            $allOngoing = [];
            $allUpcoming = [];
            $allNotes = [];
            $totalOvertimeHours = 0.0;
            $totalRequestedExtraHours = 0.0;
            $totalBreakMinutes = 0;
            $totalApprovalRequests = 0;
            $userIds = [];

            $projectNameMap = $this->buildProjectNameMapFromSubmissions($submissions);

            foreach ($submissions as $submission) {
                $date = $submission['submission_date'];
                $userIds[$submission['user_id']] = true;

                $completedLines = $this->splitTaskLines($submission['completed_tasks'] ?? '');
                $pendingLines = $this->splitTaskLines($submission['pending_tasks'] ?? '');
                $ongoingLines = $this->splitTaskLines($submission['ongoing_tasks'] ?? '');
                $upcomingLines = $this->splitTaskLines($submission['notes'] ?? '');

                foreach ($completedLines as $task) {
                    $allCompleted[] = ['date' => $date, 'task' => $task, 'username' => $submission['username'] ?? ''];
                }
                foreach ($pendingLines as $task) {
                    $allPending[] = ['date' => $date, 'task' => $task, 'username' => $submission['username'] ?? ''];
                }
                foreach ($ongoingLines as $task) {
                    $allOngoing[] = ['date' => $date, 'task' => $task, 'username' => $submission['username'] ?? ''];
                }
                foreach ($upcomingLines as $task) {
                    $allUpcoming[] = ['date' => $date, 'task' => $task, 'username' => $submission['username'] ?? ''];
                }

                if (!empty($submission['notes'])) {
                    $notesLines = $this->splitTaskLines($submission['notes']);
                    foreach ($notesLines as $note) {
                        $allNotes[] = ['date' => $date, 'note' => $note, 'username' => $submission['username'] ?? ''];
                    }
                }

                $breakEntries = $this->parseBreakEntries($submission['break_entries'] ?? null, $submission['notes'] ?? '');
                $breakMinutes = (int)($submission['total_break_minutes'] ?? 0);
                if ($breakMinutes <= 0) {
                    $breakMinutes = $this->getBreakMinutesFromEntries($breakEntries);
                }

                $totalOvertimeHours += br_effective_overtime_hours_for_stats($submission);
                $requestedExtra = (float)($submission['requested_extra_hours'] ?? 0);
                $totalRequestedExtraHours += $requestedExtra;
                if ($requestedExtra > 0 || trim((string)($submission['approval_reason'] ?? '')) !== '') {
                    $totalApprovalRequests++;
                }
                $totalBreakMinutes += $breakMinutes;

                $dailyBreakdown[] = $this->buildDailySubmissionEntry($submission, $projectNameMap);
            }

            // Include users who only have approved leave (no work row) in this period
            try {
                if (br_leave_tables_ready($this->conn)) {
                    $leaveUidStmt = $this->conn->prepare(
                        "SELECT DISTINCT user_id FROM leave_requests
                         WHERE status = 'approved'
                           AND start_date <= ?
                           AND end_date >= ?"
                    );
                    $leaveUidStmt->execute([$periodEnd, $periodStart]);
                    foreach ($leaveUidStmt->fetchAll(PDO::FETCH_COLUMN) as $leaveUid) {
                        $leaveUid = (string)$leaveUid;
                        if ($leaveUid !== '') {
                            $userIds[$leaveUid] = true;
                        }
                    }
                }
            } catch (Throwable $e) {
                error_log('getTeamPeriodDetails leave user ids: ' . $e->getMessage());
            }

            // Credit approved leave / Official Leave (holiday) across the team
            $teamUserIds = array_keys($userIds);
            $leaveMaps = br_leave_day_maps_for_users($this->conn, $teamUserIds, (string)$periodStart, (string)$periodEnd);
            $submissionKeys = [];
            $hoursByUserDate = [];
            foreach ($dailyBreakdown as $row) {
                $uid = (string)($row['user_id'] ?? '');
                $d = (string)($row['date'] ?? '');
                if ($uid === '' || $d === '') {
                    continue;
                }
                $submissionKeys[$uid . '|' . $d] = true;
                $hoursByUserDate[$uid][$d] = (float)($row['hours_today'] ?? $row['hours'] ?? 0);
            }

            $teamLeaveDays = 0;
            $teamLeaveHours = 0.0;
            $teamOfficialDays = 0;
            $teamOfficialHours = 0.0;
            $teamOtherLeaveDays = 0;
            $teamOtherLeaveHours = 0.0;
            $teamWorkHours = 0.0;
            $teamTotalHours = 0.0;
            $teamCreditedDays = [];

            foreach ($teamUserIds as $uid) {
                $uid = (string)$uid;
                $map = $leaveMaps[$uid] ?? [];
                $hoursByDate = $hoursByUserDate[$uid] ?? [];
                $breakdown = br_leave_credit_breakdown($hoursByDate, $map);
                $teamWorkHours += $breakdown['work_hours'];
                $teamLeaveHours += $breakdown['leave_hours'];
                $teamLeaveDays += $breakdown['leave_days'];
                $teamOfficialHours += $breakdown['official_leave_hours'];
                $teamOfficialDays += $breakdown['official_leave_days'];
                $teamOtherLeaveHours += $breakdown['other_leave_hours'];
                $teamOtherLeaveDays += $breakdown['other_leave_days'];
                $teamTotalHours += $breakdown['hours'];

                foreach ($hoursByDate as $d => $_h) {
                    $teamCreditedDays[$d] = true;
                }
                foreach ($map as $leaveDate => $leaveInfo) {
                    $teamCreditedDays[$leaveDate] = true;
                    $key = $uid . '|' . $leaveDate;
                    if (isset($submissionKeys[$key])) {
                        // Tag existing row
                        foreach ($dailyBreakdown as &$entry) {
                            if ((string)($entry['user_id'] ?? '') === $uid && (string)($entry['date'] ?? '') === $leaveDate) {
                                $entry['day_status'] = 'leave';
                                $entry['leave_type_code'] = $leaveInfo['leave_type_code'];
                                $entry['leave_type_name'] = $leaveInfo['leave_type_name'];
                                $entry['leave_request_id'] = $leaveInfo['leave_request_id'];
                                $entry['leave_reason'] = $leaveInfo['leave_reason'] ?? null;
                                $credited = br_leave_info_credited_hours($leaveInfo);
                                if ($credited > (float)($entry['hours_today'] ?? 0)) {
                                    $entry['hours_today'] = $credited;
                                    $entry['hours'] = $credited;
                                }
                                break;
                            }
                        }
                        unset($entry);
                        continue;
                    }
                    $credited = br_leave_info_credited_hours($leaveInfo);
                    $uname = '';
                    $urole = '';
                    // username/role already known from submissions; leave-only may need lookup later
                    $dailyBreakdown[] = $this->buildDailySubmissionEntry([
                        'id' => null,
                        'submission_date' => $leaveDate,
                        'user_id' => $uid,
                        'username' => $uname,
                        'role' => $urole,
                        'hours_today' => $credited,
                        'day_status' => 'leave',
                        'leave_type_code' => $leaveInfo['leave_type_code'],
                        'leave_type_name' => $leaveInfo['leave_type_name'],
                        'leave_request_id' => $leaveInfo['leave_request_id'],
                        'leave_reason' => $leaveInfo['leave_reason'] ?? null,
                    ], $projectNameMap);
                }
            }

            // Fill usernames for leave-only rows
            if ($teamUserIds !== []) {
                try {
                    $ph = implode(',', array_fill(0, count($teamUserIds), '?'));
                    $uStmt = $this->conn->prepare("SELECT id, username, role FROM users WHERE id IN ($ph)");
                    $uStmt->execute($teamUserIds);
                    $nameById = [];
                    foreach ($uStmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
                        $nameById[(string)$u['id']] = $u;
                    }
                    foreach ($dailyBreakdown as &$entry) {
                        if (!empty($entry['username'])) {
                            continue;
                        }
                        $uid = (string)($entry['user_id'] ?? '');
                        if (isset($nameById[$uid])) {
                            $entry['username'] = $nameById[$uid]['username'] ?? '';
                            $entry['role'] = $nameById[$uid]['role'] ?? ($entry['role'] ?? '');
                        }
                    }
                    unset($entry);
                } catch (Throwable $e) {
                    error_log('getTeamPeriodDetails leave usernames: ' . $e->getMessage());
                }
            }

            usort($dailyBreakdown, static function ($a, $b) {
                $cmp = strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? ''));
                if ($cmp !== 0) {
                    return $cmp;
                }
                return strcmp((string)($a['username'] ?? ''), (string)($b['username'] ?? ''));
            });

            $details = [
                'scope' => 'team',
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'summary' => [
                    'users' => count($userIds),
                    'submission_days' => count($teamCreditedDays),
                    'submissions' => count($dailyBreakdown),
                    'hours' => round($teamTotalHours, 2),
                    'work_hours' => round($teamWorkHours, 2),
                    'leave_hours' => round($teamLeaveHours, 2),
                    'leave_days' => (int)$teamLeaveDays,
                    'official_leave_hours' => round($teamOfficialHours, 2),
                    'official_leave_days' => (int)$teamOfficialDays,
                    'other_leave_hours' => round($teamOtherLeaveHours, 2),
                    'other_leave_days' => (int)$teamOtherLeaveDays,
                    'overtime_hours' => round($totalOvertimeHours, 2),
                    'requested_extra_hours' => round($totalRequestedExtraHours, 2),
                    'approval_requests' => (int)$totalApprovalRequests,
                    'break_minutes' => (int)$totalBreakMinutes,
                    'net_hours' => round($teamTotalHours + $totalOvertimeHours, 2),
                ],
                'submissions' => $dailyBreakdown,
                'tasks' => [
                    'completed' => $allCompleted,
                    'pending' => $allPending,
                    'ongoing' => $allOngoing,
                    'upcoming' => $allUpcoming,
                ],
                'notes' => $allNotes,
                'project_name_map' => $projectNameMap,
            ];

            $this->sendJsonResponse(200, 'Team period details retrieved successfully', $details);
        } catch (Exception $e) {
            error_log('UserWorkStatsController::getTeamPeriodDetails error: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to retrieve team period details');
        }
    }

    private function parseCheckInMinutes($value) {
        if ($value === null || $value === '') {
            return null;
        }
        $text = trim((string)$value);
        if ($text === '') {
            return null;
        }
        if (preg_match('/(\d{1,2}):(\d{2})(?::(\d{2}))?/', $text, $matches)) {
            $h = (int)$matches[1];
            $m = (int)$matches[2];
            return ($h * 60) + $m;
        }
        try {
            $dt = new DateTime($text);
            return ((int)$dt->format('H') * 60) + (int)$dt->format('i');
        } catch (Throwable $e) {
            return null;
        }
    }

    private function formatMinutesAsTime($minutes) {
        if ($minutes === null) {
            return null;
        }
        $minutes = max(0, (int)$minutes);
        $h = intdiv($minutes, 60) % 24;
        $m = $minutes % 60;
        $ampm = $h >= 12 ? 'PM' : 'AM';
        $displayHour = $h % 12;
        if ($displayHour === 0) {
            $displayHour = 12;
        }
        return sprintf('%d:%02d %s', $displayHour, $m, $ampm);
    }

    private function emptyUserAnalyticsRow(array $user) {
        return [
            'user_id' => (string)$user['id'],
            'username' => (string)($user['username'] ?? ''),
            'name' => (string)($user['name'] ?? $user['username'] ?? ''),
            'role' => (string)($user['role'] ?? ''),
            'account_active' => isset($user['account_active']) ? (int)$user['account_active'] : 1,
            'current_period' => [
                'days' => 0,
                'hours' => 0.0,
                'work_hours' => 0.0,
                'leave_hours' => 0.0,
                'leave_days' => 0,
                'official_leave_hours' => 0.0,
                'official_leave_days' => 0,
                'other_leave_hours' => 0.0,
                'other_leave_days' => 0,
                'avg_hours_per_day' => 0.0,
                'tasks_completed' => 0,
                'tasks_pending' => 0,
                'tasks_ongoing' => 0,
                'overtime_hours' => 0.0,
                'break_minutes' => 0,
                'avg_check_in_minutes' => null,
                'avg_check_in_label' => null,
                'bugs_reported' => 0,
                'bugs_fixed' => 0,
                'projects' => [],
                'net_hours' => 0.0,
            ],
            'lookback' => [
                'months' => 0,
                'avg_hours_per_day' => 0.0,
                'avg_days_per_month' => 0.0,
                'avg_tasks_completed_per_month' => 0.0,
                'avg_overtime_hours_per_month' => 0.0,
            ],
        ];
    }

    private function accumulateSubmissionMetrics(array &$bucket, array $submission, $includeCheckIn = true) {
        $date = (string)($submission['submission_date'] ?? '');
        $hoursToday = (float)($submission['hours_today'] ?? 0);
        if ($date !== '') {
            $bucket['dates'][$date] = true;
            if (!isset($bucket['hours_by_date']) || !is_array($bucket['hours_by_date'])) {
                $bucket['hours_by_date'] = [];
            }
            $bucket['hours_by_date'][$date] = (float)($bucket['hours_by_date'][$date] ?? 0) + $hoursToday;
        }

        $bucket['hours'] += $hoursToday;
        $bucket['work_hours'] = (float)($bucket['work_hours'] ?? 0) + $hoursToday;
        $bucket['tasks_completed'] += count($this->splitTaskLines($submission['completed_tasks'] ?? ''));
        $bucket['tasks_pending'] += count($this->splitTaskLines($submission['pending_tasks'] ?? ''));
        $bucket['tasks_ongoing'] += count($this->splitTaskLines($submission['ongoing_tasks'] ?? ''));
        $bucket['overtime_hours'] += br_effective_overtime_hours_for_stats($submission);

        $breakEntries = $this->parseBreakEntries($submission['break_entries'] ?? null, $submission['notes'] ?? '');
        $breakMinutes = (int)($submission['total_break_minutes'] ?? 0);
        if ($breakMinutes <= 0) {
            $breakMinutes = $this->getBreakMinutesFromEntries($breakEntries);
        }
        $bucket['break_minutes'] += $breakMinutes;

        if (!isset($bucket['project_ids']) || !is_array($bucket['project_ids'])) {
            $bucket['project_ids'] = [];
        }
        foreach ($this->parsePlannedProjectIds($submission['planned_projects'] ?? null) as $projectId) {
            $bucket['project_ids'][(string)$projectId] = true;
        }
        $updates = $this->parseProjectUpdates($submission['project_updates'] ?? null);
        foreach ($updates as $update) {
            if (!empty($update['project_id'])) {
                $bucket['project_ids'][(string)$update['project_id']] = true;
            }
        }

        if ($includeCheckIn) {
            $checkIn = $submission['check_in_time'] ?? $submission['start_time'] ?? null;
            $minutes = $this->parseCheckInMinutes($checkIn);
            if ($minutes !== null) {
                $bucket['check_in_minutes'][] = $minutes;
            }
        }
    }

    /**
     * Why: Team analytics previously ignored leave/Official Leave, so totals disagreed
     * with individual work-stats pages.
     *
     * @param array<string,mixed> $bucket
     * @param array<string,array<string,mixed>> $leaveMap
     */
    private function applyLeaveCreditsToAnalyticsBucket(array &$bucket, array $leaveMap): void
    {
        if ($leaveMap === []) {
            if (!isset($bucket['work_hours'])) {
                $bucket['work_hours'] = (float)($bucket['hours'] ?? 0);
            }
            return;
        }
        $hoursByDate = [];
        if (!empty($bucket['hours_by_date']) && is_array($bucket['hours_by_date'])) {
            foreach ($bucket['hours_by_date'] as $d => $h) {
                $hoursByDate[(string)$d] = (float)$h;
            }
        }
        $breakdown = br_leave_credit_breakdown($hoursByDate, $leaveMap);
        $bucket['work_hours'] = $breakdown['work_hours'];
        $bucket['hours'] = $breakdown['hours'];
        $bucket['leave_hours'] = $breakdown['leave_hours'];
        $bucket['leave_days'] = $breakdown['leave_days'];
        $bucket['official_leave_hours'] = $breakdown['official_leave_hours'];
        $bucket['official_leave_days'] = $breakdown['official_leave_days'];
        $bucket['other_leave_hours'] = $breakdown['other_leave_hours'];
        $bucket['other_leave_days'] = $breakdown['other_leave_days'];
        foreach ($leaveMap as $date => $_info) {
            $bucket['dates'][(string)$date] = true;
        }
    }

    private function finalizeAnalyticsBucket(array $bucket, $monthDivisor = 1, array $projectNameMap = []) {
        $days = count($bucket['dates'] ?? []);
        $hours = round((float)($bucket['hours'] ?? 0), 2);
        $avgHoursPerDay = $days > 0 ? round($hours / $days, 2) : 0.0;
        $checkIns = $bucket['check_in_minutes'] ?? [];
        $avgCheckIn = !empty($checkIns)
            ? (int)round(array_sum($checkIns) / count($checkIns))
            : null;

        $months = max(1, (int)$monthDivisor);
        $projectIds = array_keys($bucket['project_ids'] ?? []);
        $projectNames = [];
        foreach ($projectIds as $projectId) {
            $resolved = $this->lookupProjectName($projectId, $projectNameMap);
            if ($resolved === '' || $resolved === null) {
                continue;
            }
            if (
                preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $resolved) &&
                strcasecmp($resolved, (string)$projectId) === 0
            ) {
                continue;
            }
            $projectNames[] = $resolved;
        }
        $projectNames = array_values(array_unique($projectNames));
        sort($projectNames, SORT_NATURAL | SORT_FLAG_CASE);

        return [
            'days' => $days,
            'hours' => $hours,
            'work_hours' => round((float)($bucket['work_hours'] ?? $hours), 2),
            'leave_hours' => round((float)($bucket['leave_hours'] ?? 0), 2),
            'leave_days' => (int)($bucket['leave_days'] ?? 0),
            'official_leave_hours' => round((float)($bucket['official_leave_hours'] ?? 0), 2),
            'official_leave_days' => (int)($bucket['official_leave_days'] ?? 0),
            'other_leave_hours' => round((float)($bucket['other_leave_hours'] ?? 0), 2),
            'other_leave_days' => (int)($bucket['other_leave_days'] ?? 0),
            'avg_hours_per_day' => $avgHoursPerDay,
            'tasks_completed' => (int)($bucket['tasks_completed'] ?? 0),
            'tasks_pending' => (int)($bucket['tasks_pending'] ?? 0),
            'tasks_ongoing' => (int)($bucket['tasks_ongoing'] ?? 0),
            'overtime_hours' => round((float)($bucket['overtime_hours'] ?? 0), 2),
            'break_minutes' => (int)($bucket['break_minutes'] ?? 0),
            'avg_check_in_minutes' => $avgCheckIn,
            'avg_check_in_label' => $this->formatMinutesAsTime($avgCheckIn),
            'avg_days_per_month' => round($days / $months, 1),
            'avg_tasks_completed_per_month' => round(((int)($bucket['tasks_completed'] ?? 0)) / $months, 1),
            'avg_overtime_hours_per_month' => round(((float)($bucket['overtime_hours'] ?? 0)) / $months, 2),
            'projects' => $projectNames,
        ];
    }

    private function buildRoleHighLowLists(array $users, $limit = 5) {
        $metrics = [
            'avg_hours_per_day' => 'current_period.avg_hours_per_day',
            'tasks_completed' => 'current_period.tasks_completed',
            'work_days' => 'current_period.days',
            'overtime_hours' => 'current_period.overtime_hours',
        ];

        $result = ['high' => [], 'low' => []];
        foreach ($metrics as $key => $path) {
            $parts = explode('.', $path);
            $sorted = $users;
            usort($sorted, function ($a, $b) use ($parts) {
                $av = $a;
                $bv = $b;
                foreach ($parts as $part) {
                    $av = $av[$part] ?? 0;
                    $bv = $bv[$part] ?? 0;
                }
                if ($av === $bv) {
                    return strcmp((string)($a['username'] ?? ''), (string)($b['username'] ?? ''));
                }
                return $bv <=> $av;
            });

            $active = array_values(array_filter($sorted, function ($row) use ($parts) {
                $value = $row;
                foreach ($parts as $part) {
                    $value = $value[$part] ?? 0;
                }
                return (float)$value > 0;
            }));

            $result['high'][$key] = array_slice($active, 0, $limit);
            $result['low'][$key] = array_slice(array_reverse($active), 0, $limit);
        }

        return $result;
    }

    private function summarizeRoleUsers(array $users) {
        if (empty($users)) {
            return [
                'user_count' => 0,
                'avg_hours_per_day' => 0.0,
                'avg_work_days' => 0.0,
                'avg_tasks_completed' => 0.0,
                'avg_overtime_hours' => 0.0,
                'total_hours' => 0.0,
                'total_work_hours' => 0.0,
                'total_leave_hours' => 0.0,
                'total_official_leave_hours' => 0.0,
                'total_leave_days' => 0,
                'total_official_leave_days' => 0,
                'total_overtime_hours' => 0.0,
                'total_net_hours' => 0.0,
            ];
        }

        $count = count($users);
        $sumHoursPerDay = 0.0;
        $sumDays = 0.0;
        $sumTasks = 0.0;
        $sumOvertime = 0.0;
        $sumHours = 0.0;
        $sumWork = 0.0;
        $sumLeave = 0.0;
        $sumOfficial = 0.0;
        $sumLeaveDays = 0;
        $sumOfficialDays = 0;

        foreach ($users as $user) {
            $current = $user['current_period'] ?? [];
            $sumHoursPerDay += (float)($current['avg_hours_per_day'] ?? 0);
            $sumDays += (float)($current['days'] ?? 0);
            $sumTasks += (float)($current['tasks_completed'] ?? 0);
            $sumOvertime += (float)($current['overtime_hours'] ?? 0);
            $sumHours += (float)($current['hours'] ?? 0);
            $sumWork += (float)($current['work_hours'] ?? $current['hours'] ?? 0);
            $sumLeave += (float)($current['leave_hours'] ?? 0);
            $sumOfficial += (float)($current['official_leave_hours'] ?? 0);
            $sumLeaveDays += (int)($current['leave_days'] ?? 0);
            $sumOfficialDays += (int)($current['official_leave_days'] ?? 0);
        }

        return [
            'user_count' => $count,
            'avg_hours_per_day' => round($sumHoursPerDay / $count, 2),
            'avg_work_days' => round($sumDays / $count, 1),
            'avg_tasks_completed' => round($sumTasks / $count, 1),
            'avg_overtime_hours' => round($sumOvertime / $count, 2),
            'total_hours' => round($sumHours, 2),
            'total_work_hours' => round($sumWork, 2),
            'total_leave_hours' => round($sumLeave, 2),
            'total_official_leave_hours' => round($sumOfficial, 2),
            'total_leave_days' => (int)$sumLeaveDays,
            'total_official_leave_days' => (int)$sumOfficialDays,
            'total_overtime_hours' => round($sumOvertime, 2),
            'total_net_hours' => round($sumHours + $sumOvertime, 2),
        ];
    }

    public function getUsersAnalytics() {
        try {
            $decoded = $this->validateToken();
            if (!$decoded || !isset($decoded->user_id)) {
                $this->sendJsonResponse(401, 'Invalid token or user_id missing');
                return;
            }

            $this->assertCanViewTeamPeriodDetails($decoded);
            $this->ensureWorkSubmissionOtApprovalColumns();

            $istTimezone = new DateTimeZone('Asia/Kolkata');
            $resolved = $this->resolveAnalyticsPeriods($istTimezone);
            $lookbackMonths = $resolved['lookback_months'];
            $listLimit = isset($_GET['limit']) ? max(3, min(10, (int)$_GET['limit'])) : 5;
            $activeOnlyRequested = isset($_GET['active_only']) && in_array(
                strtolower((string)$_GET['active_only']),
                ['1', 'true', 'yes'],
                true
            );

            $currentPeriod = $resolved['current'];
            $periodStart = $currentPeriod['start'];
            $periodEnd = $currentPeriod['end'];
            $lookbackStart = $resolved['lookback_start'];
            $selectedMonth = $resolved['month'];

            $userCols = [];
            try {
                $colsRes = $this->conn->query("SHOW COLUMNS FROM users");
                if ($colsRes) {
                    foreach ($colsRes->fetchAll(PDO::FETCH_ASSOC) as $colRow) {
                        $field = (string)($colRow['Field'] ?? '');
                        if ($field !== '') {
                            $userCols[$field] = true;
                        }
                    }
                }
            } catch (Exception $e) {
                // fallback handled below
            }
            $hasNameCol = isset($userCols['name']);
            $hasAccountActiveCol = isset($userCols['account_active']);
            $nameExpr = $hasNameCol
                ? "COALESCE(NULLIF(name, ''), username) AS name"
                : "username AS name";
            $accountActiveExpr = $hasAccountActiveCol
                ? "COALESCE(account_active, 1) AS account_active"
                : "1 AS account_active";

            $usersStmt = $this->conn->query("
                SELECT id, username, {$nameExpr}, role, {$accountActiveExpr}
                FROM users
                ORDER BY username ASC
            ");
            if (!$usersStmt) {
                $err = $this->conn->errorInfo();
                throw new Exception('Failed users analytics user query: ' . ((string)($err[2] ?? 'unknown error')));
            }
            $allUsers = $usersStmt->fetchAll(PDO::FETCH_ASSOC);
            $totalUsersBeforeFilter = count($allUsers);
            $activeOnlyApplied = false;
            if ($activeOnlyRequested && $hasAccountActiveCol) {
                $activeOnlyApplied = true;
                $allUsers = array_values(array_filter($allUsers, function ($user) {
                    return (int)($user['account_active'] ?? 1) !== 0;
                }));
            }

            $currentBuckets = [];
            $lookbackBuckets = [];
            foreach ($allUsers as $user) {
                $uid = (string)$user['id'];
                $currentBuckets[$uid] = [
                    'dates' => [],
                    'hours_by_date' => [],
                    'hours' => 0.0,
                    'tasks_completed' => 0,
                    'tasks_pending' => 0,
                    'tasks_ongoing' => 0,
                    'overtime_hours' => 0.0,
                    'break_minutes' => 0,
                    'check_in_minutes' => [],
                    'project_ids' => [],
                    'leave_days' => 0,
                    'leave_hours' => 0.0,
                    'official_leave_days' => 0,
                    'official_leave_hours' => 0.0,
                    'other_leave_days' => 0,
                    'other_leave_hours' => 0.0,
                    'work_hours' => 0.0,
                ];
                $lookbackBuckets[$uid] = [
                    'dates' => [],
                    'hours_by_date' => [],
                    'hours' => 0.0,
                    'tasks_completed' => 0,
                    'tasks_pending' => 0,
                    'tasks_ongoing' => 0,
                    'overtime_hours' => 0.0,
                    'break_minutes' => 0,
                    'check_in_minutes' => [],
                    'project_ids' => [],
                    'leave_days' => 0,
                    'leave_hours' => 0.0,
                    'official_leave_days' => 0,
                    'official_leave_hours' => 0.0,
                    'other_leave_days' => 0,
                    'other_leave_hours' => 0.0,
                    'work_hours' => 0.0,
                ];
            }

            $wsLive = $this->wsLiveAnd('ws');
            $submissionStmt = $this->conn->prepare("
                SELECT ws.*, u.id AS user_ref_id
                FROM work_submissions ws
                INNER JOIN users u ON u.id = ws.user_id
                WHERE ws.submission_date >= ?
                AND ws.submission_date <= ?
                {$wsLive}
            ");
            if (!$submissionStmt) {
                $err = $this->conn->errorInfo();
                throw new Exception('Failed users analytics submission query: ' . ((string)($err[2] ?? 'unknown error')));
            }
            $submissionStmt->execute([$lookbackStart, $periodEnd]);
            $submissions = $submissionStmt->fetchAll(PDO::FETCH_ASSOC);
            $projectNameMap = $this->buildProjectNameMapFromSubmissions($submissions);

            foreach ($submissions as $submission) {
                $uid = (string)($submission['user_id'] ?? $submission['user_ref_id'] ?? '');
                if ($uid === '' || !isset($lookbackBuckets[$uid])) {
                    continue;
                }

                $date = (string)($submission['submission_date'] ?? '');
                $this->accumulateSubmissionMetrics($lookbackBuckets[$uid], $submission, true);
                if ($date >= $periodStart && $date <= $periodEnd) {
                    $this->accumulateSubmissionMetrics($currentBuckets[$uid], $submission, true);
                }
            }

            // Apply leave / Official Leave hour credits so analytics match individual work-stats
            $analyticsUserIds = array_map(static function ($u) {
                return (string)($u['id'] ?? '');
            }, $allUsers);
            $leaveMapsLookback = br_leave_day_maps_for_users(
                $this->conn,
                $analyticsUserIds,
                (string)$lookbackStart,
                (string)$periodEnd
            );
            foreach ($analyticsUserIds as $uid) {
                if ($uid === '' || !isset($lookbackBuckets[$uid])) {
                    continue;
                }
                $fullMap = $leaveMapsLookback[$uid] ?? [];
                $currentMap = [];
                foreach ($fullMap as $d => $info) {
                    if ($d >= $periodStart && $d <= $periodEnd) {
                        $currentMap[$d] = $info;
                    }
                }
                $this->applyLeaveCreditsToAnalyticsBucket($lookbackBuckets[$uid], $fullMap);
                $this->applyLeaveCreditsToAnalyticsBucket($currentBuckets[$uid], $currentMap);
            }

            $bugsReported = [];
            $bugsFixed = [];
            try {
                $bugStmt = $this->conn->prepare("
                    SELECT reported_by AS user_id, COUNT(*) AS total
                    FROM bugs
                    WHERE DATE(created_at) >= ? AND DATE(created_at) <= ?
                    GROUP BY reported_by
                ");
                $bugStmt->execute([$periodStart, $periodEnd]);
                foreach ($bugStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $bugsReported[(string)$row['user_id']] = (int)$row['total'];
                }

                $fixStmt = $this->conn->prepare("
                    SELECT updated_by AS user_id, COUNT(*) AS total
                    FROM bugs
                    WHERE status = 'fixed'
                    AND DATE(updated_at) >= ? AND DATE(updated_at) <= ?
                    GROUP BY updated_by
                ");
                $fixStmt->execute([$periodStart, $periodEnd]);
                foreach ($fixStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $bugsFixed[(string)$row['user_id']] = (int)$row['total'];
                }
            } catch (Exception $e) {
                error_log('UserWorkStatsController::getUsersAnalytics bug counts error: ' . $e->getMessage());
            }

            $roleGroups = [
                'admin' => [],
                'developer' => [],
                'tester' => [],
                'creator' => [],
            ];

            foreach ($allUsers as $user) {
                $role = strtolower((string)($user['role'] ?? ''));
                if (!isset($roleGroups[$role])) {
                    continue;
                }

                $uid = (string)$user['id'];
                $row = $this->emptyUserAnalyticsRow($user);
                $current = $this->finalizeAnalyticsBucket($currentBuckets[$uid] ?? [], 1, $projectNameMap);
                $lookback = $this->finalizeAnalyticsBucket($lookbackBuckets[$uid] ?? [], $lookbackMonths, $projectNameMap);

                $row['current_period'] = [
                    'days' => $current['days'],
                    'hours' => $current['hours'],
                    'work_hours' => $current['work_hours'],
                    'leave_hours' => $current['leave_hours'],
                    'leave_days' => $current['leave_days'],
                    'official_leave_hours' => $current['official_leave_hours'],
                    'official_leave_days' => $current['official_leave_days'],
                    'other_leave_hours' => $current['other_leave_hours'],
                    'other_leave_days' => $current['other_leave_days'],
                    'avg_hours_per_day' => $current['avg_hours_per_day'],
                    'tasks_completed' => $current['tasks_completed'],
                    'tasks_pending' => $current['tasks_pending'],
                    'tasks_ongoing' => $current['tasks_ongoing'],
                    'overtime_hours' => $current['overtime_hours'],
                    'break_minutes' => $current['break_minutes'],
                    'avg_check_in_minutes' => $current['avg_check_in_minutes'],
                    'avg_check_in_label' => $current['avg_check_in_label'],
                    'bugs_reported' => (int)($bugsReported[$uid] ?? 0),
                    'bugs_fixed' => (int)($bugsFixed[$uid] ?? 0),
                    'projects' => $current['projects'] ?? [],
                    'net_hours' => round((float)$current['hours'] + (float)$current['overtime_hours'], 2),
                ];
                $row['lookback'] = [
                    'months' => $lookbackMonths,
                    'avg_hours_per_day' => $lookback['avg_hours_per_day'],
                    'avg_days_per_month' => $lookback['avg_days_per_month'],
                    'avg_tasks_completed_per_month' => $lookback['avg_tasks_completed_per_month'],
                    'avg_overtime_hours_per_month' => $lookback['avg_overtime_hours_per_month'],
                ];

                $roleGroups[$role][] = $row;
            }

            $rolesPayload = [];
            foreach ($roleGroups as $role => $users) {
                usort($users, function ($a, $b) {
                    $cmp = ($b['current_period']['avg_hours_per_day'] ?? 0) <=> ($a['current_period']['avg_hours_per_day'] ?? 0);
                    if ($cmp !== 0) {
                        return $cmp;
                    }
                    return strcmp((string)$a['username'], (string)$b['username']);
                });

                $rolesPayload[$role] = [
                    'summary' => $this->summarizeRoleUsers($users),
                    'users' => $users,
                    'rankings' => $this->buildRoleHighLowLists($users, $listLimit),
                ];
            }

            $allRankedUsers = array_merge($roleGroups['admin'], $roleGroups['developer'], $roleGroups['tester'], $roleGroups['creator']);

            $payload = [
                'period' => [
                    'start' => $periodStart,
                    'end' => $periodEnd,
                    'name' => $currentPeriod['name'],
                    'range' => $currentPeriod['range'],
                    'month' => $selectedMonth,
                ],
                'lookback_months' => $lookbackMonths,
                'team_summary' => $this->summarizeRoleUsers($allRankedUsers),
                'filters' => [
                    'active_only_requested' => $activeOnlyRequested,
                    'active_only_applied' => $activeOnlyApplied,
                    'total_users_before_filter' => $totalUsersBeforeFilter,
                    'total_users_after_filter' => count($allRankedUsers),
                    'month' => $selectedMonth,
                ],
                'roles' => $rolesPayload,
                'last_updated' => date('Y-m-d H:i:s'),
            ];

            $this->sendJsonResponse(200, 'User analytics retrieved successfully', $payload);
        } catch (Throwable $e) {
            error_log('UserWorkStatsController::getUsersAnalytics error: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to retrieve user analytics');
        }
    }
}

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow GET method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

$userId = $_GET['id'] ?? null;
$periodStart = $_GET['period_start'] ?? null;
$periodEnd = $_GET['period_end'] ?? null;
$team = isset($_GET['team']) && in_array(strtolower((string)$_GET['team']), ['1', 'true', 'yes'], true);
$analytics = isset($_GET['analytics']) && in_array(strtolower((string)$_GET['analytics']), ['1', 'true', 'yes'], true);

$controller = new UserWorkStatsController();

if ($analytics) {
    $controller->getUsersAnalytics();
    exit;
}

// If period_start and period_end are provided, get period details
if ($periodStart && $periodEnd) {
    if ($team) {
        $controller->getTeamPeriodDetails($periodStart, $periodEnd);
        exit;
    }
    if (!$userId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'User ID is required']);
        exit();
    }
    $controller->getPeriodDetails($userId, $periodStart, $periodEnd);
} else {
    // Otherwise, get work stats
    if (!$userId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'User ID is required']);
        exit();
    }
    $controller->getUserWorkStats($userId);
}
?>
