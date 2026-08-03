<?php
/**
 * POST /api/projects/send_deadline_reminder_test.php
 * Body: { project_id, milestone_key? }
 *
 * Sends a test Email + WhatsApp + push to the calling admin only.
 * Does NOT write production idempotency rows.
 */

require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../utils/deadline_reminders.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$api = new BaseAPI();

try {
    $decoded = $api->validateToken();
    if (!$decoded) {
        exit;
    }

    $role = strtolower(trim((string) ($decoded->role ?? '')));
    if ($role !== 'admin') {
        $api->sendJsonResponse(403, 'Only admins can send test deadline reminders');
        exit;
    }

    $data = $api->getRequestData() ?: [];
    $projectId = trim((string) ($data['project_id'] ?? ''));
    $milestoneKey = trim((string) ($data['milestone_key'] ?? 'deadline_date'));

    if ($projectId === '') {
        $api->sendJsonResponse(400, 'project_id is required');
        exit;
    }

    $milestones = deadlineReminderMilestones();
    $allowedCols = array_keys($milestones);
    if (!in_array($milestoneKey, $allowedCols, true)) {
        $api->sendJsonResponse(400, 'Invalid milestone_key');
        exit;
    }

    $conn = $api->getConnection();
    ensureProjectTimelineColumns($conn);

    $stmt = $conn->prepare(
        "SELECT id, name, `{$milestoneKey}` AS milestone_raw FROM projects WHERE id = ? LIMIT 1"
    );
    $stmt->execute([$projectId]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$project) {
        $api->sendJsonResponse(404, 'Project not found');
        exit;
    }

    $projectName = trim((string) ($project['name'] ?? '')) ?: 'Untitled project';
    $milestoneLabel = $milestones[$milestoneKey];
    $rawDate = $project['milestone_raw'] ?? null;
    $milestoneDate = ($rawDate && trim((string) $rawDate) !== '' && $rawDate !== '0000-00-00')
        ? substr((string) $rawDate, 0, 10)
        : (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');

    $tz = new DateTimeZone('Asia/Kolkata');
    $today = new DateTime('now', $tz);
    $today->setTime(0, 0, 0);
    $milestoneDt = DateTime::createFromFormat('Y-m-d', $milestoneDate, $tz) ?: clone $today;
    $milestoneDt->setTime(0, 0, 0);
    $diffDays = (int) $today->diff($milestoneDt)->format('%r%a');

    // Prefer an applicable offset for realistic copy; fall back to 3-day preview
    $offsets = deadlineReminderOffsetsForMilestone($milestoneKey);
    $messageOffset = 3;
    foreach ($offsets as $off) {
        if ($diffDays === (int) $off) {
            $messageOffset = (int) $off;
            break;
        }
    }
    if ($diffDays !== $messageOffset) {
        // Pick closest planned offset for copy when not on an exact day
        $closest = null;
        $bestDist = PHP_INT_MAX;
        foreach ($offsets as $off) {
            $dist = abs($diffDays - (int) $off);
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $closest = (int) $off;
            }
        }
        $messageOffset = $closest !== null ? $closest : $diffDays;
    }

    $adminId = (string) ($decoded->user_id ?? '');
    if ($adminId === '') {
        $api->sendJsonResponse(400, 'Unable to resolve admin user');
        exit;
    }

    $userStmt = $conn->prepare(
        'SELECT id, username, email, phone FROM users WHERE id = ? AND account_active = 1 LIMIT 1'
    );
    $userStmt->execute([$adminId]);
    $admin = $userStmt->fetch(PDO::FETCH_ASSOC);
    if (!$admin) {
        $api->sendJsonResponse(404, 'Admin user not found or inactive');
        exit;
    }

    $recipients = [[
        'id' => (string) $admin['id'],
        'username' => (string) ($admin['username'] ?? 'there'),
        'email' => trim((string) ($admin['email'] ?? '')) ?: null,
        'phone' => trim((string) ($admin['phone'] ?? '')) ?: null,
    ]];

    $channelResult = sendDeadlineReminderChannels(
        $conn,
        $projectId,
        $projectName,
        $milestoneKey,
        $milestoneLabel,
        $milestoneDate,
        $messageOffset,
        $recipients,
        false,
        $diffDays
    );

    $anyOk = !empty($channelResult['push_ok'])
        || (int) ($channelResult['email_count'] ?? 0) > 0
        || (int) ($channelResult['whatsapp_count'] ?? 0) > 0;

    $api->sendJsonResponse(
        $anyOk ? 200 : 502,
        $anyOk ? 'Test deadline reminder sent to you' : 'Test reminder failed on all channels',
        [
            'project_id' => $projectId,
            'project_name' => $projectName,
            'milestone_key' => $milestoneKey,
            'milestone_label' => $milestoneLabel,
            'milestone_date' => $milestoneDate,
            'offset' => $messageOffset,
            'days_until' => $diffDays,
            'push' => !empty($channelResult['push_ok']),
            'emails' => (int) ($channelResult['email_count'] ?? 0),
            'whatsapp' => (int) ($channelResult['whatsapp_count'] ?? 0),
            'errors' => $channelResult['errors'] ?? [],
            'test' => true,
        ],
        $anyOk
    );
} catch (Throwable $e) {
    error_log('send_deadline_reminder_test.php: ' . $e->getMessage());
    $api->sendJsonResponse(500, 'Failed to send test deadline reminder');
}
