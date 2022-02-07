<?php

namespace dnj\DnsServer\Resolvers;

use Badcow\DNS\Classes;
use Badcow\DNS\Question;
use Badcow\DNS\Rdata\A;
use Badcow\DNS\Rdata\AAAA;
use Badcow\DNS\Rdata\CNAME;
use Badcow\DNS\Rdata\NS;
use Badcow\DNS\Rdata\RdataInterface;
use Badcow\DNS\Rdata\SOA;
use Badcow\DNS\Rdata\SPF;
use Badcow\DNS\Rdata\TXT;
use Badcow\DNS\Rdata\Types;
use Badcow\DNS\Rdata\UnsupportedTypeException;
use Badcow\DNS\ResourceRecord;
use Badcow\DNS\Validator;
use dnj\DnsServer\Contracts\IResolver;
use dnj\DnsServer\MessageResolver;
use dnj\DnsServer\Response;
use dnj\Filesystem\Contracts\IFile;
use dnj\Filesystem\Exceptions\NotFoundException;
use Exception;

/**
 * @phpstan-type GeneralRecord array<mixed,mixed>
 */
class JsonResolver implements IResolver
{
    /**
     * @var ResourceRecord[]|null
     */
    protected ?array $records = null;
    protected ?int $defaultTTL = null;

    public function __construct(public IFile $file)
    {
    }

