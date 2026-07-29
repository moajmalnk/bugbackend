<?php
/**
 * Full path probe: sync read via same SQL as BugController::getBugTypesForBug
 * Optional: ?bug_id=UUID
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../bugs/BugController.php';

$marker = 'bug-types-get-probe-v1';
$bugId = isset($_GET['bug_id']) ? trim((string) $_GET['bug_id']) : '';

try {
    $api = new BaseAPI();
    $conn = $api->getConnection();

    if ($bugId === '') {
        $row = $conn->query(
            "SELECT bug_id FROM bug_bug_types LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        $bugId = $row ? (string) $row['bug_id'] : '';
    }

    $rawJoin = null;
    $rawJoinError = null;
    $controllerTypes = null;
    $controllerError = null;
    $rawCount = 0;

    if ($bugId !== '') {
        try {
            $stmt = $conn->prepare(
                "SELECT t.id, t.name, t.slug
                 FROM bug_bug_types j
                 INNER JOIN bug_types t
                   ON t.id COLLATE utf8mb4_unicode_ci = j.bug_type_id COLLATE utf8mb4_unicode_ci
                 WHERE j.bug_id COLLATE utf8mb4_unicode_ci = CAST(? AS CHAR) COLLATE utf8mb4_unicode_ci
                 ORDER BY t.sort_order ASC, t.name ASC"
            );
            $stmt->execute([$bugId]);
            $rawJoin = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $rawJoinError = $e->getMessage();
        }

        try {
            $c = (int) $conn->prepare(
                "SELECT COUNT(*) FROM bug_bug_types
                 WHERE bug_id COLLATE utf8mb4_unicode_ci = CAST(? AS CHAR) COLLATE utf8mb4_unicode_ci"
            )->execute([$bugId]) ;
            // re-do count properly
            $cs = $conn->prepare(
                "SELECT COUNT(*) FROM bug_bug_types
                 WHERE bug_id COLLATE utf8mb4_unicode_ci = CAST(? AS CHAR) COLLATE utf8mb4_unicode_ci"
            );
            $cs->execute([$bugId]);
            $rawCount = (int) $cs->fetchColumn();
        } catch (Throwable $e) {
            $rawCount = -1;
        }

        // Reflect private getBugTypesForBug via public getById path is heavy;
        // instantiate controller and use enrich on a fake row.
        try {
            $controller = new BugController();
            $bugs = [['id' => $bugId]];
            $controller->enrichBugsWithTypes($bugs);
            $controllerTypes = $bugs[0]['bug_types'] ?? null;
        } catch (Throwable $e) {
            $controllerError = $e->getMessage();
        }
    }

    echo json_encode([
        'success' => true,
        'marker' => $marker,
        'bug_id' => $bugId,
        'junction_count' => $rawCount,
        'raw_join' => $rawJoin,
        'raw_join_error' => $rawJoinError,
        'controller_types' => $controllerTypes,
        'controller_error' => $controllerError,
    ], JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'marker' => $marker,
        'message' => $e->getMessage(),
    ]);
}
