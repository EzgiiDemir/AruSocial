<?php

namespace Tests\Fakes;

use Sentry\Event;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

// Captures events in memory instead of sending them anywhere — no real
// Sentry network call is ever made from PHPUnit (P3-6 §21).
class FakeSentryTransport implements TransportInterface
{
    /** @var Event[] */
    public array $events = [];

    public function send(Event $event): Result
    {
        $this->events[] = $event;

        return new Result(ResultStatus::success(), $event);
    }

    public function close(?int $timeout = null): Result
    {
        return new Result(ResultStatus::success());
    }
}
