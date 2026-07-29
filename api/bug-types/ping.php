<?php
/**
 * Deploy / schema probe for bug types (no secrets).
 * Visit: /api/bug-types/ping.php
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$marker = 'bug-types-sync-v3-20260729';

try {
    require_once __DIR__ . '/../BaseAPI.php';
    $api = new BaseAPI();
    $conn = $api->getConnection();

    $tables = [
        'bug_types' => false,
        'bug_bug_types' => false,
    ];
    foreach (array_keys($tables) as $name) {
        try {
            $q = $conn->query("SELECT 1 FROM `{$name}` LIMIT 1");
            $tables[$name] = $q !== false;
        } catch (Throwable $e) {
            $tables[$name] = false;
        }
    }

    $typeCount = 0;
    $linkCount = 0;
    if ($tables['bug_types']) {
        $typeCount = (int) $conn->query("SELECT COUNT(*) FROM bug_types")->fetchColumn();
    }
    if ($tables['bug_bug_types']) {
        $linkCount = (int) $conn->query("SELECT COUNT(*) FROM bug_bug_types")->fetchColumn();
    }

    echo json_encode([
        'success' => true,
        'marker' => $marker,
        'tables' => $tables,
        'bug_types_count' => $typeCount,
        'bug_bug_types_count' => $linkCount,
        'php' => PHP_VERSION,
        'time' => gmdate('c'),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'marker' => $marker,
        'message' => $e->getMessage(),
    ]);
}
