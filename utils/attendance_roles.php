<?php

/**
 * Why: Attendance exception rosters and office-day rollups must include creators
 * and testers — not only admin / developer / legacy user accounts.
 *
 * @return list<string>
 */
function br_workforce_roster_roles(): array
{
    return ['admin', 'developer', 'tester', 'creator', 'user'];
}

function br_is_workforce_roster_role(?string $role): bool
{
    $role = strtolower(trim((string) $role));
    return $role !== '' && in_array($role, br_workforce_roster_roles(), true);
}

/**
 * SQL IN list for prepared queries that filter users.role.
 */
function br_workforce_roster_role_sql_in(): string
{
    return "'" . implode("','", br_workforce_roster_roles()) . "'";
}

/**
 * Full roster predicate: workforce role AND not a client tester.
 * Why: Client testers are external reviewers and are never tracked for attendance.
 */
function br_workforce_roster_sql(string $alias = 'u', ?PDO $conn = null): string
{
    $p = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    $sql = "LOWER(TRIM(COALESCE({$p}role, ''))) IN (" . br_workforce_roster_role_sql_in() . ")";
    if ($conn !== null) {
        require_once __DIR__ . '/workforce_access.php';
        $sql .= ' AND ' . br_workforce_tester_sql($alias, $conn);
    }
    return $sql;
}
