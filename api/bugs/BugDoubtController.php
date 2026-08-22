<?php
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../projects/ProjectMemberController.php';
require_once __DIR__ . '/../NotificationManager.php';
require_once __DIR__ . '/BugController.php';
require_once __DIR__ . '/../PermissionManager.php';

/**
 * Why: Store bug doubts and replies (text + voice) without overloading bug_attachments.
 */
class BugDoubtController extends BaseAPI
{
    private const BODY_MAX = 2000;

    public function list()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->sendJsonResponse(405, 'Method not allowed');
            return;
        }

        $decoded = $this->validateToken();
        $bugId = trim((string) ($_GET['bug_id'] ?? ''));
        if ($bugId === '') {
            $this->sendJsonResponse(400, 'bug_id is required');
            return;
        }

        $bug = $this->loadBug($bugId);
        if (!$bug) {
            $this->sendJsonResponse(404, 'Bug not found');
            return;
        }
        $this->assertBugReadAccess($decoded, $bug);

        $this->sendJsonResponse(200, 'OK', ['doubts' => $this->fetchThread($bugId)]);
    }

    public function create()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(405, 'Method not allowed');
            return;
        }

        $decoded = $this->validateToken();
        $userId = (string) $decoded->user_id;
        $data = $this->getRequestData() ?: [];
        $bugId = trim((string) ($data['bug_id'] ?? ''));
        $body = $this->sanitizeBody($data['body'] ?? '');
        $voices = $this->collectVoiceUploads();

        if ($bugId === '') {
            $this->sendJsonResponse(400, 'bug_id is required');
            return;
        }
        if ($body === '' && empty($voices)) {
            $this->sendJsonResponse(400, 'Add a description or a voice message');
            return;
        }

        $bug = $this->loadBug($bugId);
        if (!$bug) {
            $this->sendJsonResponse(404, 'Bug not found');
            return;
        }
        $this->assertProjectAccess($userId, $bug['project_id']);

        if ((string) $bug['reported_by'] === $userId) {
            $this->sendJsonResponse(403, 'The reporter cannot ask a doubt on their own bug');
            return;
        }

        $id = Utils::generateUUID();
        $now = $this->istNow();

        $this->conn->beginTransaction();
        try {
            $stmt = $this->conn->prepare(
                'INSERT INTO bug_doubts (id, bug_id, asked_by, body, created_at) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$id, $bugId, $userId, $body, $now]);
            $this->storeVoiceFiles($voices, $id, null, $userId);
            $this->conn->commit();
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            $this->sendJsonResponse(500, 'Failed to save doubt');
            return;
        }

        $thread = $this->fetchThread($bugId, $id);
        $doubt = $thread[0] ?? null;

        $this->sendJsonThen(function () use ($bug, $bugId, $userId) {
            try {
                NotificationManager::getInstance()->notifyBugDoubt(
                    $bugId,
                    (string) $bug['title'],
                    (string) $bug['project_id'],
                    $userId,
                    (string) $bug['reported_by']
                );
            } catch (Exception $e) {
                error_log('BugDoubtController::create notify: ' . $e->getMessage());
            }
        }, 201, 'Doubt submitted', ['doubt' => $doubt]);
    }

    public function reply()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(405, 'Method not allowed');
            return;
        }

        $decoded = $this->validateToken();
        $userId = (string) $decoded->user_id;
        $data = $this->getRequestData() ?: [];
        $doubtId = trim((string) ($data['doubt_id'] ?? ''));
        $body = $this->sanitizeBody($data['body'] ?? '');
        $voices = $this->collectVoiceUploads();

        if ($doubtId === '') {
            $this->sendJsonResponse(400, 'doubt_id is required');
            return;
        }
        if ($body === '' && empty($voices)) {
            $this->sendJsonResponse(400, 'Add a description or a voice message');
            return;
        }

        $doubtStmt = $this->conn->prepare(
            'SELECT d.id, d.bug_id, d.asked_by, b.project_id, b.title, b.reported_by
             FROM bug_doubts d
             INNER JOIN bugs b ON b.id = d.bug_id
             WHERE d.id = ?
             LIMIT 1'
        );
        $doubtStmt->execute([$doubtId]);
        $doubt = $doubtStmt->fetch(PDO::FETCH_ASSOC);
        if (!$doubt) {
            $this->sendJsonResponse(404, 'Doubt not found');
            return;
        }
        $this->assertProjectAccess($userId, $doubt['project_id']);

        $replyId = Utils::generateUUID();
        $now = $this->istNow();

        $this->conn->beginTransaction();
        try {
            $stmt = $this->conn->prepare(
                'INSERT INTO bug_doubt_replies (id, doubt_id, user_id, body, created_at) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$replyId, $doubtId, $userId, $body, $now]);
            $this->storeVoiceFiles($voices, $doubtId, $replyId, $userId);
            $this->conn->commit();
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            $this->sendJsonResponse(500, 'Failed to save reply');
            return;
        }

        $thread = $this->fetchThread((string) $doubt['bug_id'], $doubtId);
        $saved = $thread[0] ?? null;
        $savedReply = null;
        if ($saved && !empty($saved['replies'])) {
            foreach ($saved['replies'] as $row) {
                if ($row['id'] === $replyId) {
                    $savedReply = $row;
                    break;
                }
            }
        }

        $this->sendJsonThen(function () use ($doubt, $userId) {
            try {
                NotificationManager::getInstance()->notifyBugDoubtReply(
                    (string) $doubt['bug_id'],
                    (string) $doubt['title'],
                    (string) $doubt['project_id'],
                    $userId,
                    (string) $doubt['reported_by'],
                    (string) $doubt['asked_by']
                );
            } catch (Exception $e) {
                error_log('BugDoubtController::reply notify: ' . $e->getMessage());
            }
        }, 201, 'Reply submitted', ['reply' => $savedReply, 'doubt' => $saved]);
    }

    private function loadBug($bugId)
    {
        $stmt = $this->conn->prepare(
            'SELECT id, title, project_id, reported_by FROM bugs WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$bugId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function assertProjectAccess($userId, $projectId)
    {
        $members = new ProjectMemberController();
        if (!$members->hasProjectAccess($userId, $projectId)) {
            $this->sendJsonResponse(403, 'You do not have access to this project');
        }
    }

    /**
     * Why: Common Bugs detail is read-only for non-members, but doubt threads
     * should still be visible as reference context.
     */
    private function assertBugReadAccess($decoded, array $bug): void
    {
        $userId = (string) ($decoded->user_id ?? '');
        $projectId = $bug['project_id'] ?? null;
        if ($userId !== '' && $projectId !== null && $projectId !== '') {
            $members = new ProjectMemberController();
            if ($members->hasProjectAccess($userId, $projectId)) {
                return;
            }
        }

        if ($this->canReadCommonBugReference($decoded, (string) ($bug['id'] ?? ''))) {
            return;
        }

        $this->sendJsonResponse(403, 'You do not have access to this project');
    }

    private function canReadCommonBugReference($decoded, string $bugId): bool
    {
        if ($bugId === '') {
            return false;
        }

        $userId = (string) ($decoded->user_id ?? '');
        $role = strtolower(trim((string) ($decoded->role ?? '')));
        $isAdmin = $role === 'admin';
        $isDeveloper = $role === 'developer';
        $isTester = $role === 'tester';
        $hasCommonBugsPermission = false;

        if ($userId !== '') {
            try {
                $hasCommonBugsPermission = PermissionManager::getInstance()->hasPermissionOrAdmin(
                    $userId,
                    'COMMON_BUGS_VIEW',
                    $decoded->role ?? null
                );
            } catch (Exception $e) {
                error_log('BugDoubtController common bugs permission: ' . $e->getMessage());
            }
        }

        if (!$isAdmin && !$isDeveloper && !$isTester && !$hasCommonBugsPermission) {
            return false;
        }

        $controller = new BugController();
        $meta = $controller->getCommonBugMeta($bugId);
        return !empty($meta['is_common']);
    }

    private function sanitizeBody($raw)
    {
        $text = trim(strip_tags((string) $raw));
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, self::BODY_MAX);
        }
        return substr($text, 0, self::BODY_MAX);
    }

    private function istNow()
    {
        $ist = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
        return $ist->format('Y-m-d H:i:s');
    }

    private function collectVoiceUploads()
    {
        if (empty($_FILES['voice_notes'])) {
            return [];
        }
        $vn = $_FILES['voice_notes'];
        if (!isset($vn['tmp_name'])) {
            return [];
        }
        $items = [];
        if (is_array($vn['tmp_name'])) {
            foreach ($vn['tmp_name'] as $i => $tmp) {
                $items[] = [
                    'tmp_name' => $tmp,
                    'name' => $vn['name'][$i] ?? 'voice.webm',
                    'type' => $vn['type'][$i] ?? 'audio/webm',
                    'error' => $vn['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                    'duration' => $this->parseDuration($i),
                ];
            }
        } else {
            $items[] = [
                'tmp_name' => $vn['tmp_name'],
                'name' => $vn['name'] ?? 'voice.webm',
                'type' => $vn['type'] ?? 'audio/webm',
                'error' => $vn['error'] ?? UPLOAD_ERR_NO_FILE,
                'duration' => $this->parseDuration(0),
            ];
        }
        return array_values(array_filter($items, function ($item) {
            return ($item['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
                && !empty($item['tmp_name'])
                && is_uploaded_file($item['tmp_name']);
        }));
    }

    private function parseDuration($index)
    {
        $raw = $_POST['voice_note_duration_' . $index] ?? $_POST['voice_note_duration'] ?? 0;
        $seconds = (int) $raw;
        return $seconds > 0 ? $seconds : null;
    }

    private function storeVoiceFiles(array $voices, $doubtId, $replyId, $userId)
    {
        if (empty($voices)) {
            return;
        }
        $uploadDir = __DIR__ . '/../../uploads/doubt_voice/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $stmt = $this->conn->prepare(
            'INSERT INTO bug_doubt_attachments
             (id, doubt_id, reply_id, file_name, file_path, file_type, duration, uploaded_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $now = $this->istNow();
        foreach ($voices as $voice) {
            $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename((string) $voice['name']));
            if ($safeName === '' || $safeName === '_') {
                $safeName = 'voice.webm';
            }
            $dest = $uploadDir . uniqid('', true) . '_' . $safeName;
            if (!move_uploaded_file($voice['tmp_name'], $dest)) {
                continue;
            }
            $relative = 'uploads/doubt_voice/' . basename($dest);
            $stmt->execute([
                Utils::generateUUID(),
                $doubtId,
                $replyId,
                $safeName,
                $relative,
                $voice['type'] ?: 'audio/webm',
                $voice['duration'],
                $userId,
                $now,
            ]);
        }
    }

    private function fetchThread($bugId, $onlyDoubtId = null)
    {
        $params = [$bugId];
        $sql = 'SELECT d.id, d.bug_id, d.asked_by, d.body, d.created_at, u.username AS asked_by_name
                FROM bug_doubts d
                INNER JOIN users u ON u.id = d.asked_by
                WHERE d.bug_id = ?';
        if ($onlyDoubtId) {
            $sql .= ' AND d.id = ?';
            $params[] = $onlyDoubtId;
        }
        $sql .= ' ORDER BY d.created_at ASC';
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        $doubts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$doubts) {
            return [];
        }

        $ids = array_column($doubts, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $replyStmt = $this->conn->prepare(
            "SELECT r.id, r.doubt_id, r.user_id, r.body, r.created_at, u.username AS user_name
             FROM bug_doubt_replies r
             INNER JOIN users u ON u.id = r.user_id
             WHERE r.doubt_id IN ($placeholders)
             ORDER BY r.created_at ASC"
        );
        $replyStmt->execute($ids);
        $replies = $replyStmt->fetchAll(PDO::FETCH_ASSOC);

        $attStmt = $this->conn->prepare(
            "SELECT id, doubt_id, reply_id, file_name, file_path, file_type, duration, uploaded_by, created_at
             FROM bug_doubt_attachments
             WHERE doubt_id IN ($placeholders)
             ORDER BY created_at ASC"
        );
        $attStmt->execute($ids);
        $attachments = $attStmt->fetchAll(PDO::FETCH_ASSOC);

        $repliesByDoubt = [];
        foreach ($replies as $reply) {
            $reply['attachments'] = [];
            $repliesByDoubt[$reply['doubt_id']][] = $reply;
        }
        $doubtAtt = [];
        $replyAtt = [];
        foreach ($attachments as $att) {
            if (!empty($att['reply_id'])) {
                $replyAtt[$att['reply_id']][] = $att;
            } else {
                $doubtAtt[$att['doubt_id']][] = $att;
            }
        }

        foreach ($repliesByDoubt as $doubtId => $list) {
            foreach ($list as $i => $reply) {
                $repliesByDoubt[$doubtId][$i]['attachments'] = $replyAtt[$reply['id']] ?? [];
            }
        }

        foreach ($doubts as $i => $doubt) {
            $doubts[$i]['attachments'] = $doubtAtt[$doubt['id']] ?? [];
            $doubts[$i]['replies'] = $repliesByDoubt[$doubt['id']] ?? [];
        }
        return $doubts;
    }
}
