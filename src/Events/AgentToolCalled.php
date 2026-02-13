<?php

namespace Lyre\AiAgents\Events;

class AgentToolCalled
{
    public function __construct(public readonly array $payload) {}
}
