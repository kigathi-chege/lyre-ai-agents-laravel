<?php

namespace Lyre\AiAgents\Data;

class ToolDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $description,
        public readonly array $parametersSchema = [],
        public readonly mixed $handler = null,
        public readonly array $metadata = [],
    ) {}

    public function toResponseToolArray(): array
    {
        if ($this->type === 'builtin') {
            return ['type' => $this->name];
        }

        return [
            'type' => 'function',
            'name' => $this->name,
            'description' => $this->description,
            'parameters' => (object) $this->parametersSchema,
        ];
    }
}
