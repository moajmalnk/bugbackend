<?php
/**
 * Lightweight sidebar nav counts (permission-gated, role-scoped).
 * GET /api/admin/sidebar_counts.php
 *
 * Why: One round-trip for every sidebar badge instead of loading each list page.
 */
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../utils/docs_sheets_recycle.php';

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
            'compliance' => 0,
            'bugs' => 0,
            'retests' => 0,
            'fixes' => 0,
            'updates' => 0,
            'docs' => 0,
            'sheets' => 0,
            'meetings' => 0,
            'tasks' => 0,
            'bugupdate' => 0,
            'weeklyReport' => 0,
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
            'recycleBin' => 0,
            'creative' => 0,
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
            $bugLive = $this->bugRecycleBinExclude();
            if ($isAdmin) {
                $counts['bugs'] = $this->countOrZero(
                    "SELECT COUNT(*) FROM bugs WHERE {$bugLive} AND status IN ('pending', 'in_progress')"
                );
                $counts['fixes'] = $this->countOrZero(
                    "SELECT COUNT(*) FROM bugs
                     WHERE {$bugLive}
                       AND status = 'fixed'
                       AND tester_retested = 1
                       AND tester_issue_fixed = 1"
                );
                $counts['retests'] = $this->countOrZero(
                    "SELECT COUNT(*) FROM bugs WHERE {$bugLive} AND status = 'fixed' AND tester_retested IS NULL"
                );
            } else {
                $counts['bugs'] = $this->countOrZero(
                    "SELECT COUNT(*) FROM bugs
                     WHERE {$bugLive}
                       AND status IN ('pending', 'in_progress')
                       AND project_id {$projectScopeSql}",
                    [$userId, $userId]
                );
                $counts['fixes'] = $this->countOrZero(
                    "SELECT COUNT(*) FROM bugs
                     WHERE {$bugLive}
                       AND status = 'fixed'
                       AND tester_retested = 1
                       AND tester_issue_fixed = 1
                       AND project_id {$projectScopeSql}",
                    [$userId, $userId]
                );
                $counts['retests'] = $this->countOrZero(
                    "SELECT COUNT(*) FROM bugs
                     WHERE {$bugLive}
                       AND status = 'fixed'
                       AND tester_retested IS NULL
                       AND project_id {$projectScopeSql}",
                    [$userId, $userId]
                );
            }
            $counts['dashboard'] = $counts['bugs'];
        }

        if ($this->dbTableExists('projects')) {
            $liveProjects = $this->projectRecycleBinExclude();
            $liveProjectsP = $this->projectRecycleBinExclude('p');
            $nonArchived = "(status != 'archived' OR status IS NULL)";
            $nonArchivedP = "(p.status != 'archived' OR p.status IS NULL)";
            if ($isAdmin) {
                $counts['projects'] = $this->countOrZero(
                    "SELECT COUNT(*) FROM projects WHERE {$nonArchived} AND {$liveProjects}"
                );
            } elseif ($this->dbTableExists('project_members')) {
                $counts['projects'] = $this->countOrZero(
                    "SELECT COUNT(DISTINCT pm.project_id)
                     FROM project_members pm
                     INNER JOIN projects p ON p.id = pm.project_id
                     WHERE pm.user_id = ? AND {$nonArchivedP} AND {$liveProjectsP}",
                    [$userId]
                );
            }

            if (
                ($isAdmin || $role === 'developer' || $role === 'tester')
                && $this->dbTableExists('project_compliance')
            ) {
                $counts['compliance'] = $this->countIncompleteCompliance(
                    $userId,
                    $isAdmin,
                    $nonArchivedP,
                    $liveProjectsP
                );
            }
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
            if ($isAdmin) {
                $docConds = [];
                if ($this->dbColumnExists('user_documents', 'is_archived')) {
                    $docConds[] = 'COALESCE(is_archived, 0) = 0';
                }
                if (br_user_documents_deleted_at_supported($this->conn)) {
                    $docConds[] = 'deleted_at IS NULL';
                }
                $docWhere = $docConds !== [] ? ' WHERE ' . implode(' AND ', $docConds) : '';
                $counts['docs'] = $this->countOrZero("SELECT COUNT(*) FROM user_documents{$docWhere}");
            } elseif ($role === 'developer' || $role === 'tester' || $role === 'creator') {
                $counts['docs'] = $this->countSharedDocuments($userId);
            }
        }

        if (
            ($can('SHEETS_VIEW') || $can('SHEETS_MANAGE') || $can('DOCS_VIEW'))
            && $this->dbTableExists('user_sheets')
        ) {
            if ($isAdmin) {
                $sheetConds = [];
                if ($this->dbColumnExists('user_sheets', 'is_archived')) {
                    $sheetConds[] = 'COALESCE(is_archived, 0) = 0';
                }
                if (br_user_sheets_deleted_at_supported($this->conn)) {
                    $sheetConds[] = 'deleted_at IS NULL';
                }
                $sheetWhere = $sheetConds !== [] ? ' WHERE ' . implode(' AND ', $sheetConds) : '';
                $counts['sheets'] = $this->countOrZero("SELECT COUNT(*) FROM user_sheets{$sheetWhere}");
            } elseif ($role === 'developer' || $role === 'tester' || $role === 'creator') {
                $counts['sheets'] = $this->countSharedSheets($userId);
            }
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
            $liveTasks = $this->taskRecycleBinExclude();
            $liveTasksSt = $this->taskRecycleBinExclude('st');
            if ($isAdmin) {
                $counts['tasks'] = $this->countOrZero(
                    "SELECT COUNT(*) FROM shared_tasks WHERE {$liveTasks}"
                );
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
                     WHERE ({$liveTasksSt}) AND (st.assigned_to = ? OR st.created_by = ?{$assigneeWhere})",
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
            ($isAdmin || $role === 'developer'
                || $can('DAILY_UPDATE_CREATE') || $can('DAILY_UPDATE_VIEW')
                || $can('UPDATES_VIEW') || $can('UPDATES_CREATE'))
            && $this->dbTableExists('weekly_reports')
        ) {
            $weeklyLive = $this->weeklyReportRecycleBinExclude();
            if ($isAdmin) {
                $counts['weeklyReport'] = $this->countOrZero(
                    "SELECT COUNT(*) FROM weekly_reports WHERE {$weeklyLive}"
                );
            } else {
                $counts['weeklyReport'] = $this->countOrZero(
                    "SELECT COUNT(*) FROM weekly_reports WHERE user_id = ? AND {$weeklyLive}",
                    [$userId]
                );
            }
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
            $bugLiveB = $this->bugRecycleBinExclude('b');
            $bugLivePlain = $this->bugRecycleBinExclude();
            $already = $this->dbColumnExists('bugs', 'already_raised')
                ? 'b.already_raised = 1'
                : '0 = 1';
            $counts['commonBugs'] = $this->countOrZero(
                "SELECT COUNT(*) FROM bugs b
                 LEFT JOIN (
                    SELECT project_id, LOWER(TRIM(title)) AS norm_title, COUNT(*) AS duplicate_count
                    FROM bugs
                    WHERE {$bugLivePlain}
                    GROUP BY project_id, LOWER(TRIM(title))
                    HAVING COUNT(*) > 1
                 ) dup
                    ON CAST(dup.project_id AS CHAR) = CAST(b.project_id AS CHAR)
                   AND LOWER(TRIM(b.title)) = dup.norm_title
                 WHERE {$bugLiveB} AND ({$already} OR COALESCE(dup.duplicate_count, 0) > 1)"
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
            if ($this->dbTableExists('project_activities')) {
                if ($isAdmin) {
                    $counts['activities'] = $this->countOrZero(
                        'SELECT COUNT(*) FROM project_activities'
                    );
                } else {
                    $counts['activities'] = $this->countOrZero(
                        "SELECT COUNT(*) FROM project_activities pa
                         WHERE (
                            pa.project_id IS NULL
                            OR pa.project_id IN (
                                SELECT DISTINCT project_id FROM project_members WHERE user_id = ?
                                UNION
                                SELECT DISTINCT id FROM projects WHERE created_by = ?
                            )
                         )",
                        [$userId, $userId]
                    );
                }
            } elseif ($this->dbTableExists('activities')) {
                $counts['activities'] = $this->countOrZero('SELECT COUNT(*) FROM activities');
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

        if ($can('RECYCLE_BIN_VIEW') && $this->dbTableExists('recycle_bin_items')) {
            $counts['recycleBin'] = $this->countOrZero(
                'SELECT COUNT(*) FROM recycle_bin_items
                 WHERE restored_at IS NULL AND purged_at IS NULL'
            );
        }

        if ($can('CREATIVE_VIEW') && $this->dbTableExists('creative_assets')) {
            if ($isAdmin || $can('CREATIVE_REVIEW') || $can('CREATIVE_MANAGE')) {
                $counts['creative'] = $this->countOrZero(
                    "SELECT COUNT(*) FROM creative_assets WHERE status = 'In Review'"
                );
            } else {
                $counts['creative'] = $this->countOrZero(
                    "SELECT COUNT(*) FROM creative_assets
                     WHERE creator_id = ? AND status IN ('Draft', 'In Review')",
                    [$userId]
                );
            }
        }

        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        $this->sendJsonResponse(200, 'OK', $counts);
    }

    /**
     * Why: Fallback compliance badge until the client recomputes pending rows from project payloads.
     */
    private function countIncompleteCompliance(
        string $userId,
        bool $isAdmin,
        string $nonArchivedP,
        string $liveProjectsP
    ): int {
        $incomplete = "COALESCE(pc.emergency_bypass, 0) = 0
            AND COALESCE(pc.pipeline_stage, 'developer_unverified') != 'admin_ready'";

        if ($isAdmin) {
            return $this->countOrZero(
                "SELECT COUNT(*) FROM projects p
                 LEFT JOIN project_compliance pc ON pc.project_id = p.id
                 WHERE {$nonArchivedP} AND {$liveProjectsP} AND {$incomplete}"
            );
        }

        if (!$this->dbTableExists('project_members')) {
            return 0;
        }

        return $this->countOrZero(
            "SELECT COUNT(DISTINCT p.id) FROM projects p
             INNER JOIN project_members pm ON pm.project_id = p.id
             LEFT JOIN project_compliance pc ON pc.project_id = p.id
             WHERE pm.user_id = ? AND {$nonArchivedP} AND {$liveProjectsP} AND {$incomplete}",
            [$userId]
        );
    }

    /**
     * Why: Soft-deleted recycle-bin projects must never inflate Projects / Compliance badges.
     */
    private function projectRecycleBinExclude(string $alias = ''): string
    {
        if (!$this->dbColumnExists('projects', 'deleted_at')) {
            return '1=1';
        }
        $p = $alias !== '' ? $alias . '.' : '';
        return "{$p}deleted_at IS NULL";
    }

    /**
     * Why: Recycle-bin bugs must never inflate sidebar badges or open-bug totals.
     */
    private function bugRecycleBinExclude(string $alias = ''): string
    {
        if (!$this->dbColumnExists('bugs', 'deleted_at')) {
            return '1=1';
        }
        $p = $alias !== '' ? $alias . '.' : '';
        return "{$p}deleted_at IS NULL";
    }

    /**
     * Why: Soft-deleted shared tasks belong in recycle bin, not the BugToDo badge.
     */
    private function taskRecycleBinExclude(string $alias = ''): string
    {
        if (!$this->dbColumnExists('shared_tasks', 'deleted_at')) {
            return '1=1';
        }
        $p = $alias !== '' ? $alias . '.' : '';
        return "{$p}deleted_at IS NULL";
    }

    /**
     * Why: Soft-deleted weekly reports belong in recycle bin, not the sidebar count.
     */
    private function weeklyReportRecycleBinExclude(string $alias = ''): string
    {
        if (!$this->dbColumnExists('weekly_reports', 'deleted_at')) {
            return '1=1';
        }
        $p = $alias !== '' ? $alias . '.' : '';
        return "{$p}deleted_at IS NULL";
    }

    /**
     * Why: Sidebar shared-doc badge must match BugDocs "Shared Docs" tab logic.
     */
    private function countSharedDocuments(string $userId): int
    {
        try {
            require_once __DIR__ . '/../docs/BugDocsController.php';
            $controller = new BugDocsController();
            $result = $controller->listSharedDocuments($userId, false);
            return (int) ($result['count'] ?? 0);
        } catch (Throwable $e) {
            error_log('AdminSidebarCountsController countSharedDocuments: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Why: Sidebar shared-sheet badge must match BugSheets "Shared Sheets" tab logic.
     */
    private function countSharedSheets(string $userId): int
    {
        try {
            require_once __DIR__ . '/../sheets/BugSheetsController.php';
            $controller = new BugSheetsController();
            $result = $controller->listSharedSheets($userId, false);
            return (int) ($result['count'] ?? 0);
        } catch (Throwable $e) {
            error_log('AdminSidebarCountsController countSharedSheets: ' . $e->getMessage());
            return 0;
        }
    }
}
