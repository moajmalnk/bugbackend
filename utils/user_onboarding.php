<?php
/**
 * Why: Employee onboarding (docs, banking, password) is decided per person.
 * users.onboarding_mode holds an admin override; NULL means "role default":
 *   - required: developers and CODO testers — locked into the wizard until submitted.
 *   - off:      client testers, creators, admins — no wizard.
 * Admins may switch developers, creators and testers between
 *   required (locked until submitted), optional (fill from Profile any time) and off.
 *
 * users.onboarding_completed stays the "dashboard unlocked" flag the guards read,
 * so changing the mode also flips it (see br_onboarding_completed_after_mode_change).
 */

const BR_ONBOARDING_REQUIRED = 'required';
const BR_ONBOARDING_OPTIONAL = 'optional';
const BR_ONBOARDING_OFF = 'off';

/**
 * @return list<string>
 */
function br_onboarding_modes(): array
{
    return [BR_ONBOARDING_REQUIRED, BR_ONBOARDING_OPTIONAL, BR_ONBOARDING_OFF];
}

function br_normalize_onboarding_mode($value): ?string
{
    $v = strtolower(trim((string) ($value ?? '')));
    return in_array($v, br_onboarding_modes(), true) ? $v : null;
}

function br_onboarding_normalize_role($role, $roleId = null): string
{
    $role = strtolower(trim((string) $role));
    $roleId = ($roleId !== null && $roleId !== '') ? (int) $roleId : 0;
    if ($role === '' || !in_array($role, ['admin', 'developer', 'tester', 'creator'], true)) {
        if ($roleId === 1) {
            return 'admin';
        }
        if ($roleId === 2) {
            return 'developer';
        }
        if ($roleId === 3) {
            return 'tester';
        }
    }
    return $role;
}

/**
 * Roles an admin may switch between required / optional / off.
 */
function br_onboarding_configurable($role, $roleId = null): bool
{
    $role = br_onboarding_normalize_role($role, $roleId);
    return $role === 'developer' || $role === 'creator' || $role === 'tester';
}

/**
 * @param string|null $testerType 'codo' | 'client' — only meaningful for testers.
 */
function br_role_requires_onboarding($role, $roleId = null, $testerType = null): bool
{
    $role = br_onboarding_normalize_role($role, $roleId);
    if ($role === 'developer') {
        return true;
    }
    if ($role === 'tester') {
        return strtolower(trim((string) $testerType)) === 'codo';
    }
    return false;
}

function br_onboarding_default_mode($role, $roleId = null, $testerType = null): string
{
    return br_role_requires_onboarding($role, $roleId, $testerType)
        ? BR_ONBOARDING_REQUIRED
        : BR_ONBOARDING_OFF;
}

/**
 * Effective mode from role, tester type and the stored override.
 */
function br_onboarding_resolve_mode($role, $roleId, $testerType, $stored): string
{
    $default = br_onboarding_default_mode($role, $roleId, $testerType);
    if (!br_onboarding_configurable($role, $roleId)) {
        return $default;
    }
    return br_normalize_onboarding_mode($stored) ?? $default;
}

/**
 * @param array<string, mixed>|null $user Row with role / role_id / tester_type / onboarding_mode
 */
function br_user_onboarding_mode(?array $user): string
{
    if (!$user) {
        return BR_ONBOARDING_OFF;
    }
    return br_onboarding_resolve_mode(
        $user['role'] ?? null,
        $user['role_id'] ?? null,
        $user['tester_type'] ?? null,
        $user['onboarding_mode'] ?? null
    );
}

/** Locked into the wizard until the first submit. */
function br_user_requires_onboarding(?array $user): bool
{
    return br_user_onboarding_mode($user) === BR_ONBOARDING_REQUIRED;
}

/** Onboarding records and HR verification apply (required or optional). */
function br_user_onboarding_enabled(?array $user): bool
{
    return br_user_onboarding_mode($user) !== BR_ONBOARDING_OFF;
}

