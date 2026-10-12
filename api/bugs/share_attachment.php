<?php
/**
 * Why: Mint a stable public preview token for one bug attachment.
 * Only someone who can already open the bug may create the link.
 */
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/BugController.php';
require_once __DIR__ . '/../projects/ProjectMemberController.php';
require_once __DIR__ . '/../PermissionManager.php';
require_once __DIR__ . '/../../utils/attachment_share.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$api = new BaseAPI();
$decoded = $api->validateToken();
$data = $api->getRequestData();
$attachmentId = isset($data['attachment_id']) ? trim((string) $data['attachment_id']) : '';

if ($attachmentId === '' || !preg_match('/^[A-Za-z0-9-]{8,64}$/', $attachmentId)) {
    $api->sendJsonResponse(422, 'A valid attachment is required', null, false);
}

try {
    $pdo = $api->getConnection();
    if (!$pdo instanceof PDO) {
        $api->sendJsonResponse(500, 'Could not create a share link', null, false);
    }
    attachmentShareEnsureColumn($pdo);

    $stmt = $pdo->prepare(
        'SELECT a.id, a.bug_id, a.file_name, a.file_type, b.project_id, b.title
         FROM bug_attachments a
         INNER JOIN bugs b ON CAST(b.id AS CHAR) = CAST(a.bug_id AS CHAR)
         WHERE a.id = ?
         LIMIT 1'
    );
    $stmt->execute([$attachmentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        $api->sendJsonResponse(404, 'Attachment not found', null, false);
    }

    $userId = (string) $decoded->user_id;
    $role = strtolower(trim((string) ($decoded->role ?? '')));
    $isAdmin = BaseAPI::hasGlobalDataScope($decoded);
    $memberController = new ProjectMemberController();
    $hasAccess = $isAdmin || $memberController->hasProjectAccess($userId, $row['project_id']);

    if (!$hasAccess) {
        $controller = new BugController();
        $commonMeta = $controller->getCommonBugMeta((string) $row['bug_id']);
        $canViewCommon = $role === 'developer' || $role === 'tester' || $role === 'creator';
        if (!$canViewCommon && $userId !== '') {
            try {
                $canViewCommon = PermissionManager::getInstance()->hasPermissionOrAdmin(
                    $userId,
                    'COMMON_BUGS_VIEW',
                    (string) ($decoded->role ?? '')
                );
            } catch (Throwable $e) {
                error_log('share_attachment permission: ' . $e->getMessage());
            }
        }
        $hasAccess = $canViewCommon && !empty($commonMeta['is_common']);
    }

    if (!$hasAccess) {
        $api->sendJsonResponse(403, 'You cannot share this attachment', null, false);
    }

    $token = attachmentShareMint($pdo, $attachmentId);
    if ($token === null) {
        $api->sendJsonResponse(500, 'Could not create a share link', null, false);
    }

    $api->sendJsonResponse(200, 'Share link ready', [
        'token' => $token,
        'file_name' => (string) $row['file_name'],
        'file_type' => (string) ($row['file_type'] ?? ''),
        'kind' => attachmentShareKind((string) ($row['file_type'] ?? ''), (string) $row['file_name']),
        'bug_title' => (string) ($row['title'] ?? ''),
    ]);
} catch (Throwable $e) {
    error_log('share_attachment failed: ' . $e->getMessage());
    $api->sendJsonResponse(500, 'Could not create a share link', null, false);
}
