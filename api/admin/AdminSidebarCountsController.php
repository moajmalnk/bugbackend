<?php
/**
 * Lightweight sidebar nav counts (permission-gated, role-scoped).
 * GET /api/admin/sidebar_counts.php
 *
 * Why: One round-trip for every sidebar badge instead of loading each list page.
 */
require_once __DIR__ . '/../BaseAPI.php';

class AdminSidebarCountsController extends BaseAPI
{
    /**
     * @param string $sql
     * @param array<int, mixed> $params
     */
    private function countOrZero(string $sql, array $params = []): int
    {
        try {
            $stmt = $params === [] ? $this->conn->query($sql) : $this->conn->prepare($sql);
            if ($params !== []) {
                $stmt->execute($params);
            }
            if (!$stmt) {
                return 0;
            }
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('AdminSidebarCountsController count: ' . $e->getMessage());
            return 0;
        }
    }

    private function emptyCounts(): array
    {
        return [
            'dashboard' => 0,
            'projects' => 0,
            'bugs' => 0,
            'fixes' => 0,
            'updates' => 0,
            'docs' => 0,
            'sheets' => 0,
            'meetings' => 0,
            'tasks' => 0,
            'bugupdate' => 0,
            'myleave' => 0,
            'messages' => 0,
            'commonBugs' => 0,
            'codo' => 0,
            'users' => 0,
            'clients' => 0,
            'ot' => 0,
            'leave' => 0,
            'attendance' => 0,
            'whatsapp' => 0,
            'feedbacks' => 0,
            'reviews' => 0,
            'activities' => 0,
            'push' => 0,
            'shorts' => 0,
            'settings' => 0,
            'backup' => 0,
        ];
    }

    public function get(): void
    {
        try {
            $decoded = $this->validateToken();
        } catch (Throwable $e) {
            $this->sendJsonResponse(401, $e->getMessage() ?: 'Unauthorized');
            return;
        }

        if (!$decoded || !isset($decoded->user_id)) {
            $this->sendJsonResponse(401, 'Unauthorized');
            return;
        }

        $pm = PermissionManager::getInstance();
        $userId = (string) $decoded->user_id;
        $legacyRole = $decoded->role ?? null;
        $role = strtolower(trim((string) $legacyRole));
        $isAdmin = $role === 'admin';
        $can = static function (string $key) use ($pm, $userId, $legacyRole): bool {
            return $pm->hasPermissionOrAdmin($userId, $key, $legacyRole);
        };

        $counts = $this->emptyCounts();
        $projectScopeSql = "IN (
            SELECT DISTINCT project_id FROM project_members WHERE user_id = ?
            UNION
            SELECT DISTINCT id FROM projects WHERE created_by = ?
        )";

        if ($this->dbTableExists('bugs')) {
            if ($isAdmin) {
                $counts['bugs'] = $this->countOrZero(
                    "SELECT COUNT(*) FROM bugs WHERE status IN ('pending', 'in_progress')"
                );
                $counts['fixes'] = $this->countOrZero(
                    "SELECT COUNT(*) FROM bugs WHERE status IN ('fixed', 'rejected')"
                );
            } else {
                $counts['bugs'] = $this->countOrZero(
                    "SELECT COUNT(*) FROM bugs
                     WHERE status IN ('pending', 'in_progress')
                       AND project_id {$projectScopeSql}",
                    [$userId, $userId]
                );
                $counts['fixes'] = $this->countOrZero(
                    "SELECT COUNT(*) FROM bugs
                     WHERE status IN ('fixed', 'rejected')
                       AND project_id {$projectScopeSql}",
                    [$userId, $userId]
                );
            }
            $counts['dashboard'] = $counts['bugs'];
        }

        if ($this->dbTableExists('projects')) {
            $counts['projects'] = $this->countOrZero('SELECT COUNT(*) FROM projects');
        }

        if ($this->dbTableExists('updates')) {
            if ($isAdmin) {
                $counts['updates'] = $this->countOrZero('SELECT COUNT(*) FROM updates');
            } else {
                $counts['updates'] = $this->countOrZero(
                    "SELECT COUNT(*) FROM updates WHERE project_id {$projectScopeSql}",
                    [$userId, $userId]
                );
            }
        }

