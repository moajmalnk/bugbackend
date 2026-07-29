<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/BugTypeController.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$c = new BugTypeController();
$c->update();
