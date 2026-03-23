<?php
require_once __DIR__ . '/WorkSubmissionController.php';

$c = new WorkSubmissionController();
$c->allRequestSubmissions($_GET);
?>
