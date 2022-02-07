<?php

namespace dnj\DnsServer;

use dnj\DnsServer\Contracts\IClient;
use Exception;
use Swoole\Server;

class TcpClient implements IClient
{
    public readonly int $connectTime;
    public readonly string $ip;
    public readonly int $port;
    public int $timeout = 1000; // ms
    public string $pendingPacket = '';
    public int $pendingPacketLength = 0;
    public ?int $lastReceived = null;

    public function __construct(
        public readonly Server $server,
        public readonly int $fd,
        public readonly int $reactorId,
    ) {
        /**
         * @var array{connect_time:int,remote_ip:string,remote_port:int}|false
         */
        $info = $server->getClientInfo($fd, $reactorId);
        if (false === $info) {
            throw new Exception();
        }
        $this->connectTime = $info['connect_time'];
        $this->ip = $info['remote_ip'];
        $this->port = $info['remote_port'];
    }

    public function getIP(): string
    {
        return $this->ip;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function send(string $data): void
    {
        $this->server->send($this->fd, $data);
    }

    public function close(bool $force = false): void
    {
        $this->server->close($this->fd, $force);
    }

    public function isTimeoutted(): bool
    {
        return max($this->connectTime, $this->lastReceived) + $this->timeout * 1000 <= time();
    }

    public function receivePacket(string $data): void
    {
        $this->lastReceived = time();
        $this->pendingPacket .= $data;
    }

    public function nextPacketIsReady(): bool
    {
        if (!$this->pendingPacketLength) {
            $integers = @unpack('nlength', $this->pendingPacket);
            if (false === $integers) {
                throw new Exception('Cannot read length of TCP packet');
            }
            $this->pendingPacketLength = $integers['length'];
        }

        return strlen($this->pendingPacket) - 2 >= $this->pendingPacketLength;
    }

    public function getNextPacket(): string
    {
        if (!$this->nextPacketIsReady()) {
            throw new Exception('next packet is not ready yet');
        }
        $readyPacket = substr($this->pendingPacket, 2, $this->pendingPacketLength);
        $this->pendingPacket = substr($this->pendingPacket, $this->pendingPacketLength + 2);
        $this->pendingPacketLength = 0;

        return $readyPacket;
    }
}
