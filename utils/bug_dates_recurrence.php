<?php
/**
 * Why: Expand BugDates recurrence rules into concrete occurrence dates (IST)
 * so calendar feeds, holiday checks, and hooks stay consistent.
 */

/**
 * @param array{
 *   recurrence_type?:string,
 *   recurrence_days?:mixed,
 *   start_date:string,
 *   end_date?:?string
 * } $event
 * @return list<string> YYYY-MM-DD dates inclusive within [$from, $to]
 */
function br_bug_dates_expand_occurrences(array $event, string $from, string $to): array
{
    $from = substr(trim($from), 0, 10);
    $to = substr(trim($to), 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        return [];
    }
    if ($from > $to) {
        return [];
    }

    $startDate = substr(trim((string)($event['start_date'] ?? '')), 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
        return [];
    }

    $endDate = isset($event['end_date']) ? substr(trim((string)$event['end_date']), 0, 10) : '';
    if ($endDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        $endDate = '';
    }

    $type = strtolower(trim((string)($event['recurrence_type'] ?? 'none')));
    if ($type === '') {
        $type = 'none';
    }

    $tz = new DateTimeZone('Asia/Kolkata');
    $rangeStart = DateTimeImmutable::createFromFormat('Y-m-d', $from, $tz);
    $rangeEnd = DateTimeImmutable::createFromFormat('Y-m-d', $to, $tz);
    $anchor = DateTimeImmutable::createFromFormat('Y-m-d', $startDate, $tz);
    if (!$rangeStart || !$rangeEnd || !$anchor) {
        return [];
    }

    $hardEnd = $endDate !== ''
        ? DateTimeImmutable::createFromFormat('Y-m-d', $endDate, $tz)
        : null;

    $out = [];

    if ($type === 'none') {
        if ($startDate >= $from && $startDate <= $to) {
            if ($hardEnd === null || $anchor <= $hardEnd) {
                $out[] = $startDate;
            }
        }
        // Multi-day non-recurring span
        if ($hardEnd && $hardEnd > $anchor) {
            $cursor = $anchor > $rangeStart ? $anchor : $rangeStart;
            $stop = $hardEnd < $rangeEnd ? $hardEnd : $rangeEnd;
            while ($cursor <= $stop) {
                $key = $cursor->format('Y-m-d');
                if ($key >= $startDate) {
                    $out[] = $key;
                }
                $cursor = $cursor->modify('+1 day');
            }
            $out = array_values(array_unique($out));
            sort($out);
        }
        return $out;
    }

    if ($type === 'daily') {
        $cursor = $anchor > $rangeStart ? $anchor : $rangeStart;
        $stop = $rangeEnd;
        if ($hardEnd && $hardEnd < $stop) {
            $stop = $hardEnd;
        }
        while ($cursor <= $stop) {
            if ($cursor >= $anchor) {
                $out[] = $cursor->format('Y-m-d');
            }
            $cursor = $cursor->modify('+1 day');
        }
        return $out;
    }

    if ($type === 'weekly') {
        $days = br_bug_dates_normalize_weekdays($event['recurrence_days'] ?? null);
        if (empty($days)) {
            // Fall back to weekday of start_date
            $days = [(int)$anchor->format('N')];
        }
        $cursor = $rangeStart;
        while ($cursor <= $rangeEnd) {
            if ($cursor >= $anchor
                && ($hardEnd === null || $cursor <= $hardEnd)
                && in_array((int)$cursor->format('N'), $days, true)
            ) {
                $out[] = $cursor->format('Y-m-d');
            }
            $cursor = $cursor->modify('+1 day');
        }
        return $out;
    }

    if ($type === 'monthly') {
        $dayOfMonth = (int)$anchor->format('j');
        $cursor = $rangeStart->modify('first day of this month');
        $stopMonth = $rangeEnd->modify('first day of this month');
        while ($cursor <= $stopMonth) {
            $candidate = br_bug_dates_clamp_month_day($cursor, $dayOfMonth);
            if ($candidate
                && $candidate >= $rangeStart
                && $candidate <= $rangeEnd
                && $candidate >= $anchor
                && ($hardEnd === null || $candidate <= $hardEnd)
            ) {
                $out[] = $candidate->format('Y-m-d');
            }
            $cursor = $cursor->modify('+1 month');
        }
        return $out;
    }

    if ($type === 'yearly') {
        $month = (int)$anchor->format('n');
        $day = (int)$anchor->format('j');
        $startYear = (int)$rangeStart->format('Y');
        $endYear = (int)$rangeEnd->format('Y');
        for ($year = $startYear; $year <= $endYear; $year++) {
            $candidate = DateTimeImmutable::createFromFormat(
                'Y-n-j',
                sprintf('%d-%d-%d', $year, $month, $day),
                $tz
            );
            if (!$candidate) {
                continue;
            }
            $candidate = $candidate->setTime(0, 0, 0);
            if ($candidate >= $rangeStart
                && $candidate <= $rangeEnd
                && $candidate >= $anchor
                && ($hardEnd === null || $candidate <= $hardEnd)
            ) {
                $out[] = $candidate->format('Y-m-d');
            }
        }
        return $out;
    }

    return [];
}

