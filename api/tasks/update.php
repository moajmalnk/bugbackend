<?php
require_once __DIR__ . '/TaskController.php';
$c = new TaskController();
$data = $c->getRequestData();
$c->updateTask($data);
?>


