<?php
/**
 * Why: Super Admin (is_super_admin) can create Admins; regular Admins manage
 * workforce roles only. JWT role stays `admin` for both so /admin routes work.
 */

function br_users_has_is_super_admin_col(PDO $conn): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $c = $conn->query("SHOW COLUMNS FROM users LIKE 'is_super_admin'");
        $cached = (bool) ($c && $c->fetch(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        $cached = false;
    }
    return $cached;
}

function br_user_is_super_admin(PDO $conn, string $userId): bool
{
    $userId = trim($userId);
    if ($userId === '' || !br_users_has_is_super_admin_col($conn)) {
        return false;
    }
    try {
        $stmt = $conn->prepare(
            'SELECT is_super_admin FROM users WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$userId]);
        $v = $stmt->fetchColumn();
        return $v !== false && (int) $v === 1;
    } catch (Throwable $e) {
        return false;
    }
}

function br_actor_is_super_admin(PDO $conn, ?object $actor): bool
{
    if (!$actor || empty($actor->user_id)) {
        return false;
    }
    return br_user_is_super_admin($conn, (string) $actor->user_id);
}

function br_count_super_admins(PDO $conn): int
{
    if (!br_users_has_is_super_admin_col($conn)) {
        return 0;
    }
    try {
        $deleted = '';
        $c = $conn->query("SHOW COLUMNS FROM users LIKE 'deleted_at'");
        if ($c && $c->fetch(PDO::FETCH_ASSOC)) {
            $deleted = ' AND deleted_at IS NULL';
        }
        $stmt = $conn->query(
            "SELECT COUNT(*) FROM users WHERE is_super_admin = 1{$deleted}"
        );
        return (int) ($stmt ? $stmt->fetchColumn() : 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Keep SUPER_ADMIN user_permission in sync with the flag so PermissionManager
 * and the frontend isSuper() helper stay honest.
 */
function br_sync_super_admin_permission(PDO $conn, string $userId, bool $isSuper): void
{
    $userId = trim($userId);
    if ($userId === '') {
        return;
    }
    try {
        $pStmt = $conn->prepare(
            "SELECT id FROM permissions WHERE permission_key = 'SUPER_ADMIN' LIMIT 1"
        );
        $pStmt->execute();
        $permissionId = $pStmt->fetchColumn();
        if (!$permissionId) {
            return;
        }
        $find = $conn->prepare(
            'SELECT id, granted FROM user_permissions
             WHERE user_id = ? AND permission_id = ?
               AND (project_id IS NULL OR project_id = \'\')
             LIMIT 1'
        );
        $find->execute([$userId, $permissionId]);
        $row = $find->fetch(PDO::FETCH_ASSOC);
        if ($isSuper) {
            if ($row) {
                if ((int) $row['granted'] !== 1) {
                    $upd = $conn->prepare(
                        'UPDATE user_permissions SET granted = 1 WHERE id = ?'
                    );
                    $upd->execute([$row['id']]);
                }
            } else {
                $ins = $conn->prepare(
                    'INSERT INTO user_permissions (user_id, permission_id, project_id, granted, created_at)
                     VALUES (?, ?, NULL, 1, NOW())'
                );
                $ins->execute([$userId, $permissionId]);
            }
        } elseif ($row && (int) $row['granted'] === 1) {
            $upd = $conn->prepare(
                'UPDATE user_permissions SET granted = 0 WHERE id = ?'
            );
            $upd->execute([$row['id']]);
        }
    } catch (Throwable $e) {
        error_log('br_sync_super_admin_permission: ' . $e->getMessage());
    }
}

/**
 * @return array{role:?string,is_super_admin:int}|null
 */
function br_fetch_user_admin_flags(PDO $conn, string $userId): ?array
{
    $userId = trim($userId);
    if ($userId === '') {
        return null;
    }
    $hasFlag = br_users_has_is_super_admin_col($conn);
    $sql = $hasFlag
        ? 'SELECT role, is_super_admin FROM users WHERE id = ? LIMIT 1'
        : 'SELECT role, 0 AS is_super_admin FROM users WHERE id = ? LIMIT 1';
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return [
            'role' => isset($row['role']) ? strtolower((string) $row['role']) : null,
            'is_super_admin' => (int) ($row['is_super_admin'] ?? 0),
        ];
    } catch (Throwable $e) {
        return null;
    }
}

function br_role_is_admin_enum(?string $role): bool
{
    return strtolower(trim((string) $role)) === 'admin';
}

/**
 * Why: Regular Admins must not create or promote Admins / Super Admins.
 *
 * @return string|null Error message, or null when allowed
 */
function br_hierarchy_assign_role_error(
    PDO $conn,
    ?object $actor,
    string $targetRole,
    bool $targetIsSuperAdmin = false
): ?string {
    $targetRole = strtolower(trim($targetRole));
    $actorIsSuper = br_actor_is_super_admin($conn, $actor);

    if ($targetIsSuperAdmin) {
        if (!$actorIsSuper) {
            return 'Only a Super Admin can grant Super Admin';
        }
        return null;
    }

    if ($targetRole === 'admin') {
        if (!$actorIsSuper) {
            return 'Only a Super Admin can create or assign Admin accounts';
        }
        return null;
    }

    return null;
}

/**
 * Why: Regular Admins must not edit another Admin’s privileges or delete them.
 *
 * @return string|null Error message, or null when allowed
 */
function br_hierarchy_manage_admin_target_error(
    PDO $conn,
    ?object $actor,
    string $targetUserId,
    bool $touchingPrivileges
): ?string {
    $target = br_fetch_user_admin_flags($conn, $targetUserId);
    if (!$target) {
        return 'User not found';
    }
    $actorId = (string) ($actor->user_id ?? '');
    $actorIsSuper = br_actor_is_super_admin($conn, $actor);
    $targetIsAdmin = br_role_is_admin_enum($target['role']);
    $targetIsSuper = (int) $target['is_super_admin'] === 1;

    if (!$targetIsAdmin && !$targetIsSuper) {
        return null;
    }

    // Self-edit of non-privilege fields is handled elsewhere; privilege edits on self by non-super are blocked below.
    if ($touchingPrivileges || $actorId !== $targetUserId) {
        if ($targetIsSuper && !$actorIsSuper) {
            return 'Only a Super Admin can manage Super Admin accounts';
        }
        if ($targetIsAdmin && !$actorIsSuper && $actorId !== $targetUserId) {
            return 'Only a Super Admin can manage Admin accounts';
        }
        if ($touchingPrivileges && $targetIsAdmin && !$actorIsSuper) {
            return 'Only a Super Admin can change Admin privileges';
        }
    }

    return null;
}
