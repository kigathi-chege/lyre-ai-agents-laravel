<?php

namespace Lyre\AiAgents\Events;

class ConversationUpdated
{
    public function __construct(public readonly array $payload) {}
}
