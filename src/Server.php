<?php

namespace dnj\DnsServer;

use Badcow\DNS\Message;
use dnj\DnsServer\Contracts\IClient;
use dnj\DnsServer\Contracts\IResolver;
use Exception;
use Swoole;

class Server extends Swoole\Server
{
    use Concerns\UdpServerTrait;
    use Concerns\TcpServerTrait;

    /**
     * @param int[] $sockTypes
     */
    public function __construct(
        string $host,
        int $port,
        public IResolver $resolver,
        int $mode = SWOOLE_BASE,
        public readonly array $sockTypes = [SWOOLE_SOCK_UDP, SWOOLE_SOCK_TCP]
    ) {
        $countSocketTypes = count($sockTypes);
        if (0 == $countSocketTypes) {
            throw new Exception('Socket types must not be empty');
        }
        parent::__construct($host, $port, $mode, $sockTypes[0]);
        for ($x = 1; $x < $countSocketTypes; ++$x) {
            $this->listen($host, $port, $sockTypes[$x]);
        }
        $this->setupListeners();
    }

    protected function setupListeners(): void
    {
        if (in_array(SWOOLE_SOCK_UDP, $this->sockTypes)) {
            $this->setupUdpListeners();
        }
        if (in_array(SWOOLE_SOCK_TCP, $this->sockTypes)) {
            $this->setupTcpListeners();
        }
    }

    protected function handleQueryFromStream(string $buffer, IClient $client): string
    {
        $query = Message::fromWire($buffer);
        $logicalResolver = new MessageResolver($this->resolver, $query, $client);

        return $logicalResolver->resolve()->toWire();
    }
}
