<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/BugDoubtController.php';

$controller = new BugDoubtController();
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $controller->list();
} else {
    $controller->create();
}
