<?php
require_once __DIR__ . '/TaskController.php';
$c = new TaskController();
$c->listMyTasks($_GET);
?>


