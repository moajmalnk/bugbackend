<?php
require_once __DIR__ . '/ProjectController.php';

class ProjectWorkActivityController extends ProjectController {
    public function getActivity(array $query): void {
        $decoded = $this->validateToken();
        $projectId = trim((string)($query['project_id'] ?? ''));
        if ($projectId === '') {
            $this->sendJsonResponse(400, 'project_id is required');
            return;
        }

        $from = $query['from'] ?? date('Y-m-01');
        $to = $query['to'] ?? date('Y-m-t');

        $this->ensureProjectUpdatesColumn();

        $sql = "SELECT ws.id, ws.user_id, ws.submission_date, ws.hours_today, ws.project_updates, ws.planned_projects,
                       u.username, u.role
                FROM work_submissions ws
                INNER JOIN users u ON u.id = ws.user_id
                WHERE ws.submission_date BETWEEN ? AND ?
                  AND ws.project_updates IS NOT NULL
                  AND JSON_LENGTH(ws.project_updates) > 0
                ORDER BY ws.submission_date DESC, ws.updated_at DESC
                LIMIT 200";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$from, $to]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $entries = [];
        foreach ($rows as $row) {
            $updates = json_decode($row['project_updates'] ?? '[]', true);
            if (!is_array($updates)) {
                continue;
            }
            foreach ($updates as $update) {
                if (!is_array($update) || (string)($update['project_id'] ?? '') !== $projectId) {
                    continue;
                }
                $entries[] = [
                    'submission_id' => (int)$row['id'],
                    'submission_date' => $row['submission_date'],
                    'user_id' => $row['user_id'],
                    'username' => $row['username'],
                    'role' => $row['role'],
                    'hours_today' => (float)($row['hours_today'] ?? 0),
                    'status' => (string)($update['status'] ?? 'not_started'),
                    'progress_percentage' => max(0, min(100, (int)($update['progress_percentage'] ?? 0))),
                    'notes' => trim((string)($update['notes'] ?? '')),
                ];
            }
        }

        $this->sendJsonResponse(200, 'OK', [
            'project_id' => $projectId,
            'from' => $from,
            'to' => $to,
            'entries' => $entries,
        ]);
    }

    private function ensureProjectUpdatesColumn(): void {
        try {
            $check = $this->conn->query("SHOW COLUMNS FROM work_submissions LIKE 'project_updates'");
            if ($check->rowCount() === 0) {
                $this->conn->exec("ALTER TABLE work_submissions ADD COLUMN project_updates JSON NULL DEFAULT NULL AFTER planned_work_notes");
            }
        } catch (Exception $e) {
            // ignore
        }
    }
}

$controller = new ProjectWorkActivityController();
$controller->getActivity($_GET);
