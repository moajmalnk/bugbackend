<?php
require_once __DIR__ . '/TimeTrackingController.php';

$controller = new TimeTrackingController();
$data = $controller->getRequestData();
$controller->checkIn($data);
