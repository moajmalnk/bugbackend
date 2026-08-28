<?php

/**
 * Why: BugDocs/BugSheets admin delete uses recycle bin (deleted_at); live lists must hide those rows.
 */
function br_user_documents_deleted_at_supported(PDO $conn): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $cached = false;
    try {
        $check = $conn->query("SHOW COLUMNS FROM user_documents LIKE 'deleted_at'");
        $cached = $check && $check->rowCount() > 0;
    } catch (Throwable $e) {
        error_log('br_user_documents_deleted_at_supported: ' . $e->getMessage());
    }
    return $cached;
}

function br_user_sheets_deleted_at_supported(PDO $conn): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $cached = false;
    try {
        $check = $conn->query("SHOW COLUMNS FROM user_sheets LIKE 'deleted_at'");
        $cached = $check && $check->rowCount() > 0;
    } catch (Throwable $e) {
        error_log('br_user_sheets_deleted_at_supported: ' . $e->getMessage());
    }
    return $cached;
}

/**
 * SQL AND fragment excluding recycle-bin docs (empty when column missing).
 */
function br_user_documents_live_and(PDO $conn, string $alias = ''): string
{
    if (!br_user_documents_deleted_at_supported($conn)) {
        return '';
    }
    $p = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    return " AND {$p}deleted_at IS NULL";
}

/**
 * SQL AND fragment excluding recycle-bin sheets (empty when column missing).
 */
function br_user_sheets_live_and(PDO $conn, string $alias = ''): string
{
    if (!br_user_sheets_deleted_at_supported($conn)) {
        return '';
    }
    $p = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    return " AND {$p}deleted_at IS NULL";
}
