<?php
require 'vendor/autoload.php';
require_once __DIR__ . '/db_connect.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

class VideoChat implements MessageComponentInterface{
    protected $rooms;

    public function __construct() {
        $this->rooms = [];
    }

    public function onOpen(ConnectionInterface $conn) {
        $queryString = $conn->httpRequest->getUri()->getQuery();
        parse_str($queryString, $queryParams);
        $roomId = $queryParams['room'] ?? null;

        if ($roomId) {
            $conn->roomId = $roomId;
            if (!isset($this->rooms[$roomId])) {
                $this->rooms[$roomId] = new SplObjectStorage();
            }
            $this->rooms[$roomId]->attach($conn);
            echo "New connection {$conn->resourceId} joined room {$roomId}\n";
        } else {
            echo "Connection {$conn->resourceId} rejected: Invalid or missing room ID '{$roomId}'.\n";
            $conn->close();
        }
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        if (isset($from->roomId) && isset($this->rooms[$from->roomId])) {
            foreach ($this->rooms[$from->roomId] as $client) {
                if ($from !== $client) {
                    $client->send($msg);
                }
            }
        }
    }

    public function onClose(ConnectionInterface $conn) {
        if (isset($conn->roomId) && isset($this->rooms[$conn->roomId])) {
            $this->rooms[$conn->roomId]->detach($conn);
            if (count($this->rooms[$conn->roomId]) === 0) {
                unset($this->rooms[$conn->roomId]);
                echo "Room {$conn->roomId} is now empty and removed from memory.\n";
            }
            echo "Connection {$conn->resourceId} from room {$conn->roomId} has disconnected\n";
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "An error has occurred: {$e->getMessage()}\n";
        $conn->close();
    }
}

$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new VideoChat()
        )
    ),
    8080
);

$server->run();