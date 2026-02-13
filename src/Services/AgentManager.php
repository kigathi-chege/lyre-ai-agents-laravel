<?php

namespace Lyre\AiAgents\Services;

use Lyre\AiAgents\Data\AgentDefinition;
use Lyre\AiAgents\Data\ToolDefinition;
use Lyre\AiAgents\Models\Agent;
use Lyre\AiAgents\Models\AgentTool;

class AgentManager
{
    public function __construct(
        protected AgentRunner $runner,
        protected ToolRegistry $toolRegistry,
        protected PromptTemplateResolver $promptTemplateResolver,
    ) {}

    public function registerAgent(array|AgentDefinition $definition): Agent
    {
        if ($definition instanceof AgentDefinition) {
            $definition = $definition->toArray();
        }

        $instructions = array_key_exists('instructions', $definition)
            ? $definition['instructions']
            : null;

        $promptTemplateId = $definition['prompt_template_id'] ?? null;
        if (!$promptTemplateId && empty($instructions)) {
            $promptTemplateId = $this->promptTemplateResolver->defaultTemplateId();
        }

        $values = [
            'model' => $definition['model'] ?? config('ai-agents.default_model'),
            'instructions' => $instructions,
            'prompt_template_id' => $promptTemplateId,
            'temperature' => $definition['temperature'] ?? null,
            'max_output_tokens' => $definition['max_output_tokens'] ?? null,
            'tools' => $definition['tools'] ?? [],
            'metadata' => $definition['metadata'] ?? [],
            'is_active' => true,
        ];

        if (array_key_exists('openai_api_key', $definition)) {
            $values['openai_api_key'] = $definition['openai_api_key'];
        }

        return Agent::query()->updateOrCreate(
            ['name' => $definition['name']],
            $values
        );
    }

    public function registerTool(array|ToolDefinition $definition): void
    {
        $raw = $definition;
        if (is_array($definition)) {
            $definition = new ToolDefinition(
                name: $definition['name'],
                type: $definition['type'] ?? 'function',
                description: $definition['description'] ?? '',
                parametersSchema: $definition['parameters_schema'] ?? [],
                handler: $definition['handler'] ?? null,
                metadata: $definition['metadata'] ?? [],
            );
        }

        $this->toolRegistry->register($definition);

        if (is_array($raw) && !empty($raw['agent_id'])) {
            AgentTool::query()->updateOrCreate(
                ['agent_id' => $raw['agent_id'], 'name' => $definition->name],
                [
                    'type' => $definition->type,
                    'description' => $definition->description,
                    'parameters_schema' => $definition->parametersSchema,
                    'handler_type' => is_string($definition->handler) ? 'endpoint' : 'callable',
                    'handler_ref' => is_string($definition->handler) ? $definition->handler : null,
                    'metadata' => $definition->metadata,
                ]
            );
        }
    }

    public function run(int|string $agent, string $message, array $context = []): array
    {
        $agentModel = is_numeric($agent)
            ? Agent::query()->findOrFail($agent)
            : Agent::query()->where('name', $agent)->firstOrFail();

        return $this->runner->run($agentModel, $message, $context);
    }

    public function stream(int|string $agent, string $message, array $context = []): \Generator
    {
        $agentModel = is_numeric($agent)
            ? Agent::query()->findOrFail($agent)
            : Agent::query()->where('name', $agent)->firstOrFail();

        return $this->runner->stream($agentModel, $message, $context);
    }
}
