<?php

/**
 * Helpers for user_activity_sessions — supports mixed DB schemas
 * (duration_minutes vs session_duration_minutes, optional is_active).
 */
class ActivitySessionsSchema
{
    private static $columns = null;

    public static function resetCache(): void
    {
        self::$columns = null;
    }

    public static function tableExists(PDO $conn): bool
    {
        return $conn->query("SHOW TABLES LIKE 'user_activity_sessions'")->rowCount() > 0;
    }

    public static function getColumns(PDO $conn): array
    {
        if (self::$columns !== null) {
            return self::$columns;
        }

        self::$columns = [];
        if (!self::tableExists($conn)) {
            return self::$columns;
        }

        $res = $conn->query("SHOW COLUMNS FROM user_activity_sessions");
        if ($res) {
            while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
                self::$columns[] = $row['Field'];
            }
        }

        return self::$columns;
    }

    public static function hasIsActive(PDO $conn): bool
    {
        return in_array('is_active', self::getColumns($conn), true);
    }

    public static function durationColumn(PDO $conn): ?string
    {
        $cols = self::getColumns($conn);
        if (in_array('session_duration_minutes', $cols, true)) {
            return 'session_duration_minutes';
        }
        if (in_array('duration_minutes', $cols, true)) {
            return 'duration_minutes';
        }
        return null;
    }

    /**
     * Add missing columns when safe (non-destructive).
     */
    public static function ensureSchema(PDO $conn): void
    {
        if (!self::tableExists($conn)) {
            return;
        }

        $cols = self::getColumns($conn);

        if (!in_array('is_active', $cols, true)) {
            try {
                $conn->exec(
                    "ALTER TABLE user_activity_sessions
                     ADD COLUMN is_active tinyint(1) DEFAULT 1 AFTER session_end"
                );
                self::resetCache();
            } catch (PDOException $e) {
                error_log('activity_sessions_schema: is_active migration skipped: ' . $e->getMessage());
            }
        }

        $cols = self::getColumns($conn);
        if (
            !in_array('session_duration_minutes', $cols, true)
            && in_array('duration_minutes', $cols, true)
        ) {
            try {
                $conn->exec(
                    "ALTER TABLE user_activity_sessions
                     CHANGE duration_minutes session_duration_minutes int(11) DEFAULT NULL"
                );
                self::resetCache();
            } catch (PDOException $e) {
                error_log('activity_sessions_schema: duration rename skipped: ' . $e->getMessage());
            }
        }
    }

    /** SQL fragment for finding the user's current open session. */
    public static function activeSessionPredicate(PDO $conn): string
    {
        if (self::hasIsActive($conn)) {
            return 'is_active = TRUE';
        }

        return 'session_end IS NOT NULL AND TIMESTAMPDIFF(MINUTE, updated_at, NOW()) < 5';
    }

    /** SQL CASE expression for minutes in a session row. */
    public static function minutesCaseExpression(PDO $conn): string
    {
        $durationCol = self::durationColumn($conn);
        $parts = [];

        if ($durationCol) {
            $parts[] = "WHEN {$durationCol} IS NOT NULL AND {$durationCol} > 0 THEN {$durationCol}";
        }
        $parts[] = 'WHEN session_end IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, session_start, session_end)';
        $parts[] = 'ELSE TIMESTAMPDIFF(MINUTE, session_start, NOW())';

        return 'CASE ' . implode(' ', $parts) . ' END';
    }

    public static function closeSessionSetClause(PDO $conn): string
    {
        $durationCol = self::durationColumn($conn) ?? 'session_duration_minutes';
        $sets = [
            'session_end = ?',
            "{$durationCol} = ?",
            'updated_at = NOW()',
        ];

        if (self::hasIsActive($conn)) {
            $sets[] = 'is_active = FALSE';
        }

        return implode(', ', $sets);
    }

    public static function insertColumns(PDO $conn): array
    {
        $columns = ['id', 'user_id', 'session_start', 'session_end'];
        $placeholders = ['?', '?', '?', '?'];

        if (self::hasIsActive($conn)) {
            $columns[] = 'is_active';
            $placeholders[] = 'TRUE';
        }

        $columns[] = 'created_at';
        $columns[] = 'updated_at';
        $placeholders[] = 'NOW()';
        $placeholders[] = 'NOW()';

        return [
            'columns' => $columns,
            'placeholders' => $placeholders,
        ];
    }
}
