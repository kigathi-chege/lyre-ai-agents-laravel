<?php

namespace Lyre\AiAgents\Facades;

use Illuminate\Support\Facades\Facade;

class Agents extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Lyre\AiAgents\Services\AgentManager::class;
    }
}
