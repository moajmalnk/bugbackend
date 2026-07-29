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
    $samples = [];
    if ($tables['bug_types']) {
        $typeCount = (int) $conn->query("SELECT COUNT(*) FROM bug_types")->fetchColumn();
    }
    if ($tables['bug_bug_types']) {
        $linkCount = (int) $conn->query("SELECT COUNT(*) FROM bug_bug_types")->fetchColumn();
        $sampleStmt = $conn->query(
            "SELECT j.bug_id, GROUP_CONCAT(t.name ORDER BY t.sort_order SEPARATOR ', ') AS type_names, COUNT(*) AS cnt
             FROM bug_bug_types j
             INNER JOIN bug_types t ON t.id = j.bug_type_id
             GROUP BY j.bug_id
             ORDER BY cnt DESC
             LIMIT 5"
        );
        if ($sampleStmt) {
            $samples = $sampleStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    }

    // Probe getBugTypesForBug-equivalent for first sample
    $probe = null;
    if (!empty($samples[0]['bug_id'])) {
        $bid = (string) $samples[0]['bug_id'];
        $p = $conn->prepare(
            "SELECT t.id, t.name, t.slug
             FROM bug_bug_types j
             INNER JOIN bug_types t ON t.id = j.bug_type_id
             WHERE CAST(j.bug_id AS CHAR) = CAST(? AS CHAR)
             ORDER BY t.sort_order ASC, t.name ASC"
        );
        $p->execute([$bid]);
        $probe = [
            'bug_id' => $bid,
            'types' => $p->fetchAll(PDO::FETCH_ASSOC) ?: [],
        ];
    }

    echo json_encode([
        'success' => true,
        'marker' => $marker,
        'tables' => $tables,
        'bug_types_count' => $typeCount,
        'bug_bug_types_count' => $linkCount,
        'samples' => $samples,
        'get_probe' => $probe,
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
