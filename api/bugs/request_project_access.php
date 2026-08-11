<?php
/**
 * Request project access to move or convert a bug into a project the user is not assigned to.
 * Notifies admins and target project members via in-app, email, and WhatsApp.
 */
require_once __DIR__ . '/../../config/cors.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../projects/ProjectMemberController.php';
require_once __DIR__ . '/../ActivityLogger.php';
require_once __DIR__ . '/../../utils/http_finish.php';

try {
    $api = new BaseAPI();
    $decoded = $api->validateToken();
    $userId = (string) ($decoded->user_id ?? '');
    $role = strtolower(trim((string) ($decoded->role ?? '')));

    $allowedRoles = ['admin', 'developer', 'tester'];
    if (!in_array($role, $allowedRoles, true)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Only admins, developers, and testers can request project access.',
        ]);
        exit;
    }

    $data = $api->getRequestData();
    $bugId = trim((string) ($data['bug_id'] ?? $data['id'] ?? ''));
    $targetProjectId = trim((string) ($data['project_id'] ?? ''));
    $intent = strtolower(trim((string) ($data['intent'] ?? 'move')));
    $note = trim((string) ($data['note'] ?? ''));
    $updateType = trim((string) ($data['update_type'] ?? ''));

    if ($bugId === '' || $targetProjectId === '') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'bug_id and project_id are required.',
        ]);
        exit;
    }

    if (!in_array($intent, ['move', 'to_update'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid intent.']);
        exit;
    }

    if ($intent === 'to_update' && !in_array($updateType, ['feature', 'updation', 'maintenance'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'update_type is required for convert to update.']);
        exit;
    }

    if (mb_strlen($note) > 500) {
        $note = mb_substr($note, 0, 500);
    }

    $conn = $api->getConnection();
    $stmt = $conn->prepare(
        "SELECT b.*, p.name AS project_name
         FROM bugs b
         LEFT JOIN projects p ON p.id = b.project_id
         WHERE b.id = ?
         LIMIT 1"
    );
    $stmt->execute([$bugId]);
    $bug = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$bug) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Bug not found.']);
        exit;
    }

    $sourceProjectId = (string) ($bug['project_id'] ?? '');
    if ($intent === 'move' && $sourceProjectId === $targetProjectId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Bug is already in the selected project.']);
        exit;
    }

    $targetStmt = $conn->prepare("SELECT id, name FROM projects WHERE id = ? LIMIT 1");
    $targetStmt->execute([$targetProjectId]);
    $targetProject = $targetStmt->fetch(PDO::FETCH_ASSOC);
    if (!$targetProject) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Target project not found.']);
        exit;
    }

    $members = new ProjectMemberController();
    if (!$members->hasProjectAccess($userId, $sourceProjectId)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'You do not have access to the bug\'s current project.',
        ]);
        exit;
    }

    if ($members->hasProjectAccess($userId, $targetProjectId)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'You already have access to this project. Use Move bug instead.',
        ]);
        exit;
    }

    $requesterStmt = $conn->prepare(
        "SELECT username, email FROM users WHERE id = ? AND account_active = 1 LIMIT 1"
    );
    $requesterStmt->execute([$userId]);
    $requester = $requesterStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $requesterName = trim((string) ($requester['username'] ?? '')) ?: 'User';

    $bugTitle = trim((string) ($bug['title'] ?? 'Untitled'));
    $projectName = trim((string) ($targetProject['name'] ?? 'Project'));
    $intentLabel = $intent === 'to_update' ? 'convert to update' : 'move bug';
    if ($intent === 'to_update' && $updateType !== '') {
        $intentLabel .= " ({$updateType})";
    }

    $baseUrl = function_exists('getFrontendBaseUrl')
        ? rtrim(getFrontendBaseUrl(), '/')
        : 'https://bugs.bugricer.com';
    if (
        isset($_SERVER['HTTP_HOST']) &&
        (strpos((string) $_SERVER['HTTP_HOST'], 'localhost') !== false ||
            strpos((string) $_SERVER['HTTP_HOST'], '127.0.0.1') !== false)
    ) {
        $baseUrl = 'http://localhost:8080';
    }
    $reviewUrl = $baseUrl . '/admin/projects/' . rawurlencode($targetProjectId);

    $payload = [
        'bug_id' => $bugId,
        'project_id' => $targetProjectId,
        'project_name' => $projectName,
        'intent' => $intent,
    ];

    br_after_response(
        function () use (
            $conn,
            $userId,
            $requesterName,
            $targetProjectId,
            $projectName,
            $bugId,
            $bugTitle,
            $intentLabel,
            $note,
            $reviewUrl,
            $sourceProjectId
        ) {
            try {
                require_once __DIR__ . '/../NotificationManager.php';
                NotificationManager::getInstance()->notifyProjectAccessRequest(
                    $userId,
                    $targetProjectId,
                    $projectName,
                    $bugId,
                    $bugTitle,
                    $intentLabel,
                    $note !== '' ? $note : null
                );
            } catch (Throwable $e) {
                error_log('request_project_access in-app: ' . $e->getMessage());
            }

            try {
                require_once __DIR__ . '/../../utils/email.php';
                require_once __DIR__ . '/../../utils/whatsapp.php';

                $recipientStmt = $conn->prepare(
                    "SELECT DISTINCT u.id, u.email, u.phone
                     FROM users u
                     WHERE u.account_active = 1
                       AND (
                         u.role = 'admin'
                         OR u.id IN (
                           SELECT pm.user_id FROM project_members pm WHERE pm.project_id = ?
                         )
                       )
                       AND u.id != ?"
                );
                $recipientStmt->execute([$targetProjectId, $userId]);
                $recipients = $recipientStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                foreach ($recipients as $recipient) {
                    $email = trim((string) ($recipient['email'] ?? ''));
                    $phone = trim((string) ($recipient['phone'] ?? ''));
                    if ($email !== '') {
                        sendProjectAccessRequestEmail(
                            $email,
                            $requesterName,
                            $projectName,
                            $bugTitle,
                            $intentLabel,
                            $reviewUrl,
                            $note !== '' ? $note : null
                        );
                    }
                    if ($phone !== '') {
                        sendProjectAccessRequestWhatsApp(
                            $phone,
                            $requesterName,
                            $projectName,
                            $bugTitle,
                            $intentLabel,
                            $reviewUrl,
                            $note !== '' ? $note : null
                        );
                    }
                }
            } catch (Throwable $e) {
                error_log('request_project_access mail/wa: ' . $e->getMessage());
            }

            try {
                $logger = ActivityLogger::getInstance();
                $description = "{$requesterName} requested access to \"{$projectName}\" to {$intentLabel}: {$bugTitle}";
                if ($note !== '') {
                    $description .= ' — ' . mb_substr($note, 0, 120);
                }
                $logger->logActivity(
                    $userId,
                    $targetProjectId,
                    'project_access_requested',
                    $description,
                    $bugId,
                    [
                        'bug_id' => $bugId,
                        'from_project_id' => $sourceProjectId,
                        'to_project_id' => $targetProjectId,
                        'intent' => $intentLabel,
                    ]
                );
            } catch (Throwable $e) {
                error_log('request_project_access activity: ' . $e->getMessage());
            }
        },
        200,
        'Access request sent. Admins and project members were notified.',
        $payload,
        true
    );
} catch (Exception $e) {
    error_log('request_project_access error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to send access request.',
    ]);
}
