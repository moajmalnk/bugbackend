<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/UserController.php';

$controller = new UserController();
$controller->getUsers();
?> 