        if (($can('DOCS_VIEW') || $can('DOCS_CREATE')) && $this->dbTableExists('user_documents')) {
            $archived = $this->dbColumnExists('user_documents', 'is_archived')
                ? ' WHERE COALESCE(is_archived, 0) = 0'
                : '';
            $counts['docs'] = $this->countOrZero("SELECT COUNT(*) FROM user_documents{$archived}");
        }

        if (
            ($can('SHEETS_VIEW') || $can('SHEETS_MANAGE') || $can('DOCS_VIEW'))
            && $this->dbTableExists('user_sheets')
        ) {
            $archived = $this->dbColumnExists('user_sheets', 'is_archived')
                ? ' WHERE COALESCE(is_archived, 0) = 0'
                : '';
            $counts['sheets'] = $this->countOrZero("SELECT COUNT(*) FROM user_sheets{$archived}");
        }

        if (
            ($can('MEETINGS_JOIN') || $can('MEETINGS_CREATE') || $can('MEETINGS_MANAGE') || $role === 'developer')
            && $this->dbTableExists('meetings')
        ) {
            if ($isAdmin) {
                $counts['meetings'] = $this->countOrZero('SELECT COUNT(*) FROM meetings');
            } else {
                $counts['meetings'] = $this->countOrZero(
                    'SELECT COUNT(*) FROM meetings WHERE created_by = ?',
                    [$userId]
                );
            }
        }

        if (
            ($can('TASKS_VIEW_ALL') || $can('TASKS_VIEW_ASSIGNED') || $can('TASKS_CREATE'))
            && $this->dbTableExists('shared_tasks')
        ) {
            if ($isAdmin || $can('TASKS_VIEW_ALL')) {
                $counts['tasks'] = $this->countOrZero('SELECT COUNT(*) FROM shared_tasks');
            } else {
                $assigneeJoin = $this->dbTableExists('shared_task_assignees')
                    ? ' LEFT JOIN shared_task_assignees sta ON st.id = sta.shared_task_id'
                    : '';
                $assigneeWhere = $this->dbTableExists('shared_task_assignees')
                    ? ' OR sta.assigned_to = ?'
                    : '';
                $params = $assigneeWhere !== '' ? [$userId, $userId, $userId] : [$userId, $userId];
                $counts['tasks'] = $this->countOrZero(
                    "SELECT COUNT(DISTINCT st.id) FROM shared_tasks st{$assigneeJoin}
                     WHERE st.assigned_to = ? OR st.created_by = ?{$assigneeWhere}",
                    $params
                );
            }
        }

        if ($this->dbTableExists('work_submissions')) {
            $counts['bugupdate'] = $this->countOrZero(
                'SELECT COUNT(*) FROM work_submissions WHERE user_id = ?',
                [$userId]
            );
        }

        if (
            ($can('LEAVE_VIEW') || $role === 'developer' || $role === 'user')
            && $this->dbTableExists('leave_requests')
        ) {
            $counts['myleave'] = $this->countOrZero(
                'SELECT COUNT(*) FROM leave_requests WHERE user_id = ?',
                [$userId]
            );
        }

        if (
            ($role === 'admin' || $role === 'developer' || $can('MESSAGING_VIEW'))
            && $this->dbTableExists('chat_groups')
            && $this->dbTableExists('chat_group_members')
        ) {
            $counts['messages'] = $this->countOrZero(
                "SELECT COUNT(*) FROM chat_groups cg
                 INNER JOIN chat_group_members cgm ON cgm.group_id = cg.id
                 WHERE cgm.user_id = ? AND COALESCE(cg.is_active, 1) = 1",
                [$userId]
            );
        }

