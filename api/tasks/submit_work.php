<?php
require_once __DIR__ . '/WorkSubmissionController.php';
$c = new WorkSubmissionController();
$data = $c->getRequestData();
$c->submit($data);
?>


