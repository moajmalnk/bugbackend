<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/BugController.php';

$controller = new BugController();
$controller->create();
?> 