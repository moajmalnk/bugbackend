<?php
/**
 * Why: Project effort comes from checkout project_updates. Testers log hours on
 * projects too, so developer and tester time are stored separately — mixing
 * them overstated "Developer hours taken".
 */

require_once __DIR__ . '/checkout_time_allocation.php';

/**
 * Add projects.tester_hours_taken if missing. The first time it is added, every
 * project is recomputed so existing totals stop counting tester time as developer time.
 */
function br_ensure_project_role_hours_columns(PDO $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $cols = $conn->query('SHOW COLUMNS FROM projects')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('developer_hours_taken', $cols, true)) {
            return $ready = false;
        }
        if (in_array('tester_hours_taken', $cols, true)) {
            return $ready = true;
        }
        $conn->exec(
            'ALTER TABLE projects ADD COLUMN `tester_hours_taken` DECIMAL(8,1) DEFAULT NULL AFTER `developer_hours_taken`'
        );
        $ready = true;
        foreach ($conn->query('SELECT id FROM projects')->fetchAll(PDO::FETCH_COLUMN) as $pid) {
            br_recompute_project_role_hours($conn, (string) $pid);
        }
        return true;
    } catch (Throwable $e) {
        error_log('project role hours column: ' . $e->getMessage());
        return $ready = false;
    }
}

/**
 * Recompute developer_hours_taken and tester_hours_taken for one project from
 * every checkout's project_updates, split by the submitting user's role.
 * Admins and other roles count as developer effort.
 */
function br_recompute_project_role_hours(PDO $conn, string $projectId): void
{
    if ($projectId === '' || !br_ensure_project_role_hours_columns($conn)) {
        return;
    }
    try {
        $stmt = $conn->prepare(
            "SELECT ws.project_updates, LOWER(COALESCE(u.role, '')) AS role
             FROM work_submissions ws
             LEFT JOIN users u ON u.id = ws.user_id
             WHERE ws.project_updates IS NOT NULL
               AND ws.project_updates LIKE ?"
        );
        $stmt->execute(['%' . $projectId . '%']);

        $developer = 0.0;
        $tester = 0.0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $updates = json_decode($row['project_updates'] ?? '[]', true);
            if (!is_array($updates)) {
                continue;
            }
            foreach ($updates as $update) {
                if (!is_array($update) || (string) ($update['project_id'] ?? '') !== $projectId) {
                    continue;
                }
                $hours = br_clamp_hours($update['hours'] ?? 0);
                if ($row['role'] === 'tester') {
                    $tester += $hours;
                } else {
                    $developer += $hours;
                }
            }
        }

        $upd = $conn->prepare(
            'UPDATE projects SET developer_hours_taken = ?, tester_hours_taken = ? WHERE id = ?'
        );
        $upd->execute([round($developer, 1), round($tester, 1), $projectId]);
    } catch (Throwable $e) {
        error_log('project role hours recompute: ' . $e->getMessage());
    }
}
