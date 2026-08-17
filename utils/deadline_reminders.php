<?php
/**
 * Why: Shared deadline/timeline reminder helpers so cron and admin test-send
 * use one recipient model, catch-up picker, and channel orchestration.
 */

require_once __DIR__ . '/email.php';
require_once __DIR__ . '/whatsapp.php';

/**
 * Timeline milestone columns → display labels.
 *
 * @return array<string, string>
 */
function deadlineReminderMilestones(): array
{
    return [
        'deadline_date' => 'Deadline Date',
        'expected_publish_date' => 'Expected Publish',
        'testing_start_date' => 'Testing Start',
        'testing_end_date' => 'Testing End',
        'frontend_finish_date' => 'Frontend Finish',
        'backend_finish_date' => 'Backend Finish',
        'tester_compliance_complete_date' => 'Tester Compliance Complete',
        'developer_compliance_complete_date' => 'Developer Compliance Complete',
    ];
}

/**
 * Default reminder offsets (days relative to milestone).
 * @return int[]
 */
function deadlineReminderDefaultOffsets(): array
{
    return [7, 3, 1, 0];
}

/**
 * Extra offsets for project deadline only (1 day overdue).
 * @return int[]
 */
function deadlineReminderExtraOffsets(string $milestoneKey): array
{
    return $milestoneKey === 'deadline_date' ? [-1] : [];
}

/**
 * Offsets applicable to a milestone key.
 * @return int[]
 */
function deadlineReminderOffsetsForMilestone(string $milestoneKey): array
{
    return array_merge(deadlineReminderDefaultOffsets(), deadlineReminderExtraOffsets($milestoneKey));
}

/**
 * Ensure project_deadline_reminders exists with channel telemetry columns.
 */
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
            email_count INT UNSIGNED NOT NULL DEFAULT 0,
            whatsapp_count INT UNSIGNED NOT NULL DEFAULT 0,
            push_ok TINYINT(1) NOT NULL DEFAULT 0,
            status ENUM('sent', 'partial', 'failed') NOT NULL DEFAULT 'sent',
            error_summary TEXT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_project_deadline_reminder (project_id, milestone_key, reminder_offset, milestone_date),
            KEY idx_deadline_reminders_project (project_id),
            KEY idx_deadline_reminders_sent (sent_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $existing = [];
    $res = $conn->query('SHOW COLUMNS FROM project_deadline_reminders');
    if ($res) {
        while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
            $existing[] = $row['Field'];
        }
    }

    $columns = [
        'email_count' => "INT UNSIGNED NOT NULL DEFAULT 0",
        'whatsapp_count' => "INT UNSIGNED NOT NULL DEFAULT 0",
        'push_ok' => "TINYINT(1) NOT NULL DEFAULT 0",
        'status' => "ENUM('sent', 'partial', 'failed') NOT NULL DEFAULT 'sent'",
        'error_summary' => 'TEXT NULL',
    ];
    foreach ($columns as $name => $definition) {
        if (in_array($name, $existing, true)) {
            continue;
        }
        try {
            $conn->exec("ALTER TABLE project_deadline_reminders ADD COLUMN `{$name}` {$definition}");
        } catch (Throwable $e) {
            error_log("ensureDeadlineReminderTable skipped {$name}: " . $e->getMessage());
        }
    }
}

/**
 * Ensure timeline date columns exist on projects.
 */
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
        'tester_compliance_complete_date' => 'DATE DEFAULT NULL',
        'developer_compliance_complete_date' => 'DATE DEFAULT NULL',
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

/**
 * Role-neutral project deep link for reminder CTAs.
 */
function deadlineReminderProjectUrl(string $projectId): string
{
    $base = null;
    if (class_exists('Environment')) {
        $base = Environment::get('FRONTEND_BASE_URL');
    }
    if (!$base && function_exists('getenv')) {
        $env = getenv('FRONTEND_BASE_URL');
        $base = $env !== false && $env !== '' ? $env : null;
    }
    if (!$base) {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $base = (strpos($host, 'localhost') !== false || $host === '' || $host === '127.0.0.1')
            ? 'http://localhost:8080'
            : 'https://bugs.bugricer.com';
    }
    return rtrim((string) $base, '/') . '/projects/' . rawurlencode($projectId);
}

