<?php

/**
 * Workforce access (BugUpdate, check-in/checkout, WFH, weekly report, self leave).
 *
 * Why: Testers are either CODO in-house staff or external client reviewers.
 * Client testers only report and verify bugs; they are not employees, so they
 * must never check in, submit daily work, file weekly reports or apply leave,
 * and must not appear in attendance rosters. The decision is read from the
 * database (users.tester_type) rather than the JWT so a reclassification by an
 * admin applies on the very next request.
 *
 * Every check fails closed: an unknown user, a missing tester_type or a schema
 * that cannot be confirmed is treated as "not workforce" for testers.
 */

const BR_TESTER_TYPE_CODO = 'codo';
const BR_TESTER_TYPE_CLIENT = 'client';
const BR_WORKFORCE_FORBIDDEN_MESSAGE = 'Work updates are available to CODO team members only.';
const BR_WORKFORCE_FORBIDDEN_REASON = 'client_tester';

/**
 * @return list<string>
 */
function br_tester_types(): array
{
    return [BR_TESTER_TYPE_CODO, BR_TESTER_TYPE_CLIENT];
}

/**
 * Normalise client input to a valid tester type or null.
 */
function br_normalize_tester_type($value): ?string
{
    $v = strtolower(trim((string) ($value ?? '')));
    return in_array($v, br_tester_types(), true) ? $v : null;
}

function br_tester_type_column_exists(PDO $conn): bool
{
    $check = $conn->query("SHOW COLUMNS FROM users LIKE 'tester_type'");
    return (bool) ($check && $check->fetch(PDO::FETCH_ASSOC));
}

/**
 * Why: Migration 115 may not have run on every environment yet; add the
 * column (backfilling existing testers to 'client') on first use so the gate
 * never crashes with an unknown column. Cached for the request.
 */
function br_ensure_tester_type_schema(PDO $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    try {
        if (br_tester_type_column_exists($conn)) {
            return $ready = true;
        }
        try {
            $conn->exec("ALTER TABLE users ADD COLUMN tester_type ENUM('codo','client') NULL DEFAULT NULL AFTER role");
        } catch (Throwable $e) {
            // A concurrent request may have added it first.
            if (!br_tester_type_column_exists($conn)) {
                throw $e;
            }
        }
        $conn->exec("UPDATE users SET tester_type = 'client' WHERE role = 'tester' AND tester_type IS NULL");
        try {
            $conn->exec('ALTER TABLE users ADD INDEX idx_users_role_tester_type (role, tester_type)');
        } catch (Throwable $e) {
            error_log('br_ensure_tester_type_schema index: ' . $e->getMessage());
        }
        return $ready = true;
    } catch (Throwable $e) {
        error_log('br_ensure_tester_type_schema: ' . $e->getMessage());
        return $ready = false;
    }
}

/**
 * Role + tester type for one user, cached for the request.
 *
 * @return array{role: string, tester_type: ?string}|null Null when the user does not exist.
 */
function br_workforce_profile(PDO $conn, string $userId): ?array
{
    static $cache = [];
    if ($userId === '') {
        return null;
    }
    if (array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }

    $hasType = br_ensure_tester_type_schema($conn);
    $stmt = $conn->prepare(
        'SELECT role' . ($hasType ? ', tester_type' : '') . ' FROM users WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return $cache[$userId] = null;
    }

    $role = strtolower(trim((string) ($row['role'] ?? '')));
    $type = null;
    if ($role === 'tester') {
        $type = $hasType
            ? (br_normalize_tester_type($row['tester_type'] ?? null) ?? BR_TESTER_TYPE_CLIENT)
            : BR_TESTER_TYPE_CLIENT;
    }
    return $cache[$userId] = ['role' => $role, 'tester_type' => $type];
}

/**
 * Current tester type for a user, or null for non-testers / unknown users.
 */
function br_user_tester_type(PDO $conn, string $userId): ?string
{
    $profile = br_workforce_profile($conn, $userId);
    return $profile['tester_type'] ?? null;
}

/**
 * Workforce = any existing non-tester user, or a tester explicitly marked CODO.
 */
function br_user_is_workforce(PDO $conn, string $userId): bool
{
    $profile = br_workforce_profile($conn, $userId);
    if ($profile === null) {
        return false;
    }
    return $profile['role'] !== 'tester' || $profile['tester_type'] === BR_TESTER_TYPE_CODO;
}

/**
 * Sends 403 and returns false when the caller is not workforce.
 *
 * @param BaseAPI $api Endpoint instance used to emit the JSON response.
 */
function br_require_workforce($api, PDO $conn, $decoded): bool
{
    $userId = (string) ($decoded->user_id ?? '');
    if (br_user_is_workforce($conn, $userId)) {
        return true;
    }
    $api->sendJsonResponse(403, BR_WORKFORCE_FORBIDDEN_MESSAGE, ['reason' => BR_WORKFORCE_FORBIDDEN_REASON]);
    return false;
}

/**
 * Keeps only workforce users from a list of IDs in one query (bulk admin actions).
 *
 * @param list<string> $userIds
 * @return list<string>
 */
function br_filter_workforce_user_ids(PDO $conn, array $userIds): array
{
    $userIds = array_values(array_unique(array_filter(array_map('strval', $userIds), 'strlen')));
    if ($userIds === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $stmt = $conn->prepare(
        "SELECT id FROM users WHERE id IN ($placeholders) AND " . br_workforce_tester_sql('', $conn)
    );
    $stmt->execute($userIds);
    $allowed = array_flip(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));
    return array_values(array_filter($userIds, static fn($id) => isset($allowed[$id])));
}

/**
 * SQL predicate that excludes client testers from workforce rosters.
 * When a connection is given and the column cannot be confirmed, all testers
 * are excluded (fail closed).
 */
function br_workforce_tester_sql(string $alias = '', ?PDO $conn = null): string
{
    $p = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    if ($conn !== null && !br_ensure_tester_type_schema($conn)) {
        return "({$p}role <> 'tester')";
    }
    return "({$p}role <> 'tester' OR {$p}tester_type = 'codo')";
}
