<?php
/**
 * Convert (move) an update to another project.
 * Allowed roles: admin, developer, tester — must have access to source and target projects.
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
            'message' => 'Only admins, developers, and testers can convert updates to another project.',
        ]);
        exit;
    }

    $data = $api->getRequestData();
    $updateId = trim((string) ($data['update_id'] ?? $data['id'] ?? ''));
    $targetProjectId = trim((string) ($data['project_id'] ?? ''));

    if ($updateId === '' || $targetProjectId === '') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'update_id and project_id are required.',
        ]);
        exit;
    }

    $conn = $api->getConnection();
    $stmt = $conn->prepare(
        "SELECT u.*, p.name AS project_name
         FROM updates u
         LEFT JOIN projects p ON p.id = u.project_id
         WHERE u.id = ?
         LIMIT 1"
    );
    $stmt->execute([$updateId]);
    $update = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$update) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Update not found.']);
        exit;
    }

    $sourceProjectId = (string) ($update['project_id'] ?? '');
    if ($sourceProjectId === $targetProjectId) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Update is already in the selected project.',
        ]);
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
            'message' => 'You do not have access to the update\'s current project.',
        ]);
        exit;
    }
    if (!$members->hasProjectAccess($userId, $targetProjectId)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'You do not have access to the selected project.',
        ]);
        exit;
    }

    $conn->beginTransaction();

    $updateStmt = $conn->prepare(
        "UPDATE updates
         SET project_id = ?, updated_at = CURRENT_TIMESTAMP
         WHERE id = ?"
    );
    $updateStmt->execute([$targetProjectId, $updateId]);

    if ($updateStmt->rowCount() === 0) {
        $conn->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to convert update.']);
        exit;
    }

    $conn->commit();

    $fromName = (string) ($update['project_name'] ?? 'Unknown');
    $toName = (string) ($targetProject['name'] ?? 'Unknown');
    $updateTitle = (string) ($update['title'] ?? 'Untitled');

    try {
        $logger = ActivityLogger::getInstance();
        $logger->logActivity(
            $userId,
            $targetProjectId,
            'update_converted',
            "Update converted from \"{$fromName}\" to \"{$toName}\": {$updateTitle}",
            $updateId,
            [
                'action' => 'convert',
                'from_project_id' => $sourceProjectId,
                'from_project_name' => $fromName,
                'to_project_id' => $targetProjectId,
                'to_project_name' => $toName,
            ]
        );
    } catch (Exception $logEx) {
        error_log('Update convert activity log failed: ' . $logEx->getMessage());
    }

    try {
        $api->clearCache('updates_');
        $api->clearCache('user_updates_');
    } catch (Exception $cacheEx) {
        error_log('Update convert cache clear failed: ' . $cacheEx->getMessage());
    }

    $outStmt = $conn->prepare(
        "SELECT u.*,
                p.name AS project_name,
                us.username AS created_by_name
         FROM updates u
         LEFT JOIN projects p ON p.id = u.project_id
         LEFT JOIN users us ON us.id = u.created_by
         WHERE u.id = ?
         LIMIT 1"
    );
    $outStmt->execute([$updateId]);
    $updated = $outStmt->fetch(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => "Update moved to \"{$toName}\".",
        'data' => $updated,
    ]);
} catch (Exception $e) {
    error_log('Update convert error: ' . $e->getMessage());
    if (isset($conn) && $conn instanceof PDO && $conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to convert update: ' . $e->getMessage(),
    ]);
}
