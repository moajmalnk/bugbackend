<?php
/**
 * Why: Mandatory employee onboarding (docs, banking, password) is developer-only.
 * Testers/admins skip the wizard and log in with the emailed credentials.
 */

/**
 * @param array<string, mixed>|null $user Row with role / role_id
 */
function br_user_requires_onboarding(?array $user): bool
{
    if (!$user) {
        return false;
    }
    $roleId = isset($user['role_id']) ? (int) $user['role_id'] : 0;
    if ($roleId === 2) {
        return true;
    }
    $role = strtolower(trim((string) ($user['role'] ?? '')));
    return $role === 'developer';
}

function br_role_requires_onboarding($role, $roleId = null): bool
{
    if ($roleId !== null && $roleId !== '' && (int) $roleId === 2) {
        return true;
    }
    return strtolower(trim((string) $role)) === 'developer';
}
