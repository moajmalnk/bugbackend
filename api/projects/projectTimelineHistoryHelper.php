<?php
/**
 * Why: Milestones are often marked, then rescheduled before work is complete.
 * Persist a full audit row for each timeline field change (who / from / to / when).
 */

class ProjectTimelineHistoryHelper
{
    public const FIELD_LABELS = [
        'start_date' => 'Start Date',
        'deadline_date' => 'Deadline Date',
        'expected_publish_date' => 'Expected Publish',
        'testing_start_date' => 'Testing Start',
        'testing_end_date' => 'Testing End',
        'frontend_finish_date' => 'Frontend Finish',
        'backend_finish_date' => 'Backend Finish',
        'tester_compliance_complete_date' => 'Tester Compliance Complete',
        'developer_compliance_complete_date' => 'Developer Compliance Complete',
    ];

    public static function ensureTable(PDO $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        try {
            $conn->exec(
                "CREATE TABLE IF NOT EXISTS `project_timeline_history` (
                    `id` varchar(36) NOT NULL,
                    `project_id` varchar(36) NOT NULL,
                    `field_key` varchar(64) NOT NULL,
                    `old_value` datetime DEFAULT NULL,
                    `new_value` datetime DEFAULT NULL,
                    `changed_by` varchar(36) NOT NULL,
                    `changed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_pth_project_changed` (`project_id`, `changed_at`),
                    KEY `idx_pth_project_field` (`project_id`, `field_key`, `changed_at`),
                    KEY `idx_pth_changed_by` (`changed_by`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
            );
        } catch (Throwable $e) {
            error_log('project_timeline_history ensure: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after  Only keys present in the update payload
     * @return list<array<string, mixed>>
     */
    public static function recordChanges(
        PDO $conn,
        string $projectId,
        string $userId,
        array $before,
        array $after
    ): array {
        self::ensureTable($conn);
        $changes = [];

        try {
            $insert = $conn->prepare(
                "INSERT INTO project_timeline_history
                    (id, project_id, field_key, old_value, new_value, changed_by, changed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
        } catch (Throwable $e) {
            error_log('project_timeline_history prepare: ' . $e->getMessage());
            return $changes;
        }

        $now = date('Y-m-d H:i:s');

        foreach (self::FIELD_LABELS as $field => $label) {
            if (!array_key_exists($field, $after)) {
                continue;
            }
            $old = self::normalize($before[$field] ?? null);
            $new = self::normalize($after[$field]);
            if ($old === $new) {
                continue;
            }

            $id = class_exists('Utils') ? Utils::generateUUID() : bin2hex(random_bytes(16));
            try {
                $insert->execute([$id, $projectId, $field, $old, $new, $userId, $now]);
            } catch (Throwable $e) {
                error_log('project_timeline_history insert: ' . $e->getMessage());
                continue;
            }

            $changes[] = [
                'field_key' => $field,
                'field_label' => $label,
                'old_value' => $old,
                'new_value' => $new,
            ];
        }

        return $changes;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function fetchForProject(PDO $conn, string $projectId): array
    {
        self::ensureTable($conn);

        try {
            $userCols = [];
            $colRes = $conn->query('SHOW COLUMNS FROM users');
            if ($colRes) {
                while ($row = $colRes->fetch(PDO::FETCH_ASSOC)) {
                    $userCols[] = $row['Field'];
                }
            }

            $select = [
                'h.id',
                'h.project_id',
                'h.field_key',
                'h.old_value',
                'h.new_value',
                'h.changed_by',
                'h.changed_at',
                'u.username AS changed_by_username',
            ];
            if (in_array('role', $userCols, true)) {
                $select[] = 'u.role AS changed_by_role';
            }
            if (in_array('avatar', $userCols, true)) {
                $select[] = 'u.avatar AS changed_by_avatar';
            }

            $stmt = $conn->prepare(
                'SELECT ' . implode(', ', $select) . '
                 FROM project_timeline_history h
                 LEFT JOIN users u ON u.id = h.changed_by
                 WHERE h.project_id = ?
                 ORDER BY h.changed_at DESC, h.id DESC'
            );
            $stmt->execute([$projectId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('project_timeline_history fetch: ' . $e->getMessage());
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $key = (string) $row['field_key'];
            $out[] = [
                'id' => $row['id'],
                'project_id' => $row['project_id'],
                'field_key' => $key,
                'field_label' => self::FIELD_LABELS[$key] ?? $key,
                'old_value' => $row['old_value'],
                'new_value' => $row['new_value'],
                'changed_by' => $row['changed_by'],
                'changed_by_username' => $row['changed_by_username'] ?: 'Unknown',
                'changed_by_role' => $row['changed_by_role'] ?? null,
                'changed_by_avatar' => $row['changed_by_avatar'] ?? null,
                'changed_at' => $row['changed_at'],
            ];
        }

        return $out;
    }

    public static function formatHuman(?string $value): string
    {
        if ($value === null || $value === '') {
            return 'Not set';
        }
        $ts = strtotime($value);
        if (!$ts) {
            return $value;
        }
        return date('d M Y, g:i A', $ts);
    }

    private static function normalize($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $raw = trim(str_replace('T', ' ', (string) $value));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $raw . ' 09:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $raw)) {
            return $raw . ':00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $raw)) {
            return $raw;
        }
        $ts = strtotime($raw);
        return $ts ? date('Y-m-d H:i:s', $ts) : $raw;
    }
}
