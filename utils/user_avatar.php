<?php
/**
 * Why: Production historically stored photos in profile_picture /
 * profile_picture_url while onboarding + messaging write users.avatar.
 * Dual-write/read so hard refresh always gets a loadable path.
 */

/**
 * @param list<string> $cols
 * @return list<string>
 */
function br_user_avatar_write_cols(array $cols): array
{
    $out = [];
    if (in_array('avatar', $cols, true)) {
        $out[] = 'avatar';
    }
    if (in_array('profile_picture', $cols, true)) {
        $out[] = 'profile_picture';
    }
    return $out;
}

/**
 * Append SELECT columns needed to resolve a display avatar.
 *
 * @param list<string> $select
 * @param list<string> $cols
 * @return list<string>
 */
function br_user_avatar_select_cols(array $select, array $cols): array
{
    foreach (['avatar', 'profile_picture', 'profile_picture_url'] as $col) {
        if (in_array($col, $cols, true) && !in_array($col, $select, true)) {
            $select[] = $col;
        }
    }
    return $select;
}

/**
 * Prefer uploaded avatar/profile_picture over Google profile_picture_url.
 *
 * @param array<string, mixed> $row
 */
function br_user_resolve_avatar(array $row): ?string
{
    foreach (['avatar', 'profile_picture', 'profile_picture_url'] as $key) {
        if (!array_key_exists($key, $row)) {
            continue;
        }
        $val = trim((string) ($row[$key] ?? ''));
        if ($val !== '') {
            return $val;
        }
    }
    return null;
}

/**
 * Normalize a users row so API clients always receive `avatar`.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function br_user_with_resolved_avatar(array $row): array
{
    $row['avatar'] = br_user_resolve_avatar($row);
    return $row;
}

/**
 * Append SET clauses + params for every writable avatar column.
 *
 * @param list<string> $cols
 * @param list<mixed> $params
 * @return array{0: string, 1: list<mixed>}
 */
function br_user_avatar_append_update(string $sqlFragment, array &$params, ?string $path, array $cols): string
{
    if ($path === null || $path === '') {
        return $sqlFragment;
    }
    foreach (br_user_avatar_write_cols($cols) as $col) {
        $sqlFragment .= ', `' . $col . '` = ?';
        $params[] = $path;
    }
    return $sqlFragment;
}

/**
 * Persist profile photo path to all available avatar columns.
 *
 * @param list<string>|null $cols
 */
function br_user_persist_avatar(PDO $conn, string $userId, string $path, ?array $cols = null): void
{
    if ($cols === null) {
        $cols = [];
        $colRes = $conn->query('SHOW COLUMNS FROM users');
        if ($colRes) {
            while ($row = $colRes->fetch(PDO::FETCH_ASSOC)) {
                $cols[] = $row['Field'];
            }
        }
    }

    $writeCols = br_user_avatar_write_cols($cols);
    if ($writeCols === []) {
        throw new RuntimeException(
            'No avatar/profile_picture column on users — run migration 064_users_avatar.sql'
        );
    }

    $sets = [];
    $params = [];
    foreach ($writeCols as $col) {
        $sets[] = '`' . $col . '` = ?';
        $params[] = $path;
    }
    $params[] = $userId;
    $stmt = $conn->prepare(
        'UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?'
    );
    $stmt->execute($params);
}
