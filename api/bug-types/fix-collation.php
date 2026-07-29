<?php
/**
 * One-shot collation fix for bug type tables (safe to re-run).
 * Visit once: /api/bug-types/fix-collation.php
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../BaseAPI.php';

try {
    $api = new BaseAPI();
    $conn = $api->getConnection();

    $statements = [
        "ALTER TABLE bug_types CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        "ALTER TABLE bug_bug_types CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
    ];
    $results = [];
    foreach ($statements as $sql) {
        try {
            $conn->exec($sql);
            $results[] = ['sql' => $sql, 'ok' => true];
        } catch (Throwable $e) {
            $results[] = ['sql' => $sql, 'ok' => false, 'error' => $e->getMessage()];
        }
    }

    $collations = [];
    foreach (['bug_types', 'bug_bug_types'] as $table) {
        $stmt = $conn->query(
            "SELECT COLUMN_NAME, COLLATION_NAME
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = " . $conn->quote($table) . "
               AND COLUMN_NAME IN ('id', 'bug_id', 'bug_type_id')"
        );
        $collations[$table] = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    echo json_encode([
        'success' => true,
        'marker' => 'bug-types-collation-fix-v1',
        'results' => $results,
        'collations' => $collations,
    ], JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
