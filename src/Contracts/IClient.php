<?php

namespace dnj\DnsServer\Contracts;

interface IClient
{
    public function getIP(): string;

    public function getPort(): int;

    public function send(string $data): void;
}
