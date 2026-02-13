<?php

namespace Lyre\AiAgents\Events;

class AgentConversationCreated
{
    public function __construct(public readonly array $payload) {}
}
