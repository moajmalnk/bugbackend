<?php
require_once __DIR__ . '/../BaseAPI.php';

class WorkSubmissionController extends BaseAPI {
    public function submit($payload) {
        try {
            $decoded = $this->validateToken();
            $userId = $decoded->user_id;
            
            // Debug logging to verify user isolation
            error_log("🔍 WorkSubmissionController::submit - User ID: " . $userId . ", Username: " . ($decoded->username ?? 'unknown') . ", Date: " . ($payload['submission_date'] ?? 'no date'));

            $date = $payload['submission_date'] ?? date('Y-m-d');
            // Do not allow future dates
            if (strtotime($date) > strtotime(date('Y-m-d'))) {
                return $this->sendJsonResponse(400, 'Future dates are not allowed');
            }
            $start = isset($payload['start_time']) && trim($payload['start_time']) !== '' ? $payload['start_time'] : null; // empty string -> NULL for TIME column
            $hours = isset($payload['hours_today']) ? (float)$payload['hours_today'] : 0;
            $days = isset($payload['total_working_days']) ? (int)$payload['total_working_days'] : null;
            $cumulative = isset($payload['total_hours_cumulative']) ? (float)$payload['total_hours_cumulative'] : null;
            $completed = $payload['completed_tasks'] ?? null;
            $pending = $payload['pending_tasks'] ?? null;
            $notes = $payload['notes'] ?? null;
            $ongoing = $payload['ongoing_tasks'] ?? null;

            // Auto-migrate: add ongoing_tasks column if missing
            try {
                $check = $this->conn->query("SHOW COLUMNS FROM work_submissions LIKE 'ongoing_tasks'");
                if ($check->rowCount() === 0) {
                    $this->conn->exec("ALTER TABLE work_submissions ADD COLUMN ongoing_tasks MEDIUMTEXT AFTER pending_tasks");
                }
            } catch (Exception $e) {
                // ignore; migration may fail if no permissions
            }

            $sql = "INSERT INTO work_submissions (user_id, submission_date, start_time, hours_today, total_working_days, total_hours_cumulative, completed_tasks, pending_tasks, ongoing_tasks, notes)
                    VALUES (?,?,?,?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE start_time=VALUES(start_time), hours_today=VALUES(hours_today), total_working_days=VALUES(total_working_days),
                    total_hours_cumulative=VALUES(total_hours_cumulative), completed_tasks=VALUES(completed_tasks), pending_tasks=VALUES(pending_tasks), ongoing_tasks=VALUES(ongoing_tasks), notes=VALUES(notes)";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$userId, $date, $start, $hours, $days, $cumulative, $completed, $pending, $ongoing, $notes]);

            error_log("🔍 WorkSubmissionController::submit - Saved submission for user: " . $userId . " on date: " . $date);
            $this->sendJsonResponse(200, 'Submission saved');
        } catch (Exception $e) {
            error_log('WorkSubmission submit error: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to save submission');
        }
    }

    public function mySubmissions($q) {
        $decoded = $this->validateToken();
        $userId = $decoded->user_id;
        $from = $q['from'] ?? date('Y-m-01');
        $to = $q['to'] ?? date('Y-m-t');
        
        // Debug logging to verify user isolation and impersonation
        $impersonationInfo = isset($decoded->impersonated) && $decoded->impersonated ? " (IMPERSONATED)" : "";
        $roleInfo = isset($decoded->role) ? " Role: " . $decoded->role : "";
        error_log("🔍 WorkSubmissionController::mySubmissions - User ID: " . $userId . ", Username: " . ($decoded->username ?? 'unknown') . $impersonationInfo . $roleInfo . ", Date range: $from to $to");

        $sql = "SELECT * FROM work_submissions WHERE user_id = ? AND submission_date BETWEEN ? AND ? ORDER BY submission_date DESC";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$userId, $from, $to]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("🔍 WorkSubmissionController::mySubmissions - Found " . count($rows) . " submissions for user: " . $userId . $impersonationInfo);
        $this->sendJsonResponse(200, 'OK', $rows);
    }

    public function deleteSubmission($payload) {
        try {
            $decoded = $this->validateToken();
            $userId = $decoded->user_id;
            $id = $payload['id'] ?? null;
            $date = $payload['submission_date'] ?? null;

            if (!$id && !$date) {
                return $this->sendJsonResponse(400, 'id or submission_date is required');
            }

            if ($id) {
                $stmt = $this->conn->prepare("DELETE FROM work_submissions WHERE id = ? AND user_id = ?");
                $stmt->execute([$id, $userId]);
            } else {
                $stmt = $this->conn->prepare("DELETE FROM work_submissions WHERE submission_date = ? AND user_id = ?");
                $stmt->execute([$date, $userId]);
            }

            $this->sendJsonResponse(200, 'Submission deleted');
        } catch (Exception $e) {
            error_log('WorkSubmission delete error: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to delete submission');
        }
    }

