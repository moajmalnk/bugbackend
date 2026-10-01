<?php

require_once __DIR__ . '/activity_sessions_schema.php';

/**
 * Computes a user's presence (active hours) from user_activity_sessions.
 *
 * Why: the stored session_duration_minutes column cannot be trusted — login used to close
 * stale sessions with 0, logout closed them with "now - start" even after days of idling,
 * unclosed rows were counted up to NOW(), and multiple tabs/devices produce overlapping
 * rows. Presence is therefore rebuilt from raw start/end bounds:
 *   1. each session is capped at MAX_SESSION_SECONDS and clipped to the period and to now,
 *   2. overlapping sessions are merged so parallel tabs are counted once,
 *   3. time is split on IST hour boundaries so a calendar day can never exceed 24h.
 */
final class ActiveHoursCalculator
{
    public const PERIODS = ['daily', 'weekly', 'monthly', 'yearly'];

    /** Heartbeats only run while the tab is visible, so a real continuous session never gets near this. */
    public const MAX_SESSION_SECONDS = ActivitySessionsSchema::MAX_SESSION_SECONDS;

    /** Heartbeat interval is 30s; two missed beats still counts as online. */
    public const ONLINE_WINDOW_SECONDS = 120;

    public static function timezone(): DateTimeZone
    {
        return new DateTimeZone('Asia/Kolkata');
    }

    /**
     * Half-open [start, end) range for a period, anchored on $date (Y-m-d) or today.
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    public static function periodRange(string $period, ?string $date = null): array
    {
        $tz = self::timezone();
        $anchor = $date !== null
            ? DateTimeImmutable::createFromFormat('!Y-m-d', $date, $tz)
            : new DateTimeImmutable('today', $tz);

        switch ($period) {
            case 'weekly':
                $start = $anchor->modify('-' . ((int) $anchor->format('N') - 1) . ' days');
                $end = $start->modify('+7 days');
                break;
            case 'monthly':
                $start = $anchor->setDate((int) $anchor->format('Y'), (int) $anchor->format('n'), 1);
                $end = $start->modify('+1 month');
                break;
            case 'yearly':
                $start = $anchor->setDate((int) $anchor->format('Y'), 1, 1);
                $end = $start->modify('+1 year');
                break;
            default:
                $start = $anchor;
                $end = $start->modify('+1 day');
        }

        return [$start->setTime(0, 0), $end->setTime(0, 0)];
    }

    public static function isValidDate(?string $date): bool
    {
        if ($date === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }
        $tz = self::timezone();
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $tz);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            return false;
        }
        return (int) $parsed->format('Y') >= 2000 && $parsed <= new DateTimeImmutable('today', $tz);
    }

    public static function compute(PDO $conn, string $userId, string $period, ?string $date = null): array
    {
        $tz = self::timezone();
        [$rangeStart, $rangeEnd] = self::periodRange($period, $date);
        $nowTs = time();
        $startTs = $rangeStart->getTimestamp();
        $endTs = min($rangeEnd->getTimestamp(), $nowTs);
        $offset = $tz->getOffset($rangeStart);

        $merged = self::mergeIntervals(self::loadIntervals($conn, $userId, $startTs, $endTs, $nowTs));

        $days = [];
        $hourly = array_fill(0, 24, 0);
        $longest = 0;
        $totalSeconds = 0;

        foreach ($merged as [$s, $e]) {
            $longest = max($longest, $e - $s);
            $totalSeconds += $e - $s;
            $touchedDays = [];

            for ($cursor = $s; $cursor < $e;) {
                $local = $cursor + $offset;
                $nextHour = intdiv($local, 3600) * 3600 + 3600 - $offset;
                $segEnd = min($e, $nextHour);
                $seconds = $segEnd - $cursor;
                $dayKey = gmdate('Y-m-d', $local);

                if (!isset($days[$dayKey])) {
                    $days[$dayKey] = ['seconds' => 0, 'sessions' => 0, 'first' => $cursor, 'last' => $segEnd];
                }
                $days[$dayKey]['seconds'] += $seconds;
                $days[$dayKey]['first'] = min($days[$dayKey]['first'], $cursor);
                $days[$dayKey]['last'] = max($days[$dayKey]['last'], $segEnd);
                $touchedDays[$dayKey] = true;
                $hourly[(int) gmdate('G', $local)] += $seconds;

                $cursor = $segEnd;
            }

            foreach (array_keys($touchedDays) as $dayKey) {
                $days[$dayKey]['sessions']++;
            }
        }

        krsort($days);
        $dailyBreakdown = [];
        $totalMinutes = 0;
        foreach ($days as $dayKey => $day) {
            $minutes = (int) round($day['seconds'] / 60);
            $totalMinutes += $minutes;
            $dailyBreakdown[] = [
                'date' => $dayKey,
                'total_minutes' => $minutes,
                'session_count' => $day['sessions'],
                'first_seen' => self::iso($day['first'], $tz),
                'last_seen' => self::iso($day['last'], $tz),
            ];
        }

        $hourlyDistribution = [];
        $peakHour = null;
        foreach ($hourly as $hour => $seconds) {
            $hourlyDistribution[] = ['hour' => $hour, 'minutes' => (int) round($seconds / 60)];
            if ($seconds > 0 && ($peakHour === null || $seconds > $hourly[$peakHour])) {
                $peakHour = $hour;
            }
        }

        $activeDays = count($dailyBreakdown);
        $totalHours = round($totalMinutes / 60, 2);
        $daysElapsed = $endTs > $startTs ? (int) ceil(($endTs - $startTs) / 86400) : 0;
        $presence = self::presence($conn, $userId, $nowTs, $tz);

        $sessions = [];
        if ($period === 'daily') {
            foreach ($merged as [$s, $e]) {
                $sessions[] = [
                    'start' => self::iso($s, $tz),
                    'end' => self::iso($e, $tz),
                    'minutes' => (int) round(($e - $s) / 60),
                    'is_ongoing' => $presence['is_online'] && $e >= $nowTs - self::ONLINE_WINDOW_SECONDS,
                ];
            }
        }

        return [
            'period' => $period,
            'timezone' => $tz->getName(),
            'date_range' => [
                'start' => $rangeStart->format('Y-m-d H:i:s'),
                'end' => $rangeEnd->format('Y-m-d H:i:s'),
            ],
            'summary' => [
                'total_hours' => $totalHours,
                'total_minutes' => $totalMinutes,
                'total_sessions' => count($merged),
                'active_days' => $activeDays,
                'days_elapsed' => $daysElapsed,
                'average_hours_per_day' => $activeDays > 0 ? round($totalHours / $activeDays, 2) : 0,
                'average_minutes_per_active_day' => $activeDays > 0 ? (int) round($totalMinutes / $activeDays) : 0,
                'longest_session_minutes' => (int) round($longest / 60),
                'first_activity_at' => $merged ? self::iso($merged[0][0], $tz) : null,
                'last_activity_at' => $merged ? self::iso($merged[count($merged) - 1][1], $tz) : null,
                'peak_hour' => $peakHour,
            ],
            'presence' => $presence,
            'daily_breakdown' => $dailyBreakdown,
            'hourly_distribution' => $hourlyDistribution,
            'sessions' => $sessions,
            'generated_at' => self::iso($nowTs, $tz),
        ];
    }

    /** @return array<int, array{0:int,1:int}> */
    private static function loadIntervals(PDO $conn, string $userId, int $startTs, int $endTs, int $nowTs): array
    {
        if ($endTs <= $startTs || !ActivitySessionsSchema::tableExists($conn)) {
            return [];
        }

        // Sessions are capped, so anything starting more than the cap before the range cannot reach it.
        $stmt = $conn->prepare("
            SELECT session_start, session_end
            FROM user_activity_sessions
            WHERE user_id = ?
              AND session_start >= ?
              AND session_start < ?
            ORDER BY session_start ASC
        ");
        $stmt->execute([
            $userId,
            self::sql($startTs - self::MAX_SESSION_SECONDS),
            self::sql($endTs),
        ]);

        $intervals = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $s = self::parse($row['session_start']);
            if ($s === null) {
                continue;
            }
            $e = self::parse($row['session_end']) ?? $s;
            $e = min(max($e, $s), $s + self::MAX_SESSION_SECONDS, $nowTs);
            $s = max($s, $startTs);
            $e = min($e, $endTs);
            if ($e > $s) {
                $intervals[] = [$s, $e];
            }
        }
        return $intervals;
    }

