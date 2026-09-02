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
