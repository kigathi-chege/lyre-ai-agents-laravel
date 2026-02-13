<?php

namespace Lyre\AiAgents\Events;

class UsageRecorded
{
    public function __construct(public readonly array $payload) {}
}
