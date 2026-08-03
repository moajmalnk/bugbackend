<?php
/**
 * Convert a bug into a new update (archive source bug as declined).
 * Allowed roles: admin, developer, tester with access to source + target projects.
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
require_once __DIR__ . '/../../config/utils.php';

/**
 * Map bug attachment MIME/type to update_attachments category enum.
 */
function mapBugAttachmentToUpdateType(?string $fileType, ?string $fileName): string
{
    $ft = strtolower(trim((string) $fileType));
    $fn = (string) $fileName;

    if ($ft === 'screenshot' || str_starts_with($ft, 'image/') || preg_match('/\.(jpg|jpeg|png|gif|webp|bmp|svg)$/i', $fn)) {
        return 'screenshot';
    }
    if ($ft === 'voice_note' || str_starts_with($ft, 'audio/') || preg_match('/\.(wav|mp3|m4a|ogg|webm)$/i', $fn)) {
        return 'voice_note';
    }
    return 'attachment';
}

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
            'message' => 'Only admins, developers, and testers can convert bugs to updates.',
        ]);
        exit;
    }

    $data = $api->getRequestData();
    $bugId = trim((string) ($data['bug_id'] ?? $data['id'] ?? ''));
    $updateType = trim((string) ($data['type'] ?? ''));
    $targetProjectId = trim((string) ($data['project_id'] ?? ''));

    $validTypes = ['feature', 'updation', 'maintenance'];
    if ($bugId === '' || !in_array($updateType, $validTypes, true)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'bug_id and type (feature|updation|maintenance) are required.',
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

    if (strtolower((string) ($bug['status'] ?? '')) === 'declined') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'This bug has already been declined or converted.',
        ]);
        exit;
    }

    $sourceProjectId = (string) ($bug['project_id'] ?? '');
    if ($targetProjectId === '') {
        $targetProjectId = $sourceProjectId;
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

    $descParts = [];
    $mainDesc = trim((string) ($bug['description'] ?? ''));
    if ($mainDesc !== '') {
        $descParts[] = $mainDesc;
    }
    $expected = trim((string) ($bug['expected_result'] ?? ''));
    if ($expected !== '') {
        $descParts[] = "Expected result:\n" . $expected;
    }
    $actual = trim((string) ($bug['actual_result'] ?? ''));
    if ($actual !== '') {
        $descParts[] = "Actual result:\n" . $actual;
    }
    $description = implode("\n\n", $descParts);
    if ($description === '') {
        $description = '(Converted from bug — no description)';
    }

    $priority = strtolower(trim((string) ($bug['priority'] ?? 'medium')));
    if (!in_array($priority, ['high', 'medium', 'low'], true)) {
        $priority = 'medium';
    }

    $title = trim((string) ($bug['title'] ?? 'Untitled'));
    if ($title === '') {
        $title = 'Untitled';
    }

    $conn->beginTransaction();

    $newUpdateId = Utils::generateUUID();

    // Detect optional update columns
    $colsStmt = $conn->query('SHOW COLUMNS FROM updates');
    $updateCols = $colsStmt ? array_column($colsStmt->fetchAll(PDO::FETCH_ASSOC), 'Field') : [];
    $hasUpdatePriority = in_array('update_priority', $updateCols, true);

    if ($hasUpdatePriority) {
        $ins = $conn->prepare(
            "INSERT INTO updates (id, project_id, title, type, description, created_by, status, update_priority, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, CURRENT_TIMESTAMP)"
        );
        $ins->execute([$newUpdateId, $targetProjectId, $title, $updateType, $description, $userId, $priority]);
    } else {
        $ins = $conn->prepare(
            "INSERT INTO updates (id, project_id, title, type, description, created_by, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 'pending', CURRENT_TIMESTAMP)"
        );
        $ins->execute([$newUpdateId, $targetProjectId, $title, $updateType, $description, $userId]);
    }

    // Copy attachments (reuse disk paths)
    $attStmt = $conn->prepare(
        "SELECT file_name, file_path, file_type, uploaded_by, duration
         FROM bug_attachments
         WHERE CAST(bug_id AS CHAR) = CAST(? AS CHAR)
         ORDER BY created_at ASC"
    );
    try {
        $attStmt->execute([$bugId]);
    } catch (PDOException $e) {
        // duration column may not exist
        $attStmt = $conn->prepare(
            "SELECT file_name, file_path, file_type, uploaded_by
             FROM bug_attachments
             WHERE CAST(bug_id AS CHAR) = CAST(? AS CHAR)
             ORDER BY created_at ASC"
        );
        $attStmt->execute([$bugId]);
    }
    $attachments = $attStmt->fetchAll(PDO::FETCH_ASSOC);

    $uaColsStmt = $conn->query('SHOW COLUMNS FROM update_attachments');
    $uaCols = $uaColsStmt ? array_column($uaColsStmt->fetchAll(PDO::FETCH_ASSOC), 'Field') : [];
    $hasDuration = in_array('duration', $uaCols, true);
    $hasFileSize = in_array('file_size', $uaCols, true);

    foreach ($attachments as $att) {
        $attId = Utils::generateUUID();
        $mappedType = mapBugAttachmentToUpdateType($att['file_type'] ?? null, $att['file_name'] ?? null);
        $uploader = (string) ($att['uploaded_by'] ?? $userId);
        $duration = isset($att['duration']) && $att['duration'] !== null ? (int) $att['duration'] : null;

        if ($hasDuration && $hasFileSize) {
            $copy = $conn->prepare(
                "INSERT INTO update_attachments (id, update_id, file_name, file_path, file_type, file_size, duration, uploaded_by)
                 VALUES (?, ?, ?, ?, ?, NULL, ?, ?)"
            );
            $copy->execute([
                $attId,
                $newUpdateId,
                $att['file_name'],
                $att['file_path'],
                $mappedType,
                $mappedType === 'voice_note' ? $duration : null,
                $uploader,
            ]);
        } elseif ($hasDuration) {
            $copy = $conn->prepare(
                "INSERT INTO update_attachments (id, update_id, file_name, file_path, file_type, duration, uploaded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $copy->execute([
                $attId,
                $newUpdateId,
                $att['file_name'],
                $att['file_path'],
                $mappedType,
                $mappedType === 'voice_note' ? $duration : null,
                $uploader,
            ]);
        } else {
            $copy = $conn->prepare(
                "INSERT INTO update_attachments (id, update_id, file_name, file_path, file_type, uploaded_by)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $copy->execute([
                $attId,
                $newUpdateId,
                $att['file_name'],
                $att['file_path'],
                $mappedType,
                $uploader,
            ]);
        }
    }

    // Archive source bug
    $archiveNote = "Converted to update {$newUpdateId}";
    $bugColsStmt = $conn->query('SHOW COLUMNS FROM bugs');
    $bugCols = $bugColsStmt ? array_column($bugColsStmt->fetchAll(PDO::FETCH_ASSOC), 'Field') : [];
    if (in_array('fix_description', $bugCols, true)) {
        $archive = $conn->prepare(
            "UPDATE bugs
             SET status = 'declined',
                 fix_description = CASE
                   WHEN fix_description IS NULL OR fix_description = '' THEN ?
                   ELSE CONCAT(fix_description, '\n', ?)
                 END,
                 updated_by = ?,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ?"
        );
        $archive->execute([$archiveNote, $archiveNote, $userId, $bugId]);
    } else {
        $archive = $conn->prepare(
            "UPDATE bugs
             SET status = 'declined', updated_by = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?"
        );
        $archive->execute([$userId, $bugId]);
    }

    $conn->commit();

    $toName = (string) ($targetProject['name'] ?? 'Unknown');
    $bugTitle = (string) ($bug['title'] ?? 'Untitled');

    try {
        $logger = ActivityLogger::getInstance();
        $meta = [
            'action' => 'bug_converted_to_update',
            'bug_id' => $bugId,
            'update_id' => $newUpdateId,
            'update_type' => $updateType,
            'from_project_id' => $sourceProjectId,
            'to_project_id' => $targetProjectId,
            'to_project_name' => $toName,
        ];
        $logger->logActivity(
            $userId,
            $targetProjectId,
            'bug_converted_to_update',
            "Bug converted to update: {$bugTitle} → {$newUpdateId}",
            $bugId,
            $meta
        );
        $logger->logActivity(
            $userId,
            $targetProjectId,
            'update_created_from_bug',
            "Update created from bug {$bugId}: {$bugTitle}",
            $newUpdateId,
            $meta
        );
    } catch (Exception $logEx) {
        error_log('Bug→Update convert activity log failed: ' . $logEx->getMessage());
    }

    try {
        $api->clearCache('user_bugs_');
        $api->clearCache('bugs_');
        $api->clearCache('bug_count_');
        $api->clearCache('updates_');
        $api->clearCache('user_updates_');
    } catch (Exception $cacheEx) {
        error_log('Bug→Update convert cache clear failed: ' . $cacheEx->getMessage());
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
    $outStmt->execute([$newUpdateId]);
    $created = $outStmt->fetch(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => "Bug converted to update in \"{$toName}\".",
        'data' => [
            'update' => $created,
            'bug_id' => $bugId,
            'update_id' => $newUpdateId,
        ],
    ]);
} catch (Exception $e) {
    error_log('Bug→Update convert error: ' . $e->getMessage());
    if (isset($conn) && $conn instanceof PDO && $conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to convert bug to update: ' . $e->getMessage(),
    ]);
}
