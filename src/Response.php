<?php

namespace dnj\DnsServer;

use Badcow\DNS\Classes;
use Badcow\DNS\Message;
use Badcow\DNS\Question;
use Badcow\DNS\Rdata\CNAME;
use Badcow\DNS\Rdata\MX;
use Badcow\DNS\Rdata\SRV;
use Badcow\DNS\Rdata\Types;
use Badcow\DNS\ResourceRecord;

class Response extends Message
{
    public static function isQuestionAndAnswerMatch(Question $question, ResourceRecord $answer): bool
    {
        return
            $question->getName() == $answer->getName() and
            $question->getClassId() == $answer->getClassId() and
            $question->getType() == $answer->getType()
        ;
    }

    public static function fromQuery(Message $query): self
    {
        $response = new self();
        $response->setId($query->getId());
        $response->setOpcode($query->getOpcode());
        $response->setRcode($query->getRcode());
        $response->setRecursionDesired($query->isRecursionDesired());
        $response->setTruncated($query->isTruncated());
        $response->setQuestions($query->getQuestions());
        $response->setAuthoritatives($query->getAuthoritatives());
        $response->setAdditionals($query->getAdditionals());

        return $response;
    }

    public function __construct()
    {
        $this->setResponse(true);
    }

    public function append(self $other): void
    {
        foreach ($other->getAnswers() as $record) {
            $this->addAnswer($record);
        }
        foreach ($other->getAdditionals() as $record) {
            $this->addAdditional($record);
        }
    }

    public function appendToAdditionals(self $other): void
    {
        foreach ($other->getAnswers() as $record) {
            $this->addAdditional($record);
        }
        foreach ($other->getAdditionals() as $record) {
            $this->addAdditional($record);
        }
    }

    /**
     * @return Question[]
     */
    public function getAdditionalQuestions(): array
    {
        $questions = [];
        foreach ($this->getAnswers() as $answer) {
            $questionsForAnswer = $this->getAdditionalQuestionsFor($answer);
            foreach ($questionsForAnswer as $q) {
                if (!in_array($q, $questions, false)) {
                    $questions[] = $q;
                }
            }
        }
        $questions = array_filter($questions, fn ($question) => null === $this->hasAditionalForQuestion($question) and
            null === $this->hasAnswerForQuestion($question)
        );

        return $questions;
    }

    public function hasAnswerForQuestion(Question $question): ?ResourceRecord
    {
        foreach ($this->getAnswers() as $record) {
            if (self::isQuestionAndAnswerMatch($question, $record)) {
                return $record;
            }
        }

        return null;
    }

    public function hasAditionalForQuestion(Question $question): ?ResourceRecord
    {
        foreach ($this->getAdditionals() as $record) {
            if (self::isQuestionAndAnswerMatch($question, $record)) {
                return $record;
            }
        }

        return null;
    }

    /**
     * @return Question[]
     */
    protected function getAdditionalQuestionsFor(ResourceRecord $answer): array
    {
        $rData = $answer->getRdata();
        if (null === $rData) {
            return [];
        }
        if ($rData instanceof CNAME) {
            return $this->getAdditionalQuestionsForCName($rData);
        } elseif ($rData instanceof MX) {
            return $this->getAdditionalQuestionsForMX($rData);
        } elseif ($rData instanceof SRV) {
            return $this->getAdditionalQuestionsForSRV($rData);
        }

        return [];
    }

    /**
     * @return Question[]
     */
    protected function getAdditionalQuestionsForCName(CNAME $cname): array
    {
        $domain = $cname->getTarget();

        return null !== $domain ? $this->getIPQuestionsForDomain($domain) : [];
    }

    /**
     * @return Question[]
     */
    protected function getAdditionalQuestionsForMX(MX $mx): array
    {
        $domain = $mx->getExchange();

        return null !== $domain ? $this->getIPQuestionsForDomain($domain) : [];
    }

    /**
     * @return Question[]
     */
    protected function getAdditionalQuestionsForSRV(SRV $srv): array
    {
        $domain = $srv->getTarget();

        return $this->getIPQuestionsForDomain($domain);
    }

    /**
     * @return Question[]
     */
    protected function getIPQuestionsForDomain(string $domain): array
    {
        $ipv4 = new Question();
        $ipv4->setName($domain);
        $ipv4->setTypeCode(Types::A);
        $ipv4->setClass(Classes::INTERNET);

        $ipv6 = clone $ipv4;
        $ipv6->setTypeCode(Types::AAAA);

        return [$ipv4, $ipv6];
    }
}
