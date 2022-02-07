<?php

namespace dnj\DnsServer\Concerns;

use dnj\DnsServer\UdpClient;
use Exception;
use Swoole;

trait UdpServerTrait
{
    protected function setupUdpListeners(): void
    {
        $this->on('Packet', [$this, 'onUdpPacket']);
    }

    /**
     * @param array{address:string,port:int} $clientInfo
     */
    public function onUdpPacket(Swoole\Server $server, string $data, array $clientInfo): void
    {
        $client = new UdpClient($server, $clientInfo['address'], $clientInfo['port']);
        try {
            $response = $this->handleQueryFromStream($data, $client);
            $client->send($response);
        } catch (Exception $e) {
        }
    }
}