        if ($can('COMMON_BUGS_VIEW') && $this->dbTableExists('bugs')) {
            $already = $this->dbColumnExists('bugs', 'already_raised')
                ? 'b.already_raised = 1'
                : '0 = 1';
            $counts['commonBugs'] = $this->countOrZero(
                "SELECT COUNT(*) FROM bugs b
                 LEFT JOIN (
                    SELECT project_id, LOWER(TRIM(title)) AS norm_title, COUNT(*) AS duplicate_count
                    FROM bugs
                    GROUP BY project_id, LOWER(TRIM(title))
                    HAVING COUNT(*) > 1
                 ) dup
                    ON CAST(dup.project_id AS CHAR) = CAST(b.project_id AS CHAR)
                   AND LOWER(TRIM(b.title)) = dup.norm_title
                 WHERE {$already} OR COALESCE(dup.duplicate_count, 0) > 1"
            );
        }

        if ($can('CODO_VIEW') && $this->dbTableExists('codo_common_rules')) {
            $counts['codo'] = $this->countOrZero(
                'SELECT COUNT(*) FROM codo_common_rules WHERE COALESCE(is_active, 1) = 1'
            );
        }

        if ($can('PUSH_COVERAGE_VIEW') && $this->dbTableExists('users')) {
            $counts['push'] = $this->countOrZero('SELECT COUNT(*) FROM users');
        }

        if ($can('USERS_VIEW') && $this->dbTableExists('users')) {
            $counts['users'] = $this->countOrZero('SELECT COUNT(*) FROM users');
        }

        if ($can('CLIENTS_VIEW') && $this->dbTableExists('clients')) {
            $counts['clients'] = $this->countOrZero('SELECT COUNT(*) FROM clients');
        }

        if (
            $can('OVERTIME_MANAGE')
            && $this->dbTableExists('work_submissions')
            && $this->dbColumnExists('work_submissions', 'extra_hours_approval_status')
        ) {
            $counts['ot'] = $this->countOrZero(
                "SELECT COUNT(*) FROM work_submissions
                 WHERE extra_hours_approval_status = 'pending'"
            );
        }

        if ($can('LEAVE_MANAGE') && $this->dbTableExists('leave_requests')) {
            $counts['leave'] = $this->countOrZero(
                "SELECT COUNT(*) FROM leave_requests WHERE status = 'pending'"
            );
        }

        if ($can('ATTENDANCE_MANAGE') && $this->dbTableExists('attendance_wfh_requests')) {
            $counts['attendance'] = $this->countOrZero(
                "SELECT COUNT(*) FROM attendance_wfh_requests WHERE status = 'pending'"
            );
        }

        $showWhatsApp = $can('MESSAGING_CREATE') && $isAdmin;
        if ($showWhatsApp && $this->dbTableExists('users') && $this->dbColumnExists('users', 'phone')) {
            $counts['whatsapp'] = $this->countOrZero(
                "SELECT COUNT(*) FROM users
                 WHERE phone IS NOT NULL AND TRIM(phone) <> ''"
            );
        }

        if ($can('FEEDBACK_VIEW') && $this->dbTableExists('user_feedback')) {
            $counts['feedbacks'] = $this->countOrZero('SELECT COUNT(*) FROM user_feedback');
        }

        if ($can('PERFORMANCE_REVIEWS_MANAGE') && $this->dbTableExists('performance_reviews')) {
            $counts['reviews'] = $this->countOrZero('SELECT COUNT(*) FROM performance_reviews');
        }

        if ($can('ACTIVITY_VIEW')) {
            if ($this->dbTableExists('activities')) {
                $counts['activities'] = $this->countOrZero('SELECT COUNT(*) FROM activities');
            } elseif ($this->dbTableExists('project_activities')) {
                $counts['activities'] = $this->countOrZero('SELECT COUNT(*) FROM project_activities');
            }
        }

        if ($can('SHORTS_MANAGE') && $this->dbTableExists('shorts')) {
            $counts['shorts'] = $this->countOrZero('SELECT COUNT(*) FROM shorts');
        }

        if ($can('SETTINGS_EDIT') && $this->dbTableExists('settings')) {
            $counts['settings'] = $this->countOrZero('SELECT COUNT(*) FROM settings');
        }

        if (
            ($can('BACKUP_MANAGE') || $can('SETTINGS_EDIT'))
            && $this->dbTableExists('backup_jobs')
        ) {
            $counts['backup'] = $this->countOrZero('SELECT COUNT(*) FROM backup_jobs');
        }

        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        $this->sendJsonResponse(200, 'OK', $counts);
    }
}
