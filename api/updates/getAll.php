<?php
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/updateController.php';

$controller = new UpdateController();
$controller->getAll();