    public function reload(): void
    {
        if (!$this->file->exists()) {
            throw new NotFoundException($this->file);
        }
        $data = json_decode($this->file->read(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new Exception('json data is not an array');
        }
        if (!isset($data['records'])) {
            throw new Exception("there is no 'records' index in data");
        }
        if (!is_array($data['records']) or !array_is_list($data['records'])) {
            throw new Exception("the 'records' index is not array");
        }
        if (!isset($data['ttl'])) {
            throw new Exception("there is no 'ttl' index in data");
        }
        if (!is_int($data['ttl'])) {
            throw new Exception("the 'ttl' index is not int");
        }
        $this->defaultTTL = $data['ttl'];
        $this->records = [];
        foreach ($data['records'] as $x => $record) {
            $this->records[] = $this->readRecord($record, "records[{$x}]");
        }
    }

    public function getResponse(Question $question, MessageResolver $ctx): ?Response
    {
        if (null === $this->records) {
            $this->reload();
        }
        if (null === $this->records) {
            throw new Exception();
        }
        $cnames = null;
        $answers = [];
        foreach ($this->records as $answer) {
            if (Response::isQuestionAndAnswerMatch($question, $answer)) {
                $answers[] = $answer;
            }
        }
        if (empty($answers)) {
            return null;
        }
        $response = new Response();
        $response->setAnswers($answers);

        return $response;
    }

    /**
     * @param mixed $record
     */
    protected function readRecord($record, string $index): ResourceRecord
    {
        if (!is_array($record)) {
            throw new Exception("the '{$index}' index is not array");
        }
        if (!isset($record['name'])) {
            throw new Exception("there is not '{$index}[name]' index");
        }
        if (!is_string($record['name'])) {
            throw new Exception("the '{$index}[name]' index is not string");
        }
        if (!Validator::fullyQualifiedDomainName($record['name'])) {
            throw new Exception("the '{$index}[name]' index is not fully qualified domain name");
        }
        if (!isset($record['type'])) {
            throw new Exception("there is not '{$index}[type]' index");
        }
        if (!is_string($record['type'])) {
            throw new Exception("the '{$index}[type]' index is not string");
        }
        if (!Types::isValid($record['type'])) {
            throw new Exception("the '{$index}[type]' index is not valid type");
        }

        if (isset($record['ttl'])) {
            if (!is_int($record['ttl'])) {
                throw new Exception("the '{$index}[ttl]' index is not int");
            }
        }
        if (isset($record['class'])) {
            if (!is_string($record['class'])) {
                throw new Exception("the '{$index}[class]' index is not string");
            }
            if (!Classes::isValid($record['class'])) {
                throw new Exception("the '{$index}[class]' index is not valid class");
            }
        } else {
            $record['class'] = 'IN';
        }
        $rr = new ResourceRecord();
        $rr->setClass($record['class']);
        $rr->setName($record['name']);
        if (isset($record['ttl'])) {
            $rr->setTtl($record['ttl']);
        } else {
            $rr->setTtl($this->defaultTTL);
        }
        $methodName = 'read'.$record['type'].'Data';
        if (!method_exists($this, $methodName)) {
            throw new UnsupportedTypeException($record['type']);
        }
        /**
         * @var callable(array,string)
         */
        $callable = [$this, $methodName];
        $data = call_user_func($callable, $record, $index);
        if (!$data instanceof RdataInterface) {
            throw new Exception("{$methodName} must return an instance of ".RdataInterface::class);
        }
        $rr->setRdata($data);

        return $rr;
    }

    /**
     * @param GeneralRecord $record
     */
    protected function readAData(array $record, string $index): A
    {
        if (!isset($record['address'])) {
            throw new Exception("there is not '{$index}[address]' index");
        }
        if (!is_string($record['address'])) {
            throw new Exception("the '{$index}[address]' index is not string");
        }
        if (!Validator::ipv4($record['address'])) {
            throw new Exception("the '{$index}[address]' index is valid ipv4");
        }
        $data = new A();
        $data->setAddress($record['address']);

        return $data;
    }

    /**
     * @param GeneralRecord $record
     */
    protected function readAAAAData(array $record, string $index): AAAA
    {
        if (!isset($record['address'])) {
            throw new Exception("there is not '{$index}[address]' index");
        }
        if (!is_string($record['address'])) {
            throw new Exception("the '{$index}[address]' index is not string");
        }
        if (!Validator::ipv6($record['address'])) {
            throw new Exception("the '{$index}[address]' index is valid ipv4");
        }
        $data = new AAAA();
        $data->setAddress($record['address']);

        return $data;
    }

    /**
     * @param GeneralRecord $record
     */
    protected function readCNAMEData(array $record, string $index): CNAME
    {
        if (!isset($record['target'])) {
            throw new Exception("there is not '{$index}[target]' index");
        }
        if (!is_string($record['target'])) {
            throw new Exception("the '{$index}[target]' index is not string");
        }
        if (!Validator::fullyQualifiedDomainName($record['target'])) {
            throw new Exception("the '{$index}[target]' index is not fully qualified domain name");
        }
        $data = new CNAME();
        $data->setTarget($record['target']);

        return $data;
    }

    /**
     * @param GeneralRecord $record
     */
    protected function readNSData(array $record, string $index): NS
    {
        if (!isset($record['target'])) {
            throw new Exception("there is not '{$index}[target]' index");
        }
        if (!is_string($record['target'])) {
            throw new Exception("the '{$index}[target]' index is not string");
        }
        if (!Validator::fullyQualifiedDomainName($record['target'])) {
            throw new Exception("the '{$index}[target]' index is not fully qualified domain name");
        }
        $data = new NS();
        $data->setTarget($record['target']);

        return $data;
    }

    /**
     * @param GeneralRecord $record
     */
    protected function readSOAData(array $record, string $index): SOA
    {
        foreach (['primary-ns', 'mailbox'] as $key) {
            if (!isset($record[$key])) {
                throw new Exception("there is not '{$index}[{$key}]' index");
            }
            if (!is_string($record[$key])) {
                throw new Exception("the '{$index}[{$key}]' index is not string");
            }
            if (!Validator::fullyQualifiedDomainName($record[$key])) {
                throw new Exception("the '{$index}[{$key}]' index is not fully qualified domain name");
            }
        }
        foreach (['serial', 'refresh', 'retry', 'expire', 'minimum'] as $key) {
            if (!isset($record[$key])) {
                throw new Exception("there is not '{$index}[{$key}]' index");
            }
            if (!is_int($record[$key])) {
                throw new Exception("the '{$index}[{$key}]' index is not int");
            }
            if (!Validator::isUnsignedInteger($record[$key], 32)) {
                throw new Exception("the '{$index}[{$key}]' index is not uint32");
            }
        }
        /**
         * @var array{primary-ns:string,mailbox:string,serial:int,refresh:int,retry:int,expire:int,minimum:int} $record
         */
        $data = new SOA();
        $data->setMname($record['primary-ns']);
        $data->setRname($record['mailbox']);
        $data->setSerial($record['serial']);
        $data->setRefresh($record['refresh']);
        $data->setRetry($record['retry']);
        $data->setExpire($record['expire']);
        $data->setMinimum($record['minimum']);

        return $data;
    }

    /**
     * @param GeneralRecord $record
     */
    protected function readTXTData(array $record, string $index): TXT
    {
        if (!isset($record['text'])) {
            throw new Exception("there is not '{$index}[text]' index");
        }
        if (!is_string($record['text'])) {
            throw new Exception("the '{$index}[text]' index is not string");
        }
        $data = new TXT();
        $data->setText($record['text']);

        return $data;
    }

    /**
     * @param GeneralRecord $record
     */
    protected function readSPFData(array $record, string $index): SPF
    {
        if (!isset($record['text'])) {
            throw new Exception("there is not '{$index}[text]' index");
        }
        if (!is_string($record['text'])) {
            throw new Exception("the '{$index}[text]' index is not string");
        }
        $data = new SPF();
        $data->setText($record['text']);

        return $data;
    }
}
