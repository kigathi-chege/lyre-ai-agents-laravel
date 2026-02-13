<?php

namespace Lyre\AiAgents\Events;

class AgentMessageAdded
{
    public function __construct(public readonly array $payload) {}
}
