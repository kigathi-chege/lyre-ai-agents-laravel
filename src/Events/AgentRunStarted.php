<?php

namespace Lyre\AiAgents\Events;

class AgentRunStarted
{
    public function __construct(public readonly array $payload) {}
}