    /**
     * @param array<int, array{0:int,1:int}> $intervals
     * @return array<int, array{0:int,1:int}>
     */
    private static function mergeIntervals(array $intervals): array
    {
        usort($intervals, static fn ($a, $b) => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($intervals as [$s, $e]) {
            $last = count($merged) - 1;
            if ($last >= 0 && $s <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $e);
            } else {
                $merged[] = [$s, $e];
            }
        }
        return $merged;
    }

    private static function presence(PDO $conn, string $userId, int $nowTs, DateTimeZone $tz): array
    {
        $lastSeen = null;
        try {
            $stmt = $conn->prepare("SELECT last_active_at FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $lastSeen = self::parse($stmt->fetchColumn() ?: null);
        } catch (PDOException $e) {
            // Older schemas may lack last_active_at; fall through to "unknown".
        }

        return [
            'is_online' => $lastSeen !== null && $nowTs - $lastSeen <= self::ONLINE_WINDOW_SECONDS,
            'last_seen_at' => $lastSeen !== null ? self::iso(min($lastSeen, $nowTs), $tz) : null,
        ];
    }

    private static function parse($value): ?int
    {
        if (!is_string($value) || $value === '' || strpos($value, '0000-00-00') === 0) {
            return null;
        }
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, self::timezone());
        return $dt ? $dt->getTimestamp() : null;
    }

    private static function sql(int $ts): string
    {
        return (new DateTimeImmutable('@' . $ts))->setTimezone(self::timezone())->format('Y-m-d H:i:s');
    }

    private static function iso(int $ts, DateTimeZone $tz): string
    {
        return (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->format(DATE_ATOM);
    }
}
