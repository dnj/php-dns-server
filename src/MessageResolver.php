<?php

namespace dnj\DnsServer;

use Badcow\DNS\Message;
use Badcow\DNS\Question;
use Badcow\DNS\Rdata\CNAME;
use Badcow\DNS\Rdata\Types;
use dnj\DnsServer\Contracts\IClient;
use dnj\DnsServer\Contracts\IResolver;
use Exception;

class MessageResolver
{
    protected Response $response;

    public function __construct(
        public readonly IResolver $resolver,
        public readonly Message $query,
        public readonly IClient $client,
    ) {
        $this->response = Response::fromQuery($query);
    }

    public function resolve(): Response
    {
        foreach ($this->query->getQuestions() as $question) {
            // Response of question and global response will merge in the method.
            $this->resolveQuestion($question);
        }

        $this->handleAdditionals();

        return $this->response;
    }

    protected function handleAdditionals(): void
    {
        foreach ($this->response->getAdditionalQuestions() as $question) {
            $questionResponse = $this->resolver->getResponse($question, $this);
            if (null !== $questionResponse) {
                $this->response->appendToAdditionals($questionResponse);
            }
        }
    }

    protected function resolveQuestion(Question $question): Response
    {
        $response = $this->resolver->getResponse($question, $this);
        if (null === $response) {
            $response = new Response();
        }
        if ($response->countAnswers()) {
            $this->response->append($response);

            return $response;
        }
        if (Types::CNAME != $question->getTypeCode()) {
            $cnameQuestion = clone $question;
            $cnameQuestion->setTypeCode(Types::CNAME);
            $cnameResponse = $this->resolveQuestion($cnameQuestion);

            foreach ($cnameResponse->getAnswers() as $answer) {
                /**
                 * @var CNAME|null
                 */
                $rdata = $answer->getRdata();
                $newName = $rdata?->getTarget();
                if (null === $newName) {
                    throw new Exception('target of CNAME must be not empty');
                }
                $questionInNewName = clone $question;
                $questionInNewName->setName($newName);
                $this->resolveQuestion($questionInNewName);
            }
        }

        return $response;
    }
}
