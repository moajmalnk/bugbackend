<?php
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../PermissionManager.php';

class UserWorkStatsController extends BaseAPI {
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

            // Get current custom month period (6th to 5th of next month)
            $istTimezone = new DateTimeZone('Asia/Kolkata');
            $today = new DateTime('now', $istTimezone);
            $day = (int)$today->format('d');
            
            // Determine current period
            if ($day >= 6) {
                // Current period: 6th of this month to 5th of next month
                $periodStartDate = new DateTime($today->format('Y-m-06'), $istTimezone);
                $periodEndDate = new DateTime($today->format('Y-m-06'), $istTimezone);
                $periodEndDate->modify('+1 month')->modify('-1 day'); // Go to 5th of next month
                
                $periodStart = $periodStartDate->format('Y-m-d');
                $periodEnd = $periodEndDate->format('Y-m-d');
                $periodName = $periodStartDate->format('M') . ' 06 - ' . $periodEndDate->format('M 05');
            } else {
                // Current period: 6th of last month to 5th of this month
                $periodStartDate = new DateTime($today->format('Y-m-06'), $istTimezone);
                $periodStartDate->modify('-1 month');
                $periodEndDate = new DateTime($today->format('Y-m-05'), $istTimezone);
                
                $periodStart = $periodStartDate->format('Y-m-d');
                $periodEnd = $periodEndDate->format('Y-m-d');
                $periodName = $periodStartDate->format('M') . ' 06 - ' . $periodEndDate->format('M 05');
            }
            
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
                    notes
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
            
            foreach ($submissionTasks as $submission) {
                // Count completed tasks (non-empty lines)
                $completedTasks = $submission['completed_tasks'] ?? '';
                $completedLines = array_filter(array_map('trim', explode("\n", $completedTasks)), function($line) {
                    return !empty($line);
                });
                $completed += count($completedLines);
                
                // Count pending tasks (non-empty lines)
                $pendingTasks = $submission['pending_tasks'] ?? '';
                $pendingLines = array_filter(array_map('trim', explode("\n", $pendingTasks)), function($line) {
                    return !empty($line);
                });
                $pending += count($pendingLines);
                
                // Count ongoing tasks (non-empty lines)
                $ongoingTasks = $submission['ongoing_tasks'] ?? '';
                $ongoingLines = array_filter(array_map('trim', explode("\n", $ongoingTasks)), function($line) {
                    return !empty($line);
                });
                $ongoing += count($ongoingLines);
                
                // Count upcoming tasks (notes field, non-empty lines)
                $upcomingTasks = $submission['notes'] ?? '';
                $upcomingLines = array_filter(array_map('trim', explode("\n", $upcomingTasks)), function($line) {
                    return !empty($line);
                });
                $upcoming += count($upcomingLines);
            }
            
            $currentTaskData = [
                'completed' => $completed,
                'pending' => $pending,
                'ongoing' => $ongoing,
                'upcoming' => $upcoming
            ];
            
            // Get last 6 custom periods for trend analysis
            $trendData = [];
            $istTimezone = new DateTimeZone('Asia/Kolkata');
            $currentDate = new DateTime('now', $istTimezone);
            
            // Generate 6 custom periods (6th to 5th of next month)
            for ($i = 0; $i < 6; $i++) {
                if ($i === 0) {
                    // Use the current period we already calculated
                    $periodStartStr = $periodStart;
                    $periodEndStr = $periodEnd;
                    $periodName = $periodName;
                } else {
                    // Go back i months
                    $periodStartDate = new DateTime($currentDate->format('Y-m-06'), $istTimezone);
                    $periodStartDate->modify("-{$i} months");
                    $periodEndDate = new DateTime($currentDate->format('Y-m-06'), $istTimezone);
                    $periodEndDate->modify("-{$i} months")->modify('+1 month')->modify('-1 day');
                    
                    $periodStartStr = $periodStartDate->format('Y-m-d');
                    $periodEndStr = $periodEndDate->format('Y-m-d');
                    $periodName = $periodStartDate->format('M 06') . ' - ' . $periodEndDate->format('M 05');
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
                        notes
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
                
                foreach ($periodSubmissionTasks as $submission) {
                    // Count completed tasks (non-empty lines)
                    $completedTasks = $submission['completed_tasks'] ?? '';
                    $completedLines = array_filter(array_map('trim', explode("\n", $completedTasks)), function($line) {
                        return !empty($line);
                    });
                    $periodCompleted += count($completedLines);
                    
                    // Count pending tasks (non-empty lines)
                    $pendingTasks = $submission['pending_tasks'] ?? '';
                    $pendingLines = array_filter(array_map('trim', explode("\n", $pendingTasks)), function($line) {
                        return !empty($line);
                    });
                    $periodPending += count($pendingLines);
                    
                    // Count ongoing tasks (non-empty lines)
                    $ongoingTasks = $submission['ongoing_tasks'] ?? '';
                    $ongoingLines = array_filter(array_map('trim', explode("\n", $ongoingTasks)), function($line) {
                        return !empty($line);
                    });
                    $periodOngoing += count($ongoingLines);
                    
                    // Count upcoming tasks (notes field, non-empty lines)
                    $upcomingTasks = $submission['notes'] ?? '';
                    $upcomingLines = array_filter(array_map('trim', explode("\n", $upcomingTasks)), function($line) {
                        return !empty($line);
                    });
                    $periodUpcoming += count($upcomingLines);
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
                    'days' => (int)($periodData['days'] ?? 0),
                    'hours' => (float)($periodData['hours'] ?? 0),
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
                    'days' => $totalDays,
                    'hours' => round($totalHours, 1),
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
if (!$userId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'User ID is required']);
    exit();
}

$controller = new UserWorkStatsController();
$controller->getUserWorkStats($userId);
?>
