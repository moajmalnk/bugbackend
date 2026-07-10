<?php
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../PermissionManager.php';
require_once __DIR__ . '/../../utils/work_submission_ot.php';

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

            // Current calendar month (1st through last day)
            $istTimezone = new DateTimeZone('Asia/Kolkata');
            $currentPeriod = $this->getCalendarMonthPeriodAtOffset(0, $istTimezone);
            $periodStart = $currentPeriod['start'];
            $periodEnd = $currentPeriod['end'];
            $periodName = $currentPeriod['name'];
            $periodRange = $currentPeriod['range'];
            
            // Get work submissions for the current custom period
            $stmt = $this->conn->prepare("
                SELECT 
                    submission_date,
                    hours_today,
                    start_time
                FROM work_submissions 
                WHERE user_id = ? 
                AND submission_date >= ? 
                AND submission_date <= ?
                ORDER BY submission_date DESC
            ");
            
            $stmt->execute([$userId, $periodStart, $periodEnd]);
            $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calculate statistics
            $totalHours = 0;
            $totalDays = count($submissions);
            $monthName = $periodName;
            
            foreach ($submissions as $submission) {
                $totalHours += (float)($submission['hours_today'] ?? 0);
            }
            
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
            
            // Last 6 calendar months for trend analysis
            $trendData = [];
            for ($i = 0; $i < 6; $i++) {
                if ($i === 0) {
                    $periodStartStr = $periodStart;
                    $periodEndStr = $periodEnd;
                    $periodName = $periodName;
                    $periodRangeLabel = $periodRange;
                } else {
                    $monthPeriod = $this->getCalendarMonthPeriodAtOffset($i, $istTimezone);
                    $periodStartStr = $monthPeriod['start'];
                    $periodEndStr = $monthPeriod['end'];
                    $periodName = $monthPeriod['name'];
                    $periodRangeLabel = $monthPeriod['range'];
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
                ");
                $stmt->execute([$userId, $periodStartStr, $periodEndStr]);
                $periodData = $stmt->fetch(PDO::FETCH_ASSOC);
                
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
                    'period_name' => $periodName,
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
            
            $stats = [
                'user_id' => $userId,
                'username' => $user['username'] ?? 'Unknown',
                'role' => $user['role'] ?? 'user',
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

            $details = [
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'summary' => [
                    'overtime_hours' => round($totalOvertimeHours, 2),
                    'requested_extra_hours' => round($totalRequestedExtraHours, 2),
                    'approval_requests' => (int)$totalApprovalRequests,
                    'break_minutes' => (int)$totalBreakMinutes
                ],
                'submissions' => $dailyBreakdown,
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

            $stmt = $this->conn->prepare("
                SELECT
                    ws.*,
                    u.username,
                    u.role
                FROM work_submissions ws
                INNER JOIN users u ON u.id = ws.user_id
                WHERE ws.submission_date >= ?
                AND ws.submission_date <= ?
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

            $uniqueDays = [];
            foreach ($dailyBreakdown as $row) {
                $d = $row['date'] ?? null;
                if ($d) $uniqueDays[$d] = true;
            }

            $details = [
                'scope' => 'team',
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'summary' => [
                    'users' => count($userIds),
                    'submission_days' => count($uniqueDays),
                    'submissions' => count($dailyBreakdown),
                    'overtime_hours' => round($totalOvertimeHours, 2),
                    'requested_extra_hours' => round($totalRequestedExtraHours, 2),
                    'approval_requests' => (int)$totalApprovalRequests,
                    'break_minutes' => (int)$totalBreakMinutes,
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

$controller = new UserWorkStatsController();

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