/**
 * Adds the effective `onboarding_mode` to a user row carrying role / tester_type
 * and (optionally) the raw column.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function br_user_row_with_onboarding_mode(array $row): array
{
    $role = strtolower((string) ($row['role'] ?? ''));
    $type = $role === 'tester'
        ? (strtolower(trim((string) ($row['tester_type'] ?? ''))) === 'codo' ? 'codo' : 'client')
        : null;
    $row['onboarding_mode'] = br_onboarding_resolve_mode(
        $row['role'] ?? null,
        $row['role_id'] ?? null,
        $type,
        $row['onboarding_mode'] ?? null
    );
    return $row;
}

/**
 * Why: the column is additive; add it on first use so older databases keep working.
 */
function br_ensure_onboarding_mode_schema(PDO $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $check = $conn->query("SHOW COLUMNS FROM users LIKE 'onboarding_mode'");
        if ($check && $check->fetch(PDO::FETCH_ASSOC)) {
            return $ready = true;
        }
        try {
            $conn->exec("ALTER TABLE users ADD COLUMN onboarding_mode ENUM('required','optional','off') NULL DEFAULT NULL");
        } catch (Throwable $e) {
            // A concurrent request may have added it first.
        }
        $check = $conn->query("SHOW COLUMNS FROM users LIKE 'onboarding_mode'");
        return $ready = (bool) ($check && $check->fetch(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        error_log('br_ensure_onboarding_mode_schema: ' . $e->getMessage());
        return $ready = false;
    }
}

/**
 * New onboarding_completed value after a mode change, or null to leave it.
 *
 * Why: Required → lock only people who never submitted (onboarding_completed_at
 * empty), so legacy finishers are not pushed back into the wizard. Optional/Off
 * → unlock anyone still waiting in the wizard.
 */
function br_onboarding_completed_after_mode_change(
    string $previousMode,
    string $nextMode,
    int $onboardingCompleted,
    $onboardingCompletedAt
): ?int {
    if ($nextMode === BR_ONBOARDING_REQUIRED) {
        if ($previousMode !== BR_ONBOARDING_REQUIRED
            && $onboardingCompleted === 1
            && empty($onboardingCompletedAt)) {
            return 0;
        }
        return null;
    }
    return $onboardingCompleted === 0 ? 1 : null;
}

/**
 * Why: Rejected HR verification must block check-in/checkout until docs are fixed
 * and re-verified. Pending and verified (and users without onboarding) stay allowed.
 *
 * @return array{ok:bool,message?:string}
 */
function br_assert_onboarding_allows_attendance(PDO $conn, string $userId): array
{
    try {
        $cols = [];
        $colRes = $conn->query('SHOW COLUMNS FROM users');
        if ($colRes) {
            while ($row = $colRes->fetch(PDO::FETCH_ASSOC)) {
                $cols[] = $row['Field'];
            }
        }
        if (!in_array('onboarding_verification_status', $cols, true)) {
            return ['ok' => true];
        }

        $select = ['role', 'onboarding_verification_status'];
        foreach (['role_id', 'tester_type', 'onboarding_mode'] as $col) {
            if (in_array($col, $cols, true)) {
                $select[] = $col;
            }
        }

        $stmt = $conn->prepare(
            'SELECT ' . implode(', ', $select) . ' FROM users WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || !br_user_onboarding_enabled($user)) {
            return ['ok' => true];
        }

        $status = strtolower(trim((string) ($user['onboarding_verification_status'] ?? 'none')));
        if ($status === 'rejected') {
            return [
                'ok' => false,
                'message' =>
                    'Check-in and checkout are blocked while your onboarding verification is rejected. Fix the issues on Profile, then wait for HR to re-verify.',
            ];
        }

        return ['ok' => true];
    } catch (Throwable $e) {
        error_log('br_assert_onboarding_allows_attendance: ' . $e->getMessage());
        return ['ok' => true];
    }
}
