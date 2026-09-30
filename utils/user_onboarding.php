<?php
/**
 * Why: Mandatory employee onboarding (docs, banking, password) applies to
 * employees: developers and CODO (in-house) testers. Client testers, admins and
 * creators skip the wizard and log in with the emailed credentials.
 */

/**
 * @param array<string, mixed>|null $user Row with role / role_id / tester_type
 */
function br_user_requires_onboarding(?array $user): bool
{
    if (!$user) {
        return false;
    }
    return br_role_requires_onboarding(
        $user['role'] ?? null,
        $user['role_id'] ?? null,
        $user['tester_type'] ?? null
    );
}

/**
 * @param string|null $testerType 'codo' | 'client' — only meaningful for testers.
 */
function br_role_requires_onboarding($role, $roleId = null, $testerType = null): bool
{
    $role = strtolower(trim((string) $role));
    $roleId = ($roleId !== null && $roleId !== '') ? (int) $roleId : 0;

    if ($roleId === 2 || $role === 'developer') {
        return true;
    }
    if ($roleId === 3 || $role === 'tester') {
        return strtolower(trim((string) $testerType)) === 'codo';
    }
    return false;
}

/**
 * Why: Rejected HR verification must block check-in/checkout until docs are fixed
 * and re-verified. Pending and verified (and roles without onboarding) stay allowed.
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
        if (in_array('role_id', $cols, true)) {
            $select[] = 'role_id';
        }
        if (in_array('tester_type', $cols, true)) {
            $select[] = 'tester_type';
        }

        $stmt = $conn->prepare(
            'SELECT ' . implode(', ', $select) . ' FROM users WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || !br_user_requires_onboarding($user)) {
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
