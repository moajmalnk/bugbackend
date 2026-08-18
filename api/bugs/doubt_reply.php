<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/BugDoubtController.php';

$controller = new BugDoubtController();
$controller->reply();
