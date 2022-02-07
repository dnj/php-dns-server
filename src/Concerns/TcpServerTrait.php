<?php

namespace dnj\DnsServer\Concerns;

use dnj\DnsServer\TcpClient;
use Exception;
use Swoole;

trait TcpServerTrait
{
    /**
     * @var TcpClient[]
     */
    protected array $tcpClients = [];

    protected ?int $timeoutInterval = null;

    protected function setupTcpListeners(): void
    {
        $timeoutInterval = Swoole\Timer::tick(1000, [$this, 'tcpClientsTimeout']);
        if (!is_int($timeoutInterval)) {
            throw new Exception('Cannot init tcp timeout interval');
        }
        $this->timeoutInterval = $timeoutInterval;
        $this->on('Connect', [$this, 'onTcpConnect']);
        $this->on('Receive', [$this, 'onTcpReceive']);
        $this->on('Close', [$this, 'onTcpClose']);
    }

    protected function tcpClientsTimeout(): void
    {
        foreach ($this->tcpClients as $client) {
            if ($client->isTimeoutted()) {
                $this->closeTcpClient($client);
            }
        }
    }

    protected function findTcpClient(int $fd, int $reactorId): ?TcpClient
    {
        foreach ($this->tcpClients as $client) {
            if ($client->fd === $fd and $client->reactorId === $reactorId) {
                return $client;
            }
        }

        return null;
    }

    protected function addTcpClient(TcpClient $client): void
    {
        $this->tcpClients[] = $client;
    }

    protected function removeTcpClient(TcpClient $client): void
    {
        $key = array_search($client, $this->tcpClients);
        if (false === $key or !is_int($key)) {
            throw new Exception('Cannot find the client in list');
        }
        array_splice($this->tcpClients, $key, 1);
    }

    protected function closeTcpClient(TcpClient $client, bool $force = false): void
    {
        $client->close($force);
        $this->removeTcpClient($client);
    }

    public function onTcpConnect(Swoole\Server $server, int $fd, int $reactorId): void
    {
        $client = new TcpClient($server, $fd, $reactorId);
        $this->addTcpClient($client);
    }

    public function onTcpReceive(Swoole\Server $server, int $fd, int $reactorId, string $data): void
    {
        $client = $this->findTcpClient($fd, $reactorId);
        if (null === $client) {
            throw new Exception('Cannot find the client');
        }
        $client->receivePacket($data);
        try {
            while ($client->nextPacketIsReady()) {
                $packet = $client->getNextPacket();
                $response = $this->handleQueryFromStream($packet, $client);
                $response = pack('n', strlen($response)).$response;
                $client->send($response);
            }

            if ($client->isTimeoutted()) {
                $this->closeTcpClient($client);
            }
        } catch (Exception $e) {
            $this->closeTcpClient($client);
        }
    }

    public function onTcpClose(Swoole\Server $server, int $fd, int $reactorId): void
    {
        $client = $this->findTcpClient($fd, $reactorId);
        if (!$client) {
            return;
        }
        $this->removeTcpClient($client);
    }
}
