<?php
/**
 * Magic link storage helpers.
 */

/**
 * Why: magic_links.user_id was created as INT while users.id is a UUID
 * (VARCHAR(36)). Casting the UUID to int stored only its leading digits, so
 * verification could not find the owner (surfacing as "account no longer
 * active") and the numeric JOIN could even match a different user. Migration
 * 129 fixes the column; this applies the same change on first use so login
 * works on environments where the migration has not run yet. Cached per request.
 */
function br_ensure_magic_links_schema(PDO $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    try {
        $col = $conn->query("SHOW COLUMNS FROM magic_links LIKE 'user_id'");
        $row = $col ? $col->fetch(PDO::FETCH_ASSOC) : false;
        if (!$row) {
            return $ready = false;
        }
        if (stripos((string) $row['Type'], 'varchar') === 0) {
            return $ready = true;
        }

        $conn->exec(
            'ALTER TABLE magic_links MODIFY user_id VARCHAR(36) '
            . 'CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL'
        );
        // Rows written before the fix hold truncated ids that map to no user.
        $conn->exec(
            'DELETE ml FROM magic_links ml LEFT JOIN users u ON u.id = ml.user_id WHERE u.id IS NULL'
        );
        return $ready = true;
    } catch (Throwable $e) {
        error_log('br_ensure_magic_links_schema: ' . $e->getMessage());
        return $ready = false;
    }
}
