<?php

namespace Lyre\AiAgents\Events;

class UserMessageAdded
{
    public function __construct(public readonly array $payload) {}
}
