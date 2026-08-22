<?php
/**
 * Why: Permanent delete from recycle bin reuses force-delete semantics per entity type.
 */
class RecycleBinPurger
{
    /** @var PDO */
    private $conn;

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
    }

    public function permanentDelete(string $entityType, string $entityId): void
    {
        switch ($entityType) {
            case 'bug':
                $this->purgeBug($entityId);
                return;
            case 'project':
                $this->purgeProject($entityId);
                return;
            case 'update':
                $this->purgeSimple('updates', $entityId);
                return;
            case 'user':
                $this->purgeUser($entityId);
                return;
            case 'client':
                $this->purgeClient($entityId);
                return;
            case 'weekly_report':
                $this->purgeSimple('weekly_reports', $entityId);
                return;
            case 'announcement':
                $this->purgeSimple('announcements', $entityId);
                return;
            case 'feedback':
                $this->purgeSimple('user_feedback', $entityId);
                return;
            case 'short':
                $this->purgeShort($entityId);
                return;
            case 'activity':
                $this->purgeSimple('project_activities', $entityId);
                return;
            case 'doc':
                $this->purgeSimple('user_documents', $entityId);
                return;
            case 'sheet':
                $this->purgeSimple('user_sheets', $entityId);
                return;
            case 'role':
                $this->purgeSimple('roles', $entityId);
                return;
            case 'performance_review':
                $this->purgePerformanceReview($entityId);
                return;
            case 'work_submission':
                $this->purgeSimple('work_submissions', $entityId);
                return;
            case 'shared_task':
                $this->purgeSharedTask($entityId);
                return;
            case 'user_task':
                $this->purgeSimple('user_tasks', $entityId);
                return;
            case 'codo_rule':
                $this->purgeSimple('codo_common_rules', $entityId);
                return;
            default:
                throw new RuntimeException('Unsupported purge entity: ' . $entityType);
        }
    }

    private function purgeSimple(string $table, string $id): void
    {
        if (!$this->tableExists($table)) {
            return;
        }
        $stmt = $this->conn->prepare("DELETE FROM `{$table}` WHERE id = ?");
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Entity already removed from database.');
        }
    }

    private function purgeBug(string $id): void
    {
        $attachmentQuery = 'SELECT file_path FROM bug_attachments WHERE bug_id = ?';
        $attachmentStmt = $this->conn->prepare($attachmentQuery);
        $attachmentStmt->execute([$id]);
        $attachments = $attachmentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($attachments as $attachment) {
            $filePath = __DIR__ . '/../../' . ($attachment['file_path'] ?? '');
            if ($filePath && file_exists($filePath)) {
                @unlink($filePath);
            }
        }
        $stmt = $this->conn->prepare('DELETE FROM bugs WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Bug not found for purge.');
        }
    }

    private function purgeProject(string $id): void
    {
        $this->conn->prepare('DELETE FROM project_members WHERE project_id = ?')->execute([$id]);
        $this->conn->prepare('DELETE FROM updates WHERE project_id = ?')->execute([$id]);
        $this->conn->prepare('DELETE FROM bugs WHERE project_id = ?')->execute([$id]);
        $this->conn->prepare('DELETE FROM project_activities WHERE project_id = ?')->execute([$id]);
        $stmt = $this->conn->prepare('DELETE FROM projects WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Project not found for purge.');
        }
    }

    private function purgeClient(string $id): void
    {
        $this->conn->prepare('UPDATE projects SET client_id = NULL WHERE client_id = ?')->execute([$id]);
        $stmt = $this->conn->prepare('DELETE FROM clients WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Client not found for purge.');
        }
    }

    private function purgeUser(string $userId): void
    {
        require_once __DIR__ . '/../users/UserController.php';
        $controller = new UserController();
        $ref = new ReflectionClass($controller);
        if ($ref->hasMethod('forceDetachUserReferences')) {
            $method = $ref->getMethod('forceDetachUserReferences');
            $method->setAccessible(true);
            $method->invoke($controller, $userId);
        }

        $fkDisabled = false;
        try {
            $this->conn->exec('SET FOREIGN_KEY_CHECKS=0');
            $fkDisabled = true;
        } catch (Throwable $e) {
            /* ignore */
        }

        try {
            $stmt = $this->conn->prepare('DELETE FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            if ($stmt->rowCount() === 0) {
                throw new RuntimeException('User not found for purge.');
            }
        } finally {
            if ($fkDisabled) {
                try {
                    $this->conn->exec('SET FOREIGN_KEY_CHECKS=1');
                } catch (Throwable $e) {
                    /* ignore */
                }
            }
        }
    }

    private function purgeShort(string $id): void
    {
        if (!$this->tableExists('shorts')) {
            return;
        }
        $stmt = $this->conn->prepare('SELECT video_path, thumbnail_path FROM shorts WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            foreach (['video_path', 'thumbnail_path'] as $col) {
                if (!empty($row[$col])) {
                    $path = __DIR__ . '/../../' . ltrim($row[$col], '/');
                    if (file_exists($path)) {
                        @unlink($path);
                    }
                }
            }
        }
        $this->purgeSimple('shorts', $id);
    }

    private function purgePerformanceReview(string $id): void
    {
        if ($this->tableExists('review_answers')) {
            $this->conn->prepare('DELETE FROM review_answers WHERE review_id = ?')->execute([$id]);
        }
        $this->purgeSimple('performance_reviews', $id);
    }

    private function purgeSharedTask(string $id): void
    {
        if ($this->tableExists('shared_task_assignees')) {
            $this->conn->prepare('DELETE FROM shared_task_assignees WHERE shared_task_id = ?')->execute([$id]);
        }
        if ($this->tableExists('shared_task_projects')) {
            $this->conn->prepare('DELETE FROM shared_task_projects WHERE shared_task_id = ?')->execute([$id]);
        }
        $this->purgeSimple('shared_tasks', $id);
    }

    private function tableExists(string $table): bool
    {
        try {
            $stmt = $this->conn->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            );
            $stmt->execute([$table]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}
