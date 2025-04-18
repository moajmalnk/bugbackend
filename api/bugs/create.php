<?php

require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/BugController.php';

header('Content-Type: application/json');

$controller = new BugController();
$controller->create(); 