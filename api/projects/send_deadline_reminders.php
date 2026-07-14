<?php
/**
 * Project deadline / timeline reminders
 *
 * Sends push + email to project members and admins when timeline milestones
 * are approaching, due today, or (for deadline) overdue by 1 day.
 *
 * Cron (recommended daily ~08:00 Asia/Kolkata):
 *   0 8 * * * php /path/to/bugbackend/api/projects/send_deadline_reminders.php
 *
 * HTTP (optional):
 *   GET /api/projects/send_deadline_reminders.php?token=YOUR_SECRET
 *
 * Set DEADLINE_REMINDER_SECRET in backend/.env for HTTP access.
 */

require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/environment.php';
require_once __DIR__ . '/../NotificationManager.php';
require_once __DIR__ . '/../../utils/email.php';

if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
}

$isCli = (php_sapi_name() === 'cli');
$secret = getenv('DEADLINE_REMINDER_SECRET') ?: (Environment::get('DEADLINE_REMINDER_SECRET') ?? '');
if (!$isCli) {
    $token = $_GET['token'] ?? ($_SERVER['HTTP_X_CRON_TOKEN'] ?? '');
    if ($secret === '' || !hash_equals((string) $secret, (string) $token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
}

$tz = new DateTimeZone('Asia/Kolkata');
$today = new DateTime('now', $tz);
$today->setTime(0, 0, 0);
$todayStr = $today->format('Y-m-d');

$milestones = [
    'deadline_date' => 'Deadline Date',
    'expected_publish_date' => 'Expected Publish',
    'testing_start_date' => 'Testing Start',
    'testing_end_date' => 'Testing End',
    'frontend_finish_date' => 'Frontend Finish',
    'backend_finish_date' => 'Backend Finish',
];

// Reminder offsets in days relative to milestone date
$defaultOffsets = [7, 3, 1, 0];
$deadlineExtraOffsets = [-1]; // 1 day overdue for project deadline only

$results = [
    'success' => true,
    'date' => $todayStr,
    'timezone' => 'Asia/Kolkata',
    'checked' => 0,
    'sent' => 0,
    'skipped' => 0,
    'emails' => 0,
    'errors' => [],
    'details' => [],
];

try {
    $conn = Database::getInstance()->getConnection();
    ensureDeadlineReminderTable($conn);
    ensureProjectTimelineColumns($conn);

    $projectCols = [];
    $colRes = $conn->query('SHOW COLUMNS FROM projects');
    if ($colRes) {
        while ($row = $colRes->fetch(PDO::FETCH_ASSOC)) {
            $projectCols[] = $row['Field'];
        }
    }

    $select = ['id', 'name', 'status'];
    foreach (array_keys($milestones) as $col) {
        if (in_array($col, $projectCols, true)) {
            $select[] = $col;
        }
    }

    $hasIsActive = in_array('is_active', $projectCols, true);
    if ($hasIsActive) {
        $select[] = 'is_active';
    }

    $where = "status NOT IN ('completed', 'archived')";
    if ($hasIsActive) {
        $where .= ' AND (is_active IS NULL OR is_active = 1)';
    }

    $sql = 'SELECT ' . implode(', ', $select) . " FROM projects WHERE {$where}";
    $projects = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $results['checked'] = count($projects);

    $notifier = NotificationManager::getInstance();

    foreach ($projects as $project) {
        $projectId = (string) ($project['id'] ?? '');
        $projectName = trim((string) ($project['name'] ?? '')) ?: 'Untitled project';
        if ($projectId === '') {
            continue;
        }

        foreach ($milestones as $key => $label) {
            if (!array_key_exists($key, $project)) {
                continue;
            }
            $rawDate = $project[$key] ?? null;
            if ($rawDate === null || trim((string) $rawDate) === '' || $rawDate === '0000-00-00') {
                continue;
            }

            $milestoneDate = substr((string) $rawDate, 0, 10);
            $milestoneDt = DateTime::createFromFormat('Y-m-d', $milestoneDate, $tz);
            if (!$milestoneDt) {
                continue;
            }
            $milestoneDt->setTime(0, 0, 0);
            $diffDays = (int) $today->diff($milestoneDt)->format('%r%a');
            // $diffDays > 0 means milestone is in the future; 0 today; < 0 past

            $offsets = $defaultOffsets;
            if ($key === 'deadline_date') {
                $offsets = array_merge($offsets, $deadlineExtraOffsets);
            }

            foreach ($offsets as $offset) {
                // Fire when days-until-milestone equals this offset
                if ($diffDays !== (int) $offset) {
                    continue;
                }

                if (reminderAlreadySent($conn, $projectId, $key, (int) $offset, $milestoneDate)) {
                    $results['skipped']++;
                    continue;
                }

                try {
                    $pushOk = $notifier->notifyProjectDeadlineReminder(
                        $projectId,
                        $projectName,
                        $key,
                        $label,
                        $milestoneDate,
                        (int) $offset
                    );

                    $emailCount = sendReminderEmailsForProject(
                        $conn,
                        $projectId,
                        $projectName,
                        $label,
                        $milestoneDate,
                        (int) $offset
                    );

                    markReminderSent($conn, $projectId, $key, (int) $offset, $milestoneDate);

                    $results['sent']++;
                    $results['emails'] += $emailCount;
                    $results['details'][] = [
                        'project_id' => $projectId,
                        'project' => $projectName,
                        'milestone' => $key,
                        'date' => $milestoneDate,
                        'offset' => (int) $offset,
                        'push' => (bool) $pushOk,
                        'emails' => $emailCount,
                    ];
                } catch (Throwable $e) {
                    $results['errors'][] = [
                        'project_id' => $projectId,
                        'milestone' => $key,
                        'offset' => (int) $offset,
                        'error' => $e->getMessage(),
                    ];
                    error_log('Deadline reminder failed: ' . $e->getMessage());
                }
            }
        }
    }

    if (!empty($results['errors'])) {
        $results['success'] = false;
    }

    echo json_encode($results, JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    error_log('send_deadline_reminders fatal: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'date' => $todayStr,
    ]);
}

function ensureDeadlineReminderTable(PDO $conn): void
{
    $conn->exec(
        "CREATE TABLE IF NOT EXISTS project_deadline_reminders (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id VARCHAR(36) NOT NULL,
            milestone_key VARCHAR(64) NOT NULL,
            reminder_offset INT NOT NULL,
            milestone_date DATE NOT NULL,
            sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_project_deadline_reminder (project_id, milestone_key, reminder_offset, milestone_date),
            KEY idx_deadline_reminders_project (project_id),
            KEY idx_deadline_reminders_sent (sent_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
}

function ensureProjectTimelineColumns(PDO $conn): void
{
    $existing = [];
    $res = $conn->query('SHOW COLUMNS FROM projects');
    if ($res) {
        while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
            $existing[] = $row['Field'];
        }
    }

    $columns = [
        'start_date' => 'DATE DEFAULT NULL',
        'deadline_date' => 'DATE DEFAULT NULL',
        'expected_publish_date' => 'DATE DEFAULT NULL',
        'testing_start_date' => 'DATE DEFAULT NULL',
        'testing_end_date' => 'DATE DEFAULT NULL',
        'frontend_finish_date' => 'DATE DEFAULT NULL',
        'backend_finish_date' => 'DATE DEFAULT NULL',
    ];

    foreach ($columns as $name => $definition) {
        if (in_array($name, $existing, true)) {
            continue;
        }
        try {
            $conn->exec("ALTER TABLE projects ADD COLUMN `{$name}` {$definition}");
        } catch (Throwable $e) {
            error_log("ensureProjectTimelineColumns skipped {$name}: " . $e->getMessage());
        }
    }
}

function reminderAlreadySent(PDO $conn, string $projectId, string $milestoneKey, int $offset, string $milestoneDate): bool
{
    $stmt = $conn->prepare(
        'SELECT 1 FROM project_deadline_reminders
         WHERE project_id = ? AND milestone_key = ? AND reminder_offset = ? AND milestone_date = ?
         LIMIT 1'
    );
    $stmt->execute([$projectId, $milestoneKey, $offset, $milestoneDate]);
    return (bool) $stmt->fetchColumn();
}

function markReminderSent(PDO $conn, string $projectId, string $milestoneKey, int $offset, string $milestoneDate): void
{
    $stmt = $conn->prepare(
        'INSERT IGNORE INTO project_deadline_reminders
         (project_id, milestone_key, reminder_offset, milestone_date, sent_at)
         VALUES (?, ?, ?, ?, NOW())'
    );
    $stmt->execute([$projectId, $milestoneKey, $offset, $milestoneDate]);
}

/**
 * @return int number of emails attempted
 */
function sendReminderEmailsForProject(
    PDO $conn,
    string $projectId,
    string $projectName,
    string $milestoneLabel,
    string $milestoneDate,
    int $offset
): int {
    $recipients = [];

    $adminStmt = $conn->prepare(
        "SELECT id, username, email FROM users
         WHERE account_active = 1
           AND (role = 'admin' OR role_id = 1)
           AND email IS NOT NULL AND TRIM(email) <> ''"
    );
    $adminStmt->execute();
    foreach ($adminStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $recipients[strtolower(trim((string) $row['email']))] = $row;
    }

    $memberStmt = $conn->prepare(
        "SELECT u.id, u.username, u.email
         FROM project_members pm
         INNER JOIN users u ON u.id = pm.user_id
         WHERE pm.project_id = ?
           AND u.account_active = 1
           AND u.email IS NOT NULL AND TRIM(u.email) <> ''"
    );
    $memberStmt->execute([$projectId]);
    foreach ($memberStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $recipients[strtolower(trim((string) $row['email']))] = $row;
    }

    if (empty($recipients)) {
        return 0;
    }

    $base = Environment::get('FRONTEND_BASE_URL');
    if (!$base) {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $base = (strpos($host, 'localhost') !== false || $host === '' || $host === '127.0.0.1')
            ? 'http://localhost:8080'
            : 'https://bugs.bugricer.com';
    }
    $projectUrl = rtrim($base, '/') . '/admin/projects/' . rawurlencode($projectId);

    $sent = 0;
    foreach ($recipients as $row) {
        try {
            $ok = sendProjectDeadlineReminderEmail(
                $row['email'],
                $row['username'] ?? 'there',
                $projectName,
                $milestoneLabel,
                $milestoneDate,
                $offset,
                $projectUrl
            );
            if ($ok) {
                $sent++;
            }
        } catch (Throwable $e) {
            error_log('Deadline reminder email failed for ' . ($row['email'] ?? '') . ': ' . $e->getMessage());
        }
    }

    return $sent;
}
