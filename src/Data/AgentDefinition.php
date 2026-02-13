<?php

namespace Lyre\AiAgents\Data;

class AgentDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly string $model,
        public readonly ?string $instructions = null,
        public readonly ?float $temperature = null,
        public readonly ?int $maxOutputTokens = null,
        public readonly array $tools = [],
        public readonly array $metadata = [],
        public readonly ?int $id = null,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'model' => $this->model,
            'instructions' => $this->instructions,
            'temperature' => $this->temperature,
            'max_output_tokens' => $this->maxOutputTokens,
            'tools' => $this->tools,
            'metadata' => $this->metadata,
        ];
    }
}
