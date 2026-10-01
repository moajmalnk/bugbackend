<?php

require_once __DIR__ . '/workforce_access.php';

/**
 * Per-user CODO Rules / Cursor Tips modes.
 *
 * Why: admins decide per person whether the CODO standards are mandatory
 * (forced acknowledgement gate), merely readable, or hidden. A NULL column
 * means "role default" so existing accounts keep their behaviour until an
 * admin changes them. Admins always read (optional); client testers and
 * roles outside the CODO team never see the standards (hidden).
 */

const BR_STANDARDS_REQUIRED = 'required';
const BR_STANDARDS_OPTIONAL = 'optional';
const BR_STANDARDS_HIDDEN = 'hidden';
const BR_STANDARDS_FEATURES = ['codo', 'cursor_tips'];

/**
 * @return list<string>
 */
function br_standards_modes(): array
{
    return [BR_STANDARDS_REQUIRED, BR_STANDARDS_OPTIONAL, BR_STANDARDS_HIDDEN];
}

function br_normalize_standards_mode($value): ?string
{
    $v = strtolower(trim((string) ($value ?? '')));
    return in_array($v, br_standards_modes(), true) ? $v : null;
}

function br_standards_column(string $feature): string
{
    return $feature === 'cursor_tips' ? 'cursor_tips_mode' : 'codo_rules_mode';
}

/**
 * Developers, creators and CODO testers are the only roles an admin can configure.
 */
function br_standards_configurable(string $role, ?string $testerType): bool
{
    $role = strtolower(trim($role));
    if ($role === 'developer' || $role === 'creator') {
        return true;
    }
    return $role === 'tester' && $testerType === BR_TESTER_TYPE_CODO;
}

/**
 * Mode used when the stored column is NULL (or the user is not configurable).
 */
function br_standards_default_mode(string $role, ?string $testerType, string $feature): string
{
    $role = strtolower(trim($role));
    if ($role === 'admin') {
        return BR_STANDARDS_OPTIONAL;
    }
    if (!br_standards_configurable($role, $testerType)) {
        return BR_STANDARDS_HIDDEN;
    }
    if ($feature === 'codo' && $role !== 'creator') {
        return BR_STANDARDS_REQUIRED;
    }
    return BR_STANDARDS_OPTIONAL;
}

/**
 * Effective mode from a role, tester type and stored override.
 */
function br_standards_resolve(string $role, ?string $testerType, $stored, string $feature): string
{
    $default = br_standards_default_mode($role, $testerType, $feature);
    if (!br_standards_configurable($role, $testerType)) {
        return $default;
    }
    return br_normalize_standards_mode($stored) ?? $default;
}

function br_standards_columns_exist(PDO $conn): bool
{
    $check = $conn->query("SHOW COLUMNS FROM users LIKE 'cursor_tips_mode'");
    $tips = (bool) ($check && $check->fetch(PDO::FETCH_ASSOC));
    $check = $conn->query("SHOW COLUMNS FROM users LIKE 'codo_rules_mode'");
    return $tips && (bool) ($check && $check->fetch(PDO::FETCH_ASSOC));
}

/**
 * Why: migration 119 may not have run everywhere; add the columns and the
 * Cursor Tips acknowledgement table on first use. Cached for the request.
 */
function br_ensure_standards_schema(PDO $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        if (!br_standards_columns_exist($conn)) {
            foreach (['codo_rules_mode', 'cursor_tips_mode'] as $col) {
                try {
                    $conn->exec("ALTER TABLE users ADD COLUMN {$col} ENUM('required','optional','hidden') NULL DEFAULT NULL");
                } catch (Throwable $e) {
                    // Column already exists (concurrent request or partial run).
                }
            }
        }
        $tbl = $conn->query("SHOW TABLES LIKE 'cursor_tips'");
        if ($tbl && $tbl->fetch(PDO::FETCH_NUM)) {
            $conn->exec(
                "CREATE TABLE IF NOT EXISTS `cursor_tip_acknowledgements` (
                  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `tip_id` INT UNSIGNED NOT NULL,
                  `user_id` VARCHAR(36) NOT NULL,
                  `status` VARCHAR(20) NOT NULL DEFAULT 'acknowledged',
                  `acknowledged_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `uq_cursor_tip_ack_tip_user` (`tip_id`, `user_id`),
                  KEY `idx_cursor_tip_ack_user` (`user_id`),
                  KEY `idx_cursor_tip_ack_status` (`status`),
                  CONSTRAINT `fk_cursor_tip_ack_tip`
                    FOREIGN KEY (`tip_id`) REFERENCES `cursor_tips` (`id`)
                    ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
            );
        }
        return $ready = br_standards_columns_exist($conn);
    } catch (Throwable $e) {
        error_log('br_ensure_standards_schema: ' . $e->getMessage());
        return $ready = false;
    }
}

/**
 * Effective modes for one user, cached for the request.
 *
 * @return array{codo: string, cursor_tips: string}
 */
function br_user_standards_modes(PDO $conn, string $userId): array
{
    static $cache = [];
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }
    $profile = br_workforce_profile($conn, $userId);
    if ($profile === null) {
        return $cache[$userId] = ['codo' => BR_STANDARDS_HIDDEN, 'cursor_tips' => BR_STANDARDS_HIDDEN];
    }
    $stored = ['codo_rules_mode' => null, 'cursor_tips_mode' => null];
    if (br_ensure_standards_schema($conn)) {
        $stmt = $conn->prepare('SELECT codo_rules_mode, cursor_tips_mode FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $stored = $stmt->fetch(PDO::FETCH_ASSOC) ?: $stored;
    }
    return $cache[$userId] = [
        'codo' => br_standards_resolve($profile['role'], $profile['tester_type'], $stored['codo_rules_mode'] ?? null, 'codo'),
        'cursor_tips' => br_standards_resolve($profile['role'], $profile['tester_type'], $stored['cursor_tips_mode'] ?? null, 'cursor_tips'),
    ];
}

function br_standards_mode(PDO $conn, string $userId, string $feature): string
{
    $modes = br_user_standards_modes($conn, $userId);
    return $modes[$feature === 'cursor_tips' ? 'cursor_tips' : 'codo'];
}

/**
 * Adds effective `codo_rules_mode` / `cursor_tips_mode` to a user row that
 * already carries role, tester_type and (optionally) the raw columns.
 */
function br_user_row_with_standards(array $row): array
{
    $role = (string) ($row['role'] ?? '');
    $type = strtolower($role) === 'tester'
        ? (br_normalize_tester_type($row['tester_type'] ?? null) ?? BR_TESTER_TYPE_CLIENT)
        : null;
    $row['codo_rules_mode'] = br_standards_resolve($role, $type, $row['codo_rules_mode'] ?? null, 'codo');
    $row['cursor_tips_mode'] = br_standards_resolve($role, $type, $row['cursor_tips_mode'] ?? null, 'cursor_tips');
    return $row;
}

/**
 * SQL predicate: users whose effective mode for $feature is "required".
 * Pass the alias without a trailing dot.
 */
function br_standards_required_sql(string $alias, string $feature, PDO $conn): string
{
    $p = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    if (!br_ensure_standards_schema($conn)) {
        return $feature === 'codo' ? "({$p}role IN ('developer','tester'))" : '(1 = 0)';
    }
    $col = $p . br_standards_column($feature);
    if ($feature === 'codo') {
        return "(COALESCE({$col}, CASE WHEN {$p}role = 'creator' THEN 'optional' ELSE 'required' END) = 'required')";
    }
    return "({$col} = 'required')";
}
