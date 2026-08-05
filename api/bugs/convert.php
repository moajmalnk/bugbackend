<?php
/**
 * Convert (move) a bug to another project.
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
require_once __DIR__ . '/BugController.php';
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
            'message' => 'Only admins, developers, and testers can convert bugs to another project.',
        ]);
        exit;
    }

    $data = $api->getRequestData();
    $bugId = trim((string) ($data['bug_id'] ?? $data['id'] ?? ''));
    $targetProjectId = trim((string) ($data['project_id'] ?? ''));

    if ($bugId === '' || $targetProjectId === '') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'bug_id and project_id are required.',
        ]);
        exit;
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
    if ($sourceProjectId === $targetProjectId) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Bug is already in the selected project.',
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
            'message' => 'You do not have access to the bug\'s current project.',
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

    $update = $conn->prepare(
        "UPDATE bugs
         SET project_id = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP
         WHERE id = ?"
    );
    $update->execute([$targetProjectId, $userId, $bugId]);

    if ($update->rowCount() === 0) {
        $conn->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to convert bug.']);
        exit;
    }

    $conn->commit();

    $fromName = (string) ($bug['project_name'] ?? 'Unknown');
    $toName = (string) ($targetProject['name'] ?? 'Unknown');
    $bugTitle = (string) ($bug['title'] ?? 'Untitled');

    try {
        $logger = ActivityLogger::getInstance();
        $meta = [
            'action' => 'convert',
            'from_project_id' => $sourceProjectId,
            'from_project_name' => $fromName,
            'to_project_id' => $targetProjectId,
            'to_project_name' => $toName,
        ];
        // Single log keyed by bug related_id (avoids duplicate history rows)
        $logger->logActivity(
            $userId,
            $targetProjectId,
            'bug_converted',
            "Bug converted from \"{$fromName}\" to \"{$toName}\": {$bugTitle}",
            $bugId,
            $meta
        );
    } catch (Exception $logEx) {
        error_log('Bug convert activity log failed: ' . $logEx->getMessage());
    }

    // Invalidate bug list + project card stats (source & target counts)
    try {
        $api->clearCache('user_bugs_');
        $api->clearCache('bugs_');
        $api->clearCache('bug_count_');
        $api->clearCache('user_total_bugs_');
        $api->clearCache('project_stats_');
        $api->clearCache('bug_stats_');
    } catch (Exception $cacheEx) {
        error_log('Bug convert cache clear failed: ' . $cacheEx->getMessage());
    }

    $outStmt = $conn->prepare(
        "SELECT b.*,
                p.name AS project_name,
                u.username AS reporter_name,
                ub.username AS updated_by_name
         FROM bugs b
         LEFT JOIN projects p ON CAST(p.id AS CHAR) = CAST(b.project_id AS CHAR)
         LEFT JOIN users u ON u.id = CAST(b.reported_by AS CHAR)
         LEFT JOIN users ub ON ub.id = CAST(b.updated_by AS CHAR)
         WHERE b.id = ?
         LIMIT 1"
    );
    $outStmt->execute([$bugId]);
    $updated = $outStmt->fetch(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => "Bug moved to \"{$toName}\".",
        'data' => $updated,
    ]);
} catch (Exception $e) {
    error_log('Bug convert error: ' . $e->getMessage());
    if (isset($conn) && $conn instanceof PDO && $conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to convert bug: ' . $e->getMessage(),
    ]);
}
