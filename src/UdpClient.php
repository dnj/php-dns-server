<?php

namespace dnj\DnsServer;

use dnj\DnsServer\Contracts\IClient;
use Swoole\Server;

class UdpClient implements IClient
{
    public function __construct(public readonly Server $server, public readonly string $ip, public readonly int $port)
    {
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
        $this->server->sendTo($this->ip, $this->port, $data);
    }
}
