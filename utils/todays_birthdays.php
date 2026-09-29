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

const BR_BIRTHDAY_WISH_MESSAGE_MAX = 280;

/**
 * Why: Send wish must work before migrations 069/112 are run manually, so the table
 * and the `message` column self-heal once per request.
 */
function br_ensure_birthday_wishes_table(PDO $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS `birthday_wishes` (
              `id` CHAR(36) NOT NULL,
              `from_user_id` VARCHAR(64) NOT NULL,
              `to_user_id` VARCHAR(64) NOT NULL,
              `wish_date` DATE NOT NULL,
              `message` VARCHAR(280) NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_birthday_wish_day` (`from_user_id`, `to_user_id`, `wish_date`),
              KEY `idx_birthday_wishes_wall` (`to_user_id`, `wish_date`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
        $col = $conn->query("SHOW COLUMNS FROM `birthday_wishes` LIKE 'message'");
        if (!$col || !$col->fetch(PDO::FETCH_ASSOC)) {
            $conn->exec("ALTER TABLE `birthday_wishes` ADD COLUMN `message` VARCHAR(280) NULL AFTER `wish_date`");
        }
        $ready = true;
    } catch (Throwable $e) {
        error_log('br_ensure_birthday_wishes_table: ' . $e->getMessage());
        $ready = false;
    }
    return $ready;
}

/** Plain-text, single-paragraph wish message capped to the column size (null when empty). */
function br_sanitize_birthday_wish_message($raw): ?string
{
    $text = trim(strip_tags((string) $raw));
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
    $text = preg_replace('/\s+/u', ' ', $text) ?? '';
    if ($text === '') {
        return null;
    }
    return mb_substr($text, 0, BR_BIRTHDAY_WISH_MESSAGE_MAX);
}

/**
 * Wishes received today, grouped by celebrant, newest first.
 *
 * Why: messages are personal — only the celebrant and the sender see the text;
 * everyone else sees who wished (the team celebration), not what they wrote.
 *
 * @param list<string> $celebrantIds
 * @return array<string, list<array{id:string,from_user_id:string,username:string,avatar:?string,message:?string,created_at:string,is_mine:bool}>>
 */
function br_fetch_birthday_wishes(PDO $conn, array $celebrantIds, string $todayYmd, string $viewerId): array
{
    $celebrantIds = array_values(array_unique(array_filter(array_map('strval', $celebrantIds))));
    if ($celebrantIds === [] || !br_ensure_birthday_wishes_table($conn)) {
        return [];
    }

    $userCols = [];
    $ucRes = $conn->query('SHOW COLUMNS FROM users');
    if ($ucRes) {
        while ($row = $ucRes->fetch(PDO::FETCH_ASSOC)) {
            $userCols[] = $row['Field'];
        }
    }
    $avatarCols = array_map(
        static fn(string $c): string => 'u.' . $c,
        br_user_avatar_select_cols([], $userCols)
    );

    $placeholders = implode(',', array_fill(0, count($celebrantIds), '?'));
    $sql = 'SELECT w.id, w.from_user_id, w.to_user_id, w.message, w.created_at, u.username'
        . ($avatarCols ? ', ' . implode(', ', $avatarCols) : '') . '
            FROM birthday_wishes w
            INNER JOIN users u ON u.id = w.from_user_id
            WHERE w.wish_date = ? AND w.to_user_id IN (' . $placeholders . ')
            ORDER BY w.created_at DESC, w.id DESC';
    $stmt = $conn->prepare($sql);
    $stmt->execute(array_merge([$todayYmd], $celebrantIds));

    $grouped = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $row = br_user_with_resolved_avatar($row);
        $toId = (string) $row['to_user_id'];
        $fromId = (string) $row['from_user_id'];
        $canReadMessage = $viewerId === $toId || $viewerId === $fromId;
        $grouped[$toId][] = [
            'id' => (string) $row['id'],
            'from_user_id' => $fromId,
            'username' => (string) ($row['username'] ?? ''),
            'avatar' => $row['avatar'] ?? null,
            'message' => $canReadMessage && $row['message'] !== null ? (string) $row['message'] : null,
            'created_at' => (string) $row['created_at'],
            'is_mine' => $viewerId === $fromId,
        ];
    }
    return $grouped;
}
