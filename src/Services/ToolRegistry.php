<?php

namespace Lyre\AiAgents\Services;

use Lyre\AiAgents\Data\ToolDefinition;

class ToolRegistry
{
    /** @var array<string, ToolDefinition> */
    protected array $tools = [];

    public function register(ToolDefinition $tool): void
    {
        $this->tools[$tool->name] = $tool;
    }

    public function get(string $name): ?ToolDefinition
    {
        return $this->tools[$name] ?? null;
    }

    public function all(): array
    {
        return $this->tools;
    }

    public function allForResponse(): array
    {
        return array_values(array_map(
            fn (ToolDefinition $tool) => $tool->toResponseToolArray(),
            $this->tools
        ));
    }
}
