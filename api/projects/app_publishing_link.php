<?php
/**
 * Why: Staff mint / view / revoke one-time public App Publishing form links.
 * GET  ?project_id=  → active link + latest submitted meta
 * POST { project_id, valid_days? } → create new link (revokes previous active)
 * DELETE { project_id } or ?project_id= → revoke active link
 */
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../utils/app_publishing_links.php';

$api = new BaseAPI();
$decoded = $api->validateToken();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$conn = $api->getConnection();

/**
 * @param object $decoded
 */
function appPublishingStaffCanManage(BaseAPI $api, $decoded, string $projectId): bool
{
    if (BaseAPI::hasGlobalDataScope($decoded)) {
        return true;
    }
    $role = strtolower(trim((string) ($decoded->role ?? '')));
    if (!in_array($role, ['developer', 'tester'], true)) {
        return false;
    }
    $stmt = $api->getConnection()->prepare(
        'SELECT 1 FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1'
    );
    $stmt->execute([$projectId, $decoded->user_id ?? '']);
    return (bool) $stmt->fetchColumn();
}

function appPublishingEnsureFormColumn(PDO $conn): void
{
    try {
        $cols = [];
        $res = $conn->query('SHOW COLUMNS FROM projects');
        if ($res) {
            while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
                $cols[] = $row['Field'];
            }
        }
        if (!in_array('app_publishing_form', $cols, true)) {
            $conn->exec(
                "ALTER TABLE projects ADD COLUMN app_publishing_form LONGTEXT DEFAULT NULL"
            );
        }
    } catch (Throwable $e) {
        error_log('appPublishingEnsureFormColumn: ' . $e->getMessage());
    }
}

try {
    appPublishingLinksEnsureSchema($conn);
    appPublishingEnsureFormColumn($conn);

    if ($method === 'GET') {
        $projectId = isset($_GET['project_id']) ? trim((string) $_GET['project_id']) : '';
        if ($projectId === '') {
            $api->sendJsonResponse(400, 'project_id is required');
            exit;
        }
        if (!appPublishingStaffCanManage($api, $decoded, $projectId)) {
            $api->sendJsonResponse(403, 'You cannot manage this project app form link');
            exit;
        }

        $proj = $conn->prepare(
            'SELECT id, name, app_publishing_form, client_name, client_contact_name, client_phone, platforms
             FROM projects WHERE id = ? LIMIT 1'
        );
        $proj->execute([$projectId]);
        $project = $proj->fetch(PDO::FETCH_ASSOC);
        if (!$project) {
            $api->sendJsonResponse(404, 'Project not found');
            exit;
        }

        $active = appPublishingLinksFindActive($conn, $projectId);

        $latestStmt = $conn->prepare(
            "SELECT id, status, created_at, expires_at, submitted_at, revoked_at
             FROM project_app_form_links
             WHERE project_id = ?
             ORDER BY created_at DESC
             LIMIT 5"
        );
        $latestStmt->execute([$projectId]);
        $history = $latestStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $api->sendJsonResponse(200, 'OK', [
            'project_id' => $projectId,
            'project_name' => $project['name'],
            'app_publishing_form' => $project['app_publishing_form'],
            'active_link' => $active
                ? [
                    'token' => $active['token'],
                    'status' => $active['status'],
                    'created_at' => $active['created_at'],
                    'expires_at' => $active['expires_at'],
                ]
                : null,
            'history' => $history,
        ]);
        exit;
    }

    $data = $api->getRequestData();
    if (!is_array($data)) {
        $data = [];
    }
    $projectId = isset($data['project_id'])
        ? trim((string) $data['project_id'])
        : (isset($_GET['project_id']) ? trim((string) $_GET['project_id']) : '');

    if ($projectId === '') {
        $api->sendJsonResponse(400, 'project_id is required');
        exit;
    }
    if (!appPublishingStaffCanManage($api, $decoded, $projectId)) {
        $api->sendJsonResponse(403, 'You cannot manage this project app form link');
        exit;
    }

    $exists = $conn->prepare('SELECT id FROM projects WHERE id = ? LIMIT 1');
    $exists->execute([$projectId]);
    if (!$exists->fetch()) {
        $api->sendJsonResponse(404, 'Project not found');
        exit;
    }

    if ($method === 'POST') {
        $validDays = isset($data['valid_days']) ? (int) $data['valid_days'] : 14;
        $link = appPublishingLinksCreate(
            $conn,
            $projectId,
            isset($decoded->user_id) ? (string) $decoded->user_id : null,
            $validDays
        );
        $api->sendJsonResponse(200, 'Share link created', [
            'token' => $link['token'],
            'status' => $link['status'],
            'created_at' => $link['created_at'],
            'expires_at' => $link['expires_at'],
        ]);
        exit;
    }

    if ($method === 'DELETE') {
        appPublishingLinksRevokeActive($conn, $projectId);
        $api->sendJsonResponse(200, 'Active share link revoked', ['project_id' => $projectId]);
        exit;
    }

    $api->sendJsonResponse(405, 'Method not allowed');
} catch (Throwable $e) {
    error_log('app_publishing_link: ' . $e->getMessage());
    $api->sendJsonResponse(500, 'Could not manage app form link');
}
