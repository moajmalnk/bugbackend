<?php
require_once __DIR__ . '/../BaseAPI.php';

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
            $currentUserRole = $decoded->role ?? 'user';
            $currentUserId = $decoded->user_id;
            
            // Only allow admins to view other users' stats, or users to view their own
            if ($currentUserRole !== 'admin' && $currentUserId !== $userId) {
                $this->sendJsonResponse(403, 'Access denied');
                return;
            }

            // Get current custom month period (6th to 5th of next month)
            $today = new DateTime();
            $day = (int)$today->format('d');
            
            // Determine current period
            if ($day >= 6) {
                // Current period: 6th of this month to 5th of next month
                $periodStartDate = new DateTime($today->format('Y-m-06'));
                $periodEndDate = new DateTime($today->format('Y-m-06'));
                $periodEndDate->modify('+1 month')->modify('-1 day'); // Go to 5th of next month
                
                $periodStart = $periodStartDate->format('Y-m-d');
                $periodEnd = $periodEndDate->format('Y-m-d');
                $periodName = $periodStartDate->format('M') . ' 06 - ' . $periodEndDate->format('M 05');
            } else {
                // Current period: 6th of last month to 5th of this month
                $periodStartDate = new DateTime($today->format('Y-m-06'));
                $periodStartDate->modify('-1 month');
                $periodEndDate = new DateTime($today->format('Y-m-05'));
                
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
            
            // Get task counts for current period
            $taskStmt = $this->conn->prepare("
                SELECT 
                    COUNT(CASE WHEN status = 'done' AND updated_at >= ? AND updated_at <= ? THEN 1 END) as completed,
                    COUNT(CASE WHEN status = 'todo' AND created_at >= ? AND created_at <= ? THEN 1 END) as pending,
                    COUNT(CASE WHEN status = 'in_progress' AND created_at >= ? AND created_at <= ? THEN 1 END) as ongoing,
                    COUNT(CASE WHEN status = 'todo' AND created_at >= ? AND created_at <= ? AND due_date > ? THEN 1 END) as upcoming
                FROM user_tasks 
                WHERE user_id = ?
            ");
            $taskStmt->execute([
                $periodStart . ' 00:00:00', $periodEnd . ' 23:59:59', // completed
                $periodStart . ' 00:00:00', $periodEnd . ' 23:59:59', // pending
                $periodStart . ' 00:00:00', $periodEnd . ' 23:59:59', // ongoing
                $periodStart . ' 00:00:00', $periodEnd . ' 23:59:59', $periodEnd, // upcoming
                $userId
            ]);
            $currentTaskData = $taskStmt->fetch(PDO::FETCH_ASSOC);
            
            // Get last 6 custom periods for trend analysis
            $trendData = [];
            $currentDate = new DateTime();
            
            // Generate 6 custom periods (6th to 5th of next month)
            for ($i = 0; $i < 6; $i++) {
                if ($i === 0) {
                    // Use the current period we already calculated
                    $periodStartStr = $periodStart;
                    $periodEndStr = $periodEnd;
                    $periodName = $periodName;
                } else {
                    // Go back i months
                    $periodStartDate = new DateTime($currentDate->format('Y-m-06'));
                    $periodStartDate->modify("-{$i} months");
                    $periodEndDate = new DateTime($currentDate->format('Y-m-06'));
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
                
                // Get task counts for this period
                $taskStmt = $this->conn->prepare("
                    SELECT 
                        COUNT(CASE WHEN status = 'done' AND updated_at >= ? AND updated_at <= ? THEN 1 END) as completed,
                        COUNT(CASE WHEN status = 'todo' AND created_at >= ? AND created_at <= ? THEN 1 END) as pending,
                        COUNT(CASE WHEN status = 'in_progress' AND created_at >= ? AND created_at <= ? THEN 1 END) as ongoing,
                        COUNT(CASE WHEN status = 'todo' AND created_at >= ? AND created_at <= ? AND due_date > ? THEN 1 END) as upcoming
                    FROM user_tasks 
                    WHERE user_id = ?
                ");
                $taskStmt->execute([
                    $periodStartStr . ' 00:00:00', $periodEndStr . ' 23:59:59', // completed
                    $periodStartStr . ' 00:00:00', $periodEndStr . ' 23:59:59', // pending
                    $periodStartStr . ' 00:00:00', $periodEndStr . ' 23:59:59', // ongoing
                    $periodStartStr . ' 00:00:00', $periodEndStr . ' 23:59:59', $periodEndStr, // upcoming
                    $userId
                ]);
                $taskData = $taskStmt->fetch(PDO::FETCH_ASSOC);
                
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
