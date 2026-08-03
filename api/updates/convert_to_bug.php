<?php
/**
 * Convert an update into a new bug (archive source update as declined).
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
 * Map update_attachments category to a MIME-ish type for bug_attachments.
 */
function mapUpdateAttachmentToBugType(?string $fileType, ?string $fileName): string
{
    $ft = strtolower(trim((string) $fileType));
    $fn = strtolower((string) $fileName);

    if ($ft === 'screenshot' || str_starts_with($ft, 'image/')) {
        if (preg_match('/\.(png)$/i', $fn)) return 'image/png';
        if (preg_match('/\.(jpe?g)$/i', $fn)) return 'image/jpeg';
        if (preg_match('/\.(gif)$/i', $fn)) return 'image/gif';
        if (preg_match('/\.(webp)$/i', $fn)) return 'image/webp';
        return 'image/png';
    }
    if ($ft === 'voice_note' || str_starts_with($ft, 'audio/')) {
        if (preg_match('/\.(mp3)$/i', $fn)) return 'audio/mpeg';
        if (preg_match('/\.(m4a)$/i', $fn)) return 'audio/mp4';
        if (preg_match('/\.(ogg)$/i', $fn)) return 'audio/ogg';
        if (preg_match('/\.(wav)$/i', $fn)) return 'audio/wav';
        return 'audio/webm';
    }
    if (str_starts_with($ft, 'video/') || preg_match('/\.(mp4|webm|mov|avi|mkv|m4v)$/i', $fn)) {
        return 'video/mp4';
    }
    if ($ft !== '' && $ft !== 'attachment') {
        return $ft;
    }
    return 'application/octet-stream';
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
            'message' => 'Only admins, developers, and testers can convert updates to bugs.',
        ]);
        exit;
    }

    $data = $api->getRequestData();
    $updateId = trim((string) ($data['update_id'] ?? $data['id'] ?? ''));
    $targetProjectId = trim((string) ($data['project_id'] ?? ''));

    if ($updateId === '') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'update_id is required.',
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

    if (strtolower((string) ($update['status'] ?? '')) === 'declined') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'This update has already been declined or converted.',
        ]);
        exit;
    }

    $sourceProjectId = (string) ($update['project_id'] ?? '');
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

    $updateType = (string) ($update['type'] ?? 'feature');
    $body = trim((string) ($update['description'] ?? ''));
    $description = "[Converted from update ({$updateType})]\n\n" . ($body !== '' ? $body : '(No description)');

    $priority = strtolower(trim((string) ($update['update_priority'] ?? 'medium')));
    if (!in_array($priority, ['high', 'medium', 'low'], true)) {
        $priority = 'medium';
    }

    $title = trim((string) ($update['title'] ?? 'Untitled'));
    if ($title === '') {
        $title = 'Untitled';
    }

    $conn->beginTransaction();

    $newBugId = Utils::generateUUID();

    $ins = $conn->prepare(
        "INSERT INTO bugs (id, title, description, expected_result, actual_result, project_id, reported_by, priority, status, created_at, updated_at, updated_by)
         VALUES (?, ?, ?, NULL, NULL, ?, ?, ?, 'pending', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)"
    );
    $ins->execute([$newBugId, $title, $description, $targetProjectId, $userId, $priority, $userId]);

    $attStmt = $conn->prepare(
        "SELECT file_name, file_path, file_type, uploaded_by, duration
         FROM update_attachments
         WHERE update_id = ?
         ORDER BY created_at ASC"
    );
    try {
        $attStmt->execute([$updateId]);
    } catch (PDOException $e) {
        $attStmt = $conn->prepare(
            "SELECT file_name, file_path, file_type, uploaded_by
             FROM update_attachments
             WHERE update_id = ?
             ORDER BY created_at ASC"
        );
        $attStmt->execute([$updateId]);
    }
    $attachments = $attStmt->fetchAll(PDO::FETCH_ASSOC);

    $baColsStmt = $conn->query('SHOW COLUMNS FROM bug_attachments');
    $baCols = $baColsStmt ? array_column($baColsStmt->fetchAll(PDO::FETCH_ASSOC), 'Field') : [];
    $hasDuration = in_array('duration', $baCols, true);
    $hasUploadContext = in_array('upload_context', $baCols, true);

    foreach ($attachments as $att) {
        $attId = Utils::generateUUID();
        $mappedType = mapUpdateAttachmentToBugType($att['file_type'] ?? null, $att['file_name'] ?? null);
        $uploader = (string) ($att['uploaded_by'] ?? $userId);
        $duration = isset($att['duration']) && $att['duration'] !== null ? (int) $att['duration'] : null;
        $isAudio = str_starts_with($mappedType, 'audio/') || ($att['file_type'] ?? '') === 'voice_note';

        if ($hasDuration && $hasUploadContext) {
            $copy = $conn->prepare(
                "INSERT INTO bug_attachments (id, bug_id, file_name, file_path, file_type, uploaded_by, upload_context, duration)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $copy->execute([
                $attId,
                $newBugId,
                $att['file_name'],
                $att['file_path'],
                $mappedType,
                $uploader,
                $isAudio ? 'voice_note' : null,
                $isAudio ? $duration : null,
            ]);
        } elseif ($hasDuration) {
            $copy = $conn->prepare(
                "INSERT INTO bug_attachments (id, bug_id, file_name, file_path, file_type, uploaded_by, duration)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $copy->execute([
                $attId,
                $newBugId,
                $att['file_name'],
                $att['file_path'],
                $mappedType,
                $uploader,
                $isAudio ? $duration : null,
            ]);
        } else {
            $copy = $conn->prepare(
                "INSERT INTO bug_attachments (id, bug_id, file_name, file_path, file_type, uploaded_by)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $copy->execute([
                $attId,
                $newBugId,
                $att['file_name'],
                $att['file_path'],
                $mappedType,
                $uploader,
            ]);
        }
    }

    // Archive source update
    $updateColsStmt = $conn->query('SHOW COLUMNS FROM updates');
    $updateCols = $updateColsStmt ? array_column($updateColsStmt->fetchAll(PDO::FETCH_ASSOC), 'Field') : [];
    $hasDeclinedAt = in_array('declined_at', $updateCols, true);

    if ($hasDeclinedAt) {
        $archive = $conn->prepare(
            "UPDATE updates
             SET status = 'declined', declined_at = NOW(), updated_at = CURRENT_TIMESTAMP
             WHERE id = ?"
        );
        $archive->execute([$updateId]);
    } else {
        $archive = $conn->prepare(
            "UPDATE updates
             SET status = 'declined', updated_at = CURRENT_TIMESTAMP
             WHERE id = ?"
        );
        $archive->execute([$updateId]);
    }

    $conn->commit();

    $toName = (string) ($targetProject['name'] ?? 'Unknown');
    $updateTitle = (string) ($update['title'] ?? 'Untitled');

    try {
        $logger = ActivityLogger::getInstance();
        $meta = [
            'action' => 'update_converted_to_bug',
            'update_id' => $updateId,
            'bug_id' => $newBugId,
            'from_project_id' => $sourceProjectId,
            'to_project_id' => $targetProjectId,
            'to_project_name' => $toName,
        ];
        $logger->logActivity(
            $userId,
            $targetProjectId,
            'update_converted_to_bug',
            "Update converted to bug: {$updateTitle} → {$newBugId}",
            $updateId,
            $meta
        );
        $logger->logActivity(
            $userId,
            $targetProjectId,
            'bug_created_from_update',
            "Bug created from update {$updateId}: {$updateTitle}",
            $newBugId,
            $meta
        );
    } catch (Exception $logEx) {
        error_log('Update→Bug convert activity log failed: ' . $logEx->getMessage());
    }

    try {
        $api->clearCache('user_bugs_');
        $api->clearCache('bugs_');
        $api->clearCache('bug_count_');
        $api->clearCache('updates_');
        $api->clearCache('user_updates_');
    } catch (Exception $cacheEx) {
        error_log('Update→Bug convert cache clear failed: ' . $cacheEx->getMessage());
    }

    $outStmt = $conn->prepare(
        "SELECT b.*,
                p.name AS project_name,
                u.username AS reporter_name
         FROM bugs b
         LEFT JOIN projects p ON p.id = b.project_id
         LEFT JOIN users u ON u.id = b.reported_by
         WHERE b.id = ?
         LIMIT 1"
    );
    $outStmt->execute([$newBugId]);
    $created = $outStmt->fetch(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => "Update converted to bug in \"{$toName}\".",
        'data' => [
            'bug' => $created,
            'update_id' => $updateId,
            'bug_id' => $newBugId,
        ],
    ]);
} catch (Exception $e) {
    error_log('Update→Bug convert error: ' . $e->getMessage());
    if (isset($conn) && $conn instanceof PDO && $conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to convert update to bug: ' . $e->getMessage(),
    ]);
}
