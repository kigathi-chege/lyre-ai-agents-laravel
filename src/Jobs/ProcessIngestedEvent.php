<?php

namespace Lyre\AiAgents\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Lyre\AiAgents\Models\Event;
use Lyre\AiAgents\Services\InboundEventProcessor;

class ProcessIngestedEvent implements ShouldQueue
{
    public int $tries = 5;

    public function __construct(public readonly int $eventId) {}

    public function handle(InboundEventProcessor $processor): void
    {
        $event = Event::query()->find($this->eventId);
        if (!$event) {
            return;
        }

        $processor->process($event);
    }
}
