<?php

namespace Lyre\AiAgents\Models;

trait ResolvesTableName
{
    protected function resolveTableName(string $key, string $fallbackBase): string
    {
        $configured = config("ai-agents.tables.{$key}");
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $prefix = config('ai-agents.tables.prefix');
        if (!is_string($prefix) || $prefix === '') {
            $rawPrefix = env('AI_AGENTS_TABLE_PREFIX', 'ai_agents');
            $prefix = $rawPrefix === '' ? '' : (str_ends_with($rawPrefix, '_') ? $rawPrefix : $rawPrefix.'_');
        }

        return $prefix.$fallbackBase;
    }
}

