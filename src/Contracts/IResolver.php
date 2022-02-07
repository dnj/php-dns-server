<?php

namespace dnj\DnsServer\Contracts;

use Badcow\DNS\Question;
use dnj\DnsServer\MessageResolver;
use dnj\DnsServer\Response;

interface IResolver
{
    public function getResponse(Question $question, MessageResolver $ctx): ?Response;
}
