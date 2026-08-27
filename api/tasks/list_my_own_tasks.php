<?php
require_once __DIR__ . '/TaskController.php';

class OwnTaskController extends TaskController {
    public function listMyOwnTasks($filters) {
        // Same live list as listMyTasks (includes recycle-bin exclusion + impersonation token).
        $this->listMyTasks($filters);
    }
}

$c = new OwnTaskController();
$c->listMyOwnTasks($_GET);
?>
