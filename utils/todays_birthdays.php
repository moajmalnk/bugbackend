<?php
/**
 * Why: Shared IST birthday lookup for dashboard sticky + wish validation.
 * Never returns birth year — only public display fields.
 */

require_once __DIR__ . '/user_avatar.php';

/**
 * Today's calendar date in Asia/Kolkata (Y-m-d).
 */
function br_ist_today_ymd(): string
{
    $tz = new DateTimeZone('Asia/Kolkata');
    return (new DateTimeImmutable('now', $tz))->format('Y-m-d');
}

/**
 * @return list<array{id:string,username:string,role:?string,job_title:?string,department:?string,avatar:?string}>
 */
function br_fetch_todays_birthdays(PDO $conn, ?string $todayYmd = null): array
{
    $today = $todayYmd ?: br_ist_today_ymd();

    $hasOnboarding = false;
    try {
        $t = $conn->query("SHOW TABLES LIKE 'user_onboarding_details'");
        $hasOnboarding = $t && $t->rowCount() > 0;
    } catch (Throwable $e) {
        $hasOnboarding = false;
    }
    if (!$hasOnboarding) {
        return [];
    }

    $detailCols = [];
    $colRes = $conn->query('SHOW COLUMNS FROM user_onboarding_details');
    if ($colRes) {
        while ($row = $colRes->fetch(PDO::FETCH_ASSOC)) {
            $detailCols[] = $row['Field'];
        }
    }
    if (!in_array('date_of_birth', $detailCols, true)) {
        return [];
    }

    $userCols = [];
    $ucRes = $conn->query('SHOW COLUMNS FROM users');
    if ($ucRes) {
        while ($row = $ucRes->fetch(PDO::FETCH_ASSOC)) {
            $userCols[] = $row['Field'];
        }
    }

    $select = ['u.id', 'u.username', 'u.role'];
    if (in_array('job_title', $userCols, true)) {
        $select[] = 'u.job_title';
    }
    if (in_array('department', $userCols, true)) {
        $select[] = 'u.department';
    }
    $select = br_user_avatar_select_cols($select, $userCols);

    $whereActive = '';
    if (in_array('account_active', $userCols, true)) {
        $whereActive = ' AND (u.account_active IS NULL OR u.account_active = 1)';
    }

    $sql = 'SELECT ' . implode(', ', $select) . '
            FROM users u
            INNER JOIN user_onboarding_details d ON d.user_id = u.id
            WHERE d.date_of_birth IS NOT NULL
              AND MONTH(d.date_of_birth) = MONTH(?)
              AND DAY(d.date_of_birth) = DAY(?)
              ' . $whereActive . '
            ORDER BY u.username ASC';

    $stmt = $conn->prepare($sql);
    $stmt->execute([$today, $today]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $out = [];
    foreach ($rows as $row) {
        $row = br_user_with_resolved_avatar($row);
        $out[] = [
            'id' => (string) ($row['id'] ?? ''),
            'username' => (string) ($row['username'] ?? ''),
            'role' => isset($row['role']) ? (string) $row['role'] : null,
            'job_title' => isset($row['job_title']) && $row['job_title'] !== ''
                ? (string) $row['job_title']
                : null,
            'department' => isset($row['department']) && $row['department'] !== ''
                ? (string) $row['department']
                : null,
            'avatar' => $row['avatar'] ?? null,
        ];
    }

    return array_values(array_filter($out, static fn($u) => $u['id'] !== '' && $u['username'] !== ''));
}

/**
 * Whether userId is celebrating a birthday today (IST).
 */
function br_user_is_birthday_today(PDO $conn, string $userId, ?string $todayYmd = null): bool
{
    $userId = trim($userId);
    if ($userId === '') {
        return false;
    }
    foreach (br_fetch_todays_birthdays($conn, $todayYmd) as $person) {
        if ((string) $person['id'] === $userId) {
            return true;
        }
    }
    return false;
}
