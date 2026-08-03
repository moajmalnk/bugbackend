<?php
/**
 * GET /api/projects/deadline_reminders.php
 *   ?project_id=…  — history for one project
 *   (no project_id) — recent sends across all projects
 *
 * Admin JWT required. Ordered by sent_at DESC.
 */

require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../utils/deadline_reminders.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
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
        $api->sendJsonResponse(403, 'Only admins can view deadline reminder history');
        exit;
    }

    $conn = $api->getConnection();
    ensureDeadlineReminderTable($conn);

    $projectId = isset($_GET['project_id']) ? trim((string) $_GET['project_id']) : '';
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int) ($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $milestones = deadlineReminderMilestones();

    if ($projectId !== '') {
        $countStmt = $conn->prepare(
            'SELECT COUNT(*) FROM project_deadline_reminders WHERE project_id = ?'
        );
        $countStmt->execute([$projectId]);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $conn->prepare(
            'SELECT r.id, r.project_id, r.milestone_key, r.reminder_offset, r.milestone_date,
                    r.sent_at, r.email_count, r.whatsapp_count, r.push_ok, r.status, r.error_summary,
                    p.name AS project_name
             FROM project_deadline_reminders r
             LEFT JOIN projects p ON p.id = r.project_id
             WHERE r.project_id = ?
             ORDER BY r.sent_at DESC
             LIMIT ? OFFSET ?'
        );
        $stmt->bindValue(1, $projectId, PDO::PARAM_STR);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        $stmt->execute();
    } else {
        $countStmt = $conn->query('SELECT COUNT(*) FROM project_deadline_reminders');
        $total = (int) $countStmt->fetchColumn();

        $stmt = $conn->prepare(
            'SELECT r.id, r.project_id, r.milestone_key, r.reminder_offset, r.milestone_date,
                    r.sent_at, r.email_count, r.whatsapp_count, r.push_ok, r.status, r.error_summary,
                    p.name AS project_name
             FROM project_deadline_reminders r
             LEFT JOIN projects p ON p.id = r.project_id
             ORDER BY r.sent_at DESC
             LIMIT ? OFFSET ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $items = [];
    foreach ($rows as $row) {
        $key = (string) ($row['milestone_key'] ?? '');
        $off = (int) ($row['reminder_offset'] ?? 0);
        $items[] = [
            'id' => (int) ($row['id'] ?? 0),
            'project_id' => (string) ($row['project_id'] ?? ''),
            'project_name' => (string) ($row['project_name'] ?? 'Untitled project'),
            'milestone_key' => $key,
            'milestone_label' => $milestones[$key] ?? $key,
            'reminder_offset' => $off,
            'offset_label' => deadlineReminderOffsetLabel($off),
            'milestone_date' => (string) ($row['milestone_date'] ?? ''),
            'sent_at' => (string) ($row['sent_at'] ?? ''),
            'email_count' => (int) ($row['email_count'] ?? 0),
            'whatsapp_count' => (int) ($row['whatsapp_count'] ?? 0),
            'push_ok' => (bool) ($row['push_ok'] ?? false),
            'status' => (string) ($row['status'] ?? 'sent'),
            'error_summary' => $row['error_summary'] ?? null,
        ];
    }

    $api->sendJsonResponse(200, 'Deadline reminders retrieved', [
        'items' => $items,
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
    ]);
} catch (Throwable $e) {
    error_log('deadline_reminders.php: ' . $e->getMessage());
    $api->sendJsonResponse(500, 'Failed to load deadline reminders');
}
