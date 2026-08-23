<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/BugDoubtController.php';

$controller = new BugDoubtController();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = strtolower(trim((string) ($_GET['action'] ?? ($_POST['action'] ?? ''))));

if ($method === 'GET') {
    $controller->list();
} elseif ($method === 'DELETE' || ($method === 'POST' && $action === 'delete')) {
    $controller->deleteDoubt();
} elseif (in_array($method, ['PUT', 'PATCH'], true) || ($method === 'POST' && $action === 'update')) {
    $controller->updateDoubt();
} else {
    $controller->create();
}
