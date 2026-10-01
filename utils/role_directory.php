<?php
/**
 * Why: Invite pickers (BugMeet etc.) need name + photo, not just email. The
 * get_all_{role}.php endpoints share this query so the payload stays consistent
 * and additive (email / phone keep their old shape for existing callers).
 */
require_once __DIR__ . '/user_avatar.php';

/**
 * @return list<array<string, mixed>>
 */
function br_role_directory(BaseAPI $api, string $role, string $cacheKey, int $ttl = 600): array
{
    $allowed = ['admin', 'developer', 'tester', 'creator'];
    if (!in_array($role, $allowed, true)) {
        return [];
    }

    $columnRows = $api->fetchCached('SHOW COLUMNS FROM users', [], 'users_columns', 3600);
    $cols = array_map(static fn($r) => (string) ($r['Field'] ?? ''), $columnRows ?: []);

    $select = br_user_avatar_select_cols(['id', 'username', 'email', 'phone', 'role'], $cols);
    $selectSql = implode(', ', array_map(static fn($c) => '`' . $c . '`', $select));

    $rows = $api->fetchCached(
        "SELECT {$selectSql} FROM users
         WHERE role = ? AND account_active = 1
         ORDER BY username ASC",
        [$role],
        $cacheKey,
        $ttl
    );

    return array_map(static function (array $row): array {
        $row = br_user_with_resolved_avatar($row);
        unset($row['profile_picture'], $row['profile_picture_url']);
        return $row;
    }, $rows ?: []);
}