    public function templateText($q) {
        try {
            $decoded = $this->validateToken();
            $userId = $decoded->user_id;

            $date = $q['date'] ?? date('Y-m-d');
            $since = $q['since'] ?? null;

            $stmt = $this->conn->prepare("SELECT username, name FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $name = $u['name'] ?? ($u['username'] ?? 'User');

            $stmt = $this->conn->prepare("SELECT * FROM work_submissions WHERE user_id = ? AND submission_date = ?");
            $stmt->execute([$userId, $date]);
            $sub = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $hours = number_format((float)($sub['hours_today'] ?? 0), 0);
            $startRaw = $sub['start_time'] ?? null;
            // Format 24h time to 12h with AM/PM for readability
            if ($startRaw) {
                $start = date('h:i A', strtotime($startRaw));
            } else {
                $start = '----';
            }
            $completed = trim((string)($sub['completed_tasks'] ?? ''));
            $pending = trim((string)($sub['pending_tasks'] ?? ''));
            $upcoming = trim((string)($sub['notes'] ?? ''));
            $ongoing = trim((string)($sub['ongoing_tasks'] ?? ''));

            if ($since) {
                $stmt = $this->conn->prepare("SELECT COUNT(*) days, COALESCE(SUM(hours_today),0) hours FROM work_submissions WHERE user_id = ? AND submission_date >= ?");
                $stmt->execute([$userId, $since]);
            } else {
                $stmt = $this->conn->prepare("SELECT COUNT(*) days, COALESCE(SUM(hours_today),0) hours FROM work_submissions WHERE user_id = ?");
                $stmt->execute([$userId]);
            }
            $agg = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['days' => 0, 'hours' => 0];
            $days = (int)$agg['days'];
            $totalHours = (float)$agg['hours'];

            $count = function($txt) {
                $lines = array_filter(array_map('trim', explode("\n", (string)$txt)), function($x){ return $x !== ''; });
                return count($lines);
            };
            $completedCount = $count($completed);
            $pendingCount = $count($pending);
            $ongoingCount = $count($ongoing);
            $upcomingCount = $count($upcoming);

            $completedLines = ($completedCount > 0 ? " (".$completedCount.")" : "");
            $pendingLines = ($pendingCount > 0 ? " (".$pendingCount.")" : "");
            $ongoingLines = ($ongoingCount > 0 ? " (".$ongoingCount.")" : "");
            $upcomingLines = ($upcomingCount > 0 ? " (".$upcomingCount.")" : "");

            $text =
"🧾 CODO Daily Work Update – $name
📅 Date: ".date('j/n/Y l', strtotime($date))."
🕘 Start Time: $start
⏱ Today’s Working Hours: $hours Hours
📊 Total Working Days".($since ? " (Since ".date('j F', strtotime($since)).")" : "").": $days ".($days===1?'Day':'Days')."
🧮 Total Hours Completed : $totalHours hours

✅ Completed$completedLines\n".($completed ? "\n$completed" : '')."

⌛ Pending$pendingLines\n".($pending ? "\n$pending" : '')."

🔄 Ongoing$ongoingLines\n".($ongoing ? "\n$ongoing" : '')."

🔥 Upcoming$upcomingLines\n".($upcoming ? "\n$upcoming" : '');

            $this->sendJsonResponse(200, 'OK', ['text' => $text]);
        } catch (Exception $e) {
            error_log('WorkSubmission templateText error: ' . $e->getMessage());
            // Fallback template if DB lookup fails (avoid 500s in UI)
            $date = $q['date'] ?? date('Y-m-d');
            $name = 'User';
            $fallback = "🧾 CODO Daily Work Update – $name\n".
                        "📅 Date: ".date('j/n/Y', strtotime($date))."\n".
                        "🕘 Start Time: ----\n".
                        "⏱ Today’s Working Hours: 0 Hours\n".
                        "📊 Total Working Days: 0 Days\n".
                        "🧮 Total Hours Completed : 0 hours\n\n".
                        "✅ Completed Tasks:\n\nPending\n";
            $this->sendJsonResponse(200, 'OK', ['text' => $fallback]);
        }
    }
}
?>


