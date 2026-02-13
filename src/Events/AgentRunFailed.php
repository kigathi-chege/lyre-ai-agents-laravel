<?php

namespace Lyre\AiAgents\Events;

class AgentRunFailed
{
    public function __construct(public readonly array $payload) {}
}