/**
 * @param mixed $raw JSON string, array, or null
 * @return list<int> ISO weekdays 1=Mon … 7=Sun
 */
function br_bug_dates_normalize_weekdays($raw): array
{
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $raw = $decoded;
        } else {
            $raw = preg_split('/\s*,\s*/', $raw) ?: [];
        }
    }
    if (!is_array($raw)) {
        return [];
    }

    $map = [
        'mon' => 1, 'monday' => 1, '1' => 1,
        'tue' => 2, 'tues' => 2, 'tuesday' => 2, '2' => 2,
        'wed' => 3, 'wednesday' => 3, '3' => 3,
        'thu' => 4, 'thur' => 4, 'thurs' => 4, 'thursday' => 4, '4' => 4,
        'fri' => 5, 'friday' => 5, '5' => 5,
        'sat' => 6, 'saturday' => 6, '6' => 6,
        'sun' => 7, 'sunday' => 7, '7' => 7,
    ];

    $out = [];
    foreach ($raw as $item) {
        if (is_int($item) && $item >= 1 && $item <= 7) {
            $out[] = $item;
            continue;
        }
        $key = strtolower(trim((string)$item));
        if (isset($map[$key])) {
            $out[] = $map[$key];
        }
    }
    return array_values(array_unique($out));
}

function br_bug_dates_clamp_month_day(DateTimeImmutable $monthStart, int $dayOfMonth): ?DateTimeImmutable
{
    $last = (int)$monthStart->format('t');
    $day = max(1, min($dayOfMonth, $last));
    return $monthStart->setDate((int)$monthStart->format('Y'), (int)$monthStart->format('n'), $day);
}

/**
 * Whether bug_dates_events table exists.
 */
function br_bug_dates_tables_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $t = $conn->query("SHOW TABLES LIKE 'bug_dates_events'");
        $ready = (bool)($t && $t->fetch(PDO::FETCH_NUM));
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

/**
 * Closed-office dates in [from, to] from approved holiday/closed events (+ Sundays optional).
 *
 * @return list<string>
 */
function br_bug_dates_closed_dates(PDO $conn, string $from, string $to, bool $includeSundays = true): array
{
    $closed = [];
    $tz = new DateTimeZone('Asia/Kolkata');
    $fromDt = DateTimeImmutable::createFromFormat('Y-m-d', $from, $tz);
    $toDt = DateTimeImmutable::createFromFormat('Y-m-d', $to, $tz);
    if (!$fromDt || !$toDt) {
        return [];
    }

    if ($includeSundays) {
        $cursor = $fromDt;
        while ($cursor <= $toDt) {
            if ((int)$cursor->format('N') === 7) {
                $closed[] = $cursor->format('Y-m-d');
            }
            $cursor = $cursor->modify('+1 day');
        }
    }

    if (!br_bug_dates_tables_ready($conn)) {
        return array_values(array_unique($closed));
    }

    try {
        $stmt = $conn->prepare(
            "SELECT id, title, category, recurrence_type, recurrence_days, start_date, end_date, is_office_closed
             FROM bug_dates_events
             WHERE status = 'approved'
               AND is_office_closed = 1
               AND start_date <= ?
               AND (end_date IS NULL OR end_date >= ? OR recurrence_type <> 'none')
             ORDER BY start_date ASC, id ASC"
        );
        $stmt->execute([$to, $from]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            foreach (br_bug_dates_expand_occurrences($row, $from, $to) as $date) {
                $closed[] = $date;
            }
        }
    } catch (Throwable $e) {
        error_log('br_bug_dates_closed_dates: ' . $e->getMessage());
    }

    $closed = array_values(array_unique($closed));
    sort($closed);
    return $closed;
}

/**
 * True if the date is Sunday or an approved office-closed BugDates occurrence.
 */
function br_is_office_closed(string $date, ?PDO $conn = null): bool
{
    $date = substr(trim($date), 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }

    $tz = new DateTimeZone('Asia/Kolkata');
    $dt = DateTime::createFromFormat('Y-m-d', $date, $tz);
    if ($dt && (int)$dt->format('N') === 7) {
        return true;
    }

    if (!$conn || !br_bug_dates_tables_ready($conn)) {
        return false;
    }

    static $cache = [];
    $cacheKey = $date;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    try {
        $stmt = $conn->prepare(
            "SELECT id, recurrence_type, recurrence_days, start_date, end_date
             FROM bug_dates_events
             WHERE status = 'approved'
               AND is_office_closed = 1
               AND start_date <= ?
               AND (end_date IS NULL OR end_date >= ? OR recurrence_type IN ('daily','weekly','monthly','yearly'))"
        );
        $stmt->execute([$date, $date]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $hits = br_bug_dates_expand_occurrences($row, $date, $date);
            if (!empty($hits)) {
                $cache[$cacheKey] = true;
                return true;
            }
        }
    } catch (Throwable $e) {
        error_log('br_is_office_closed: ' . $e->getMessage());
    }

    $cache[$cacheKey] = false;
    return false;
}
