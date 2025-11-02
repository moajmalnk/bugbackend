<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/AnnouncementController.php';

$controller = new AnnouncementController();
$controller->getAll(); 