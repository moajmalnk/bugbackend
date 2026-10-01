<?php
chdir('/Applications/XAMPP/xamppfiles/htdocs/BugRicer/backend/api/users');
$_SERVER['HTTP_HOST'] = 'localhost'; $_SERVER['REQUEST_METHOD'] = 'GET';
require '/Applications/XAMPP/xamppfiles/htdocs/BugRicer/backend/api/users/__ws_class_tmp.php';
class WsTestException extends Exception {}
class H extends UserWorkStatsController {
  public $out;
  public function __construct() {
    $this->conn = new PDO('mysql:host=127.0.0.1;dbname=br_ws_test;charset=utf8mb4', 'root', '');
    $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  }
  public function validateToken() { global $actor; return (object)['user_id' => $actor]; }
  public function sendJsonResponse($s, $m, $d = null, $x = null, $y = null) { throw new WsTestException("$s $m"); }
}
set_error_handler(function($no,$str,$file,$line){ if ($no & (E_WARNING|E_NOTICE|E_DEPRECATED|E_USER_DEPRECATED)) { echo "  [php $no] $str @".basename($file).":$line\n"; } return true; });
$pdo = new PDO('mysql:host=127.0.0.1;dbname=br_ws_test', 'root', '');
$admin = $pdo->query("SELECT id FROM users WHERE role='admin' LIMIT 1")->fetchColumn();
$actor = null;
foreach ($pdo->query("SELECT id, role FROM users") as $u) {
  $actor = $u["id"]; try { (new H())->getUserWorkStats($u['id']); echo "NO RESPONSE {$u['id']}\n"; }
  catch (WsTestException $e) { echo "{$u['role']} {$u['id']}: ".$e->getMessage()."\n"; }
  catch (Throwable $e) { echo "FATAL {$u['role']} {$u['id']}: ".get_class($e).": ".$e->getMessage()." @ ".basename($e->getFile()).":".$e->getLine()."\n"; }
}
