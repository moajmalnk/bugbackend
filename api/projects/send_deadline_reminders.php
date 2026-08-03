<?php
/**
 * Project deadline / timeline reminders
 *
 * Sends push + email + WhatsApp to project members and admins when timeline
 * milestones are approaching, due today, or (for deadline) overdue by 1 day.
 *
 * Catch-up: if cron missed a day, sends only the most urgent unsent applicable
 * offset (no multi-offset spam). Marks sent only when at least one channel succeeds.
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
require_once __DIR__ . '/../../utils/deadline_reminders.php';

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

$milestones = deadlineReminderMilestones();

$results = [
    'success' => true,
    'date' => $todayStr,
    'timezone' => 'Asia/Kolkata',
    'checked' => 0,
    'sent' => 0,
    'skipped' => 0,
    'emails' => 0,
    'whatsapp' => 0,
    'failed' => 0,
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

    $sql = 'SELECT ' . implode(', ', $select) . " FROM projects WHERE {$where} ORDER BY created_at DESC";
    try {
        $projects = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        // created_at may be missing on some schemas
        $sql = 'SELECT ' . implode(', ', $select) . " FROM projects WHERE {$where}";
        $projects = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    $results['checked'] = count($projects);

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

            $offset = pickDeadlineReminderOffset($conn, $projectId, $key, $milestoneDate, $diffDays);
            if ($offset === null) {
                $results['skipped']++;
                continue;
            }

            try {
                $channelResult = sendDeadlineReminderChannels(
                    $conn,
                    $projectId,
                    $projectName,
                    $key,
                    $label,
                    $milestoneDate,
                    $offset,
                    null,
                    false,
                    $diffDays
                );

                $marked = markDeadlineReminderSent(
                    $conn,
                    $projectId,
                    $key,
                    $offset,
                    $milestoneDate,
                    $channelResult
                );

                if ($marked) {
                    $results['sent']++;
                    $results['emails'] += (int) ($channelResult['email_count'] ?? 0);
                    $results['whatsapp'] += (int) ($channelResult['whatsapp_count'] ?? 0);
                    $results['details'][] = [
                        'project_id' => $projectId,
                        'project' => $projectName,
                        'milestone' => $key,
                        'date' => $milestoneDate,
                        'offset' => $offset,
                        'push' => !empty($channelResult['push_ok']),
                        'emails' => (int) ($channelResult['email_count'] ?? 0),
                        'whatsapp' => (int) ($channelResult['whatsapp_count'] ?? 0),
                    ];
                } else {
                    $results['failed']++;
                    $results['errors'][] = [
                        'project_id' => $projectId,
                        'milestone' => $key,
                        'offset' => $offset,
                        'error' => implode('; ', $channelResult['errors'] ?? ['All channels failed']),
                    ];
                }
            } catch (Throwable $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'project_id' => $projectId,
                    'milestone' => $key,
                    'offset' => $offset,
                    'error' => $e->getMessage(),
                ];
                error_log('Deadline reminder failed: ' . $e->getMessage());
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
