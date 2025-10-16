<?php
require __DIR__ . '/../../vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

class SignalingServer implements MessageComponentInterface {
    protected $clients; // SplObjectStorage
    protected $rooms;   // code => [connId => conn]

    public function __construct() {
        $this->clients = new \SplObjectStorage();
        $this->rooms = [];
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        echo "Client connected: " . $conn->resourceId . "\n";
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $data = json_decode($msg, true);
        if (!is_array($data)) { return; }
        $type = $data['type'] ?? '';
        $code = $data['code'] ?? '';
        $payload = $data['payload'] ?? [];
        if ($type === 'join' && $code) {
            if (!isset($this->rooms[$code])) { $this->rooms[$code] = []; }
            $this->rooms[$code][$from->resourceId] = $from;
            
            // Store user information if provided
            $userInfo = $data['user'] ?? null;
            
            $this->broadcast($code, [ 
                'type' => 'peer-joined', 
                'peerId' => $from->resourceId,
                'user' => $userInfo
            ], $from);
            $from->send(json_encode(['type' => 'peers', 'peers' => array_keys($this->rooms[$code]) ]));
            return;
        }
        if ($type === 'signal' && $code) {
            $to = $payload['to'] ?? null;
            if (isset($this->rooms[$code])) {
                if ($to && isset($this->rooms[$code][$to])) {
                    $this->rooms[$code][$to]->send(json_encode(['type' => 'signal', 'from' => $from->resourceId, 'signal' => $payload['signal'] ?? null]));
                } else {
                    $this->broadcast($code, ['type' => 'signal', 'from' => $from->resourceId, 'signal' => $payload['signal'] ?? null], $from);
                }
            }
            return;
        }
        
        // Handle chat messages
        if ($type === 'chat' && $code) {
            $message = $payload['message'] ?? '';
            $this->broadcast($code, [
                'type' => 'chat',
                'from' => $from->resourceId,
                'message' => $message
            ], $from);
            return;
        }
        
        // Handle hand raising
        if ($type === 'hand-raise' && $code) {
            $raised = $payload['raised'] ?? false;
            $this->broadcast($code, [
                'type' => 'hand-raise',
                'from' => $from->resourceId,
                'raised' => $raised
            ], $from);
            return;
        }
    }

    public function onClose(ConnectionInterface $conn) {
        echo "Client disconnected: " . $conn->resourceId . "\n";
        $this->clients->detach($conn);
        foreach ($this->rooms as $code => &$members) {
            if (isset($members[$conn->resourceId])) {
                unset($members[$conn->resourceId]);
                $this->broadcast($code, [ 'type' => 'peer-left', 'peerId' => $conn->resourceId ]);
                if (empty($members)) { unset($this->rooms[$code]); }
                break;
            }
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "WebSocket error: " . $e->getMessage() . "\n";
        $conn->close();
    }

    private function broadcast($code, $message, $exclude = null) {
        if (!isset($this->rooms[$code])) { return; }
        $encoded = json_encode($message);
        foreach ($this->rooms[$code] as $peerId => $client) {
            if ($exclude && $client === $exclude) { continue; }
            $client->send($encoded);
        }
    }
}

$port = intval(getenv('BUGMEET_SIGNAL_PORT') ?: 8089);
$server = IoServer::factory(new HttpServer(new WsServer(new SignalingServer())), $port);
echo "BugMeet signaling server listening on :{$port}\n";
$server->run();

