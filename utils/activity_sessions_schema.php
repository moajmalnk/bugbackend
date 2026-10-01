<?php

/**
 * Helpers for user_activity_sessions — supports mixed DB schemas
 * (duration_minutes vs session_duration_minutes, optional is_active).
 */
class ActivitySessionsSchema
{
    /** Matches the 5-minute idle split in user/heartbeat.php. */
    public const IDLE_TIMEOUT_SECONDS = 300;
    public const MAX_SESSION_SECONDS = 12 * 3600;

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

    /**
     * Close every open session for a user (login / logout).
     *
     * Why: heartbeats keep session_end at the last moment the user was seen. If that is
     * older than the idle timeout the user had already left, so the session must end
     * there — ending it at "now" would credit days of idle time (e.g. 56h in one day).
     */
    public static function closeOpenSessions(PDO $conn, string $userId): int
    {
        $activePredicate = self::activeSessionPredicate($conn);
        $stmt = $conn->prepare("
            SELECT id, session_start, session_end
            FROM user_activity_sessions
            WHERE user_id = ? AND {$activePredicate}
        ");
        $stmt->execute([$userId]);
        $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$sessions) {
            return 0;
        }

        $tz = new DateTimeZone('Asia/Kolkata');
        $nowTs = time();
        $close = $conn->prepare(
            'UPDATE user_activity_sessions SET ' . self::closeSessionSetClause($conn) . ' WHERE id = ?'
        );

        foreach ($sessions as $session) {
            $startTs = (new DateTime($session['session_start'], $tz))->getTimestamp();
            $lastSeenTs = $session['session_end']
                ? (new DateTime($session['session_end'], $tz))->getTimestamp()
                : $startTs;
            $endTs = $nowTs - $lastSeenTs >= self::IDLE_TIMEOUT_SECONDS ? $lastSeenTs : $nowTs;
            $endTs = min(max($endTs, $startTs), $startTs + self::MAX_SESSION_SECONDS);

            $close->execute([
                (new DateTime('@' . $endTs))->setTimezone($tz)->format('Y-m-d H:i:s'),
                intdiv($endTs - $startTs, 60),
                $session['id'],
            ]);
        }

        return count($sessions);
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