/**
 * Whether global email notifications are enabled in settings.
 */
function isDeadlineReminderEmailEnabled(PDO $conn): bool
{
    try {
        $stmt = $conn->prepare("SELECT value FROM settings WHERE key_name = 'email_notifications_enabled' LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return true;
        }
        return ((string) ($row['value'] ?? '1')) === '1';
    } catch (Throwable $e) {
        error_log('isDeadlineReminderEmailEnabled: ' . $e->getMessage());
        return true;
    }
}

/**
 * Resolve unique active recipients: project members ∪ all admins.
 *
 * @return array<int, array{id: string, username: string, email: string|null, phone: string|null}>
 */
function resolveDeadlineReminderRecipients(PDO $conn, string $projectId): array
{
    $byId = [];

    $adminStmt = $conn->prepare(
        "SELECT id, username, email, phone FROM users
         WHERE account_active = 1
           AND (role = 'admin' OR role_id = 1)"
    );
    $adminStmt->execute();
    foreach ($adminStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (string) ($row['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $byId[$id] = [
            'id' => $id,
            'username' => (string) ($row['username'] ?? 'there'),
            'email' => trim((string) ($row['email'] ?? '')) ?: null,
            'phone' => trim((string) ($row['phone'] ?? '')) ?: null,
        ];
    }

    $memberStmt = $conn->prepare(
        "SELECT u.id, u.username, u.email, u.phone
         FROM project_members pm
         INNER JOIN users u ON u.id = pm.user_id
         WHERE pm.project_id = ?
           AND u.account_active = 1"
    );
    $memberStmt->execute([$projectId]);
    foreach ($memberStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (string) ($row['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $byId[$id] = [
            'id' => $id,
            'username' => (string) ($row['username'] ?? 'there'),
            'email' => trim((string) ($row['email'] ?? '')) ?: null,
            'phone' => trim((string) ($row['phone'] ?? '')) ?: null,
        ];
    }

    return array_values($byId);
}

function reminderAlreadySent(
    PDO $conn,
    string $projectId,
    string $milestoneKey,
    int $offset,
    string $milestoneDate
): bool {
    $stmt = $conn->prepare(
        'SELECT 1 FROM project_deadline_reminders
         WHERE project_id = ? AND milestone_key = ? AND reminder_offset = ? AND milestone_date = ?
           AND status IN (\'sent\', \'partial\')
         LIMIT 1'
    );
    $stmt->execute([$projectId, $milestoneKey, $offset, $milestoneDate]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Inclusive day window for an offset relative to sibling offsets.
 * e.g. with [7,3,1,0,-1]: offset 7 → [4,7], offset 3 → [2,3], overdue -1 → [-1,-1].
 *
 * @param int[] $allOffsets
 * @return array{0: int, 1: int} [lower, upper]
 */
function deadlineReminderOffsetWindow(int $offset, array $allOffsets): array
{
    $sorted = array_values(array_unique(array_map('intval', $allOffsets)));
    rsort($sorted, SORT_NUMERIC);

    $nextSmaller = null;
    foreach ($sorted as $o) {
        if ($o < $offset) {
            $nextSmaller = $o;
            break;
        }
    }

    if ($offset < 0) {
        // Overdue bucket: exact day only (one-shot); catch-up via pickDeadlineReminderOffset
        return [$offset, $offset];
    }

    $lower = $nextSmaller !== null ? $nextSmaller + 1 : $offset;
    return [$lower, $offset];
}

/**
 * Catch-up without spam: among offsets whose day-window contains diffDays
 * (or overdue catch-up for −1 when past due), return the most urgent unsent offset.
 */
function pickDeadlineReminderOffset(
    PDO $conn,
    string $projectId,
    string $milestoneKey,
    string $milestoneDate,
    int $diffDays
): ?int {
    $offsets = deadlineReminderOffsetsForMilestone($milestoneKey);
    $candidates = [];

    foreach ($offsets as $offset) {
        $offset = (int) $offset;
        if (reminderAlreadySent($conn, $projectId, $milestoneKey, $offset, $milestoneDate)) {
            continue;
        }

        if ($offset < 0) {
            // Overdue: fire on exact day, or once as catch-up if already past and never sent
            if ($diffDays === $offset || ($diffDays < $offset && $diffDays <= -1)) {
                $candidates[] = $offset;
            }
            continue;
        }

        [$lower, $upper] = deadlineReminderOffsetWindow($offset, $offsets);
        if ($diffDays >= $lower && $diffDays <= $upper) {
            $candidates[] = $offset;
        }
    }

    if (empty($candidates)) {
        return null;
    }
    return (int) min($candidates);
}

/**
 * Human label for an offset (e.g. "in 3 days", "due today", "1 day overdue").
 */
function deadlineReminderOffsetLabel(int $offset): string
{
    if ($offset > 0) {
        return $offset === 1 ? 'tomorrow' : "in {$offset} days";
    }
    if ($offset === 0) {
        return 'due today';
    }
    $days = abs($offset);
    return $days === 1 ? '1 day overdue' : "{$days} days overdue";
}

/**
 * Persist a successful/partial reminder send. Skips insert when nothing succeeded.
 *
 * @param array{email_count?: int, whatsapp_count?: int, push_ok?: bool, errors?: string[]} $channelResult
 * @return bool true if a row was written
 */
function markDeadlineReminderSent(
    PDO $conn,
    string $projectId,
    string $milestoneKey,
    int $offset,
    string $milestoneDate,
    array $channelResult
): bool {
    $emailCount = (int) ($channelResult['email_count'] ?? 0);
    $whatsappCount = (int) ($channelResult['whatsapp_count'] ?? 0);
    $pushOk = !empty($channelResult['push_ok']);
    $errors = $channelResult['errors'] ?? [];

    $channelsOk = 0;
    if ($emailCount > 0) {
        $channelsOk++;
    }
    if ($whatsappCount > 0) {
        $channelsOk++;
    }
    if ($pushOk) {
        $channelsOk++;
    }

    if ($channelsOk === 0) {
        return false;
    }

    $attempted = 0;
    if (array_key_exists('email_attempted', $channelResult) ? $channelResult['email_attempted'] : true) {
        $attempted++;
    }
    if (array_key_exists('whatsapp_attempted', $channelResult) ? $channelResult['whatsapp_attempted'] : true) {
        $attempted++;
    }
    $attempted++; // push always attempted when orchestration runs

    $status = $channelsOk >= 2 || ($channelsOk === 1 && empty($errors)) ? 'sent' : 'partial';
    // Prefer partial when some channels failed while at least one succeeded
    if (!empty($errors) && $channelsOk > 0) {
        $status = 'partial';
    }
    if ($channelsOk >= 3) {
        $status = 'sent';
    }

    $errorSummary = !empty($errors) ? implode('; ', array_slice($errors, 0, 5)) : null;

    $stmt = $conn->prepare(
        'INSERT INTO project_deadline_reminders
         (project_id, milestone_key, reminder_offset, milestone_date, sent_at,
          email_count, whatsapp_count, push_ok, status, error_summary)
         VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           sent_at = VALUES(sent_at),
           email_count = VALUES(email_count),
           whatsapp_count = VALUES(whatsapp_count),
           push_ok = VALUES(push_ok),
           status = VALUES(status),
           error_summary = VALUES(error_summary)'
    );
    $stmt->execute([
        $projectId,
        $milestoneKey,
        $offset,
        $milestoneDate,
        $emailCount,
        $whatsappCount,
        $pushOk ? 1 : 0,
        $status,
        $errorSummary,
    ]);

    return true;
}

/**
 * Send email channel to recipient list. Returns count of successful sends.
 *
 * @param array<int, array{id: string, username: string, email: string|null, phone: string|null}> $recipients
 */
function sendDeadlineReminderEmails(
    PDO $conn,
    array $recipients,
    string $projectName,
    string $milestoneLabel,
    string $milestoneDate,
    int $offset,
    string $projectUrl
): int {
    if (!isDeadlineReminderEmailEnabled($conn)) {
        return 0;
    }

    $sent = 0;
    $seen = [];
    foreach ($recipients as $row) {
        $email = trim((string) ($row['email'] ?? ''));
        if ($email === '') {
            continue;
        }
        $key = strtolower($email);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        try {
            $ok = sendProjectDeadlineReminderEmail(
                $email,
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
            error_log('Deadline reminder email failed for ' . $email . ': ' . $e->getMessage());
        }
    }
    return $sent;
}

/**
 * Orchestrate push + email + WhatsApp for one milestone reminder.
 *
 * @param array<int, array{id: string, username: string, email: string|null, phone: string|null}>|null $recipients
 *        When null, resolves members + admins. When provided (test send), uses as-is.
 * @param int|null $messageOffset Days used in copy (actual days-until when catching up). Defaults to $offset.
 * @return array{push_ok: bool, email_count: int, whatsapp_count: int, errors: string[], recipient_count: int}
 */
function sendDeadlineReminderChannels(
    PDO $conn,
    string $projectId,
    string $projectName,
    string $milestoneKey,
    string $milestoneLabel,
    string $milestoneDate,
    int $offset,
    ?array $recipients = null,
    bool $skipPush = false,
    ?int $messageOffset = null
): array {
    $result = [
        'push_ok' => false,
        'email_count' => 0,
        'whatsapp_count' => 0,
        'errors' => [],
        'recipient_count' => 0,
        'email_attempted' => true,
        'whatsapp_attempted' => true,
    ];

    $copyOffset = $messageOffset !== null ? (int) $messageOffset : $offset;

    if ($recipients === null) {
        $recipients = resolveDeadlineReminderRecipients($conn, $projectId);
    }
    $result['recipient_count'] = count($recipients);

    if (empty($recipients)) {
        $result['errors'][] = 'No recipients';
        return $result;
    }

    $projectUrl = deadlineReminderProjectUrl($projectId);
    $userIds = array_map(static function ($r) {
        return (string) $r['id'];
    }, $recipients);

    if (!$skipPush) {
        try {
            require_once __DIR__ . '/../api/NotificationManager.php';
            $notifier = NotificationManager::getInstance();
            $pushResult = $notifier->notifyProjectDeadlineReminder(
                $projectId,
                $projectName,
                $milestoneKey,
                $milestoneLabel,
                $milestoneDate,
                $copyOffset,
                $userIds
            );
            $result['push_ok'] = $pushResult !== false && $pushResult !== 0;
            if (!$result['push_ok']) {
                $result['errors'][] = 'Push failed or no recipients';
            }
        } catch (Throwable $e) {
            $result['errors'][] = 'Push: ' . $e->getMessage();
            error_log('Deadline reminder push failed: ' . $e->getMessage());
        }
    }

    try {
        $result['email_attempted'] = isDeadlineReminderEmailEnabled($conn);
        $result['email_count'] = sendDeadlineReminderEmails(
            $conn,
            $recipients,
            $projectName,
            $milestoneLabel,
            $milestoneDate,
            $copyOffset,
            $projectUrl
        );
        if ($result['email_attempted'] && $result['email_count'] === 0) {
            $hasEmail = false;
            foreach ($recipients as $r) {
                if (!empty($r['email'])) {
                    $hasEmail = true;
                    break;
                }
            }
            if ($hasEmail) {
                $result['errors'][] = 'Email: none delivered';
            }
        }
    } catch (Throwable $e) {
        $result['errors'][] = 'Email: ' . $e->getMessage();
        error_log('Deadline reminder email batch failed: ' . $e->getMessage());
    }

    try {
        $result['whatsapp_count'] = sendProjectDeadlineReminderWhatsApp(
            $recipients,
            $projectName,
            $milestoneLabel,
            $milestoneDate,
            $copyOffset,
            $projectUrl
        );
        if ($result['whatsapp_count'] === 0) {
            $hasPhone = false;
            foreach ($recipients as $r) {
                if (!empty($r['phone'])) {
                    $hasPhone = true;
                    break;
                }
            }
            if ($hasPhone) {
                $result['errors'][] = 'WhatsApp: none delivered';
            }
        }
    } catch (Throwable $e) {
        $result['errors'][] = 'WhatsApp: ' . $e->getMessage();
        error_log('Deadline reminder WhatsApp failed: ' . $e->getMessage());
    }

    return $result;
}
