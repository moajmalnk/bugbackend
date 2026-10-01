<?php
/**
 * Why: BugMeet lists Google Calendar events, not BugRicer rows. The sidebar polls
 * every ~45s, so the live/upcoming Meet count is cached per user to avoid hitting
 * the Google Calendar API on every badge refresh.
 */

const BR_MEET_NAV_COUNT_TTL = 300;
const BR_MEET_NAV_COUNT_FAILURE_TTL = 120;

function br_meet_nav_count_cache_file(string $userId): string
{
    $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'bugricer_meet_nav';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir . DIRECTORY_SEPARATOR . hash('sha256', $userId) . '.json';
}

/**
 * Store the live/upcoming Meet count so the sidebar can reuse it.
 */
function br_meet_nav_count_store(string $userId, int $count, int $ttl = BR_MEET_NAV_COUNT_TTL): void
{
    $payload = json_encode([
        'count' => max(0, $count),
        'expires_at' => time() + $ttl,
    ]);
    if ($payload !== false) {
        @file_put_contents(br_meet_nav_count_cache_file($userId), $payload, LOCK_EX);
    }
}

/**
 * Drop the cached count after a Meet is created or deleted.
 */
function br_meet_nav_count_forget(string $userId): void
{
    $file = br_meet_nav_count_cache_file($userId);
    if (is_file($file)) {
        @unlink($file);
    }
}

function br_meet_nav_count_cached(string $userId): ?int
{
    $file = br_meet_nav_count_cache_file($userId);
    if (!is_file($file)) {
        return null;
    }
    $data = json_decode((string) @file_get_contents($file), true);
    if (!is_array($data) || (int) ($data['expires_at'] ?? 0) < time()) {
        return null;
    }
    return (int) ($data['count'] ?? 0);
}

/**
 * Why: Mirrors get-running-meets.php "running" bucket (live now or starting within
 * 24h) so the badge matches what the user sees when opening BugMeet.
 */
function br_meet_nav_count(PDO $conn, string $userId): int
{
    $cached = br_meet_nav_count_cached($userId);
    if ($cached !== null) {
        return $cached;
    }

    try {
        $stmt = $conn->prepare('SELECT 1 FROM google_tokens WHERE bugricer_user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        if (!$stmt->fetchColumn()) {
            br_meet_nav_count_store($userId, 0);
            return 0;
        }
    } catch (Throwable $e) {
        return 0;
    }

    try {
        require_once __DIR__ . '/../api/oauth/GoogleAuthService.php';
        $client = (new GoogleAuthService())->getClientForUser($userId);
        $calendar = new Google\Service\Calendar($client);

        $tz = new DateTimeZone('Asia/Kolkata');
        $now = new DateTime('now', $tz);
        $events = $calendar->events->listEvents('primary', [
            'timeMin' => (clone $now)->sub(new DateInterval('PT12H'))->format('c'),
            'timeMax' => (clone $now)->add(new DateInterval('PT24H'))->format('c'),
            'singleEvents' => true,
            'orderBy' => 'startTime',
        ]);

        $count = 0;
        foreach ($events->getItems() as $event) {
            $conference = $event->getConferenceData();
            if (!$conference || !$conference->getEntryPoints()) {
                continue;
            }
            $hasMeet = false;
            foreach ($conference->getEntryPoints() as $entry) {
                if ($entry->getEntryPointType() === 'video'
                    && strpos((string) $entry->getUri(), 'meet.google.com') !== false
                ) {
                    $hasMeet = true;
                    break;
                }
            }
            if (!$hasMeet) {
                continue;
            }
            $end = $event->getEnd();
            $endAt = new DateTime($end->getDateTime() ?? $end->getDate(), $tz);
            if ($endAt >= $now) {
                $count++;
            }
        }

        br_meet_nav_count_store($userId, $count);
        return $count;
    } catch (Throwable $e) {
        error_log(json_encode([
            'event' => 'sidebar.meet_count.failed',
            'user_id' => $userId,
            'error' => $e->getMessage(),
        ]));
        br_meet_nav_count_store($userId, 0, BR_MEET_NAV_COUNT_FAILURE_TTL);
        return 0;
    }
}
