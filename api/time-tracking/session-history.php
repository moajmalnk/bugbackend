<?php
require_once __DIR__ . '/TimeTrackingController.php';

$controller = new TimeTrackingController();
// For GET requests, merge query params with request body
$data = array_merge($_GET, $controller->getRequestData());
$controller->getSessionHistory($data);
