<?php

namespace Lyre\AiAgents\Services;

use Lyre\AiAgents\Data\ToolDefinition;
use Lyre\AiAgents\Models\Agent;
use Lyre\AiAgents\Models\AgentTool;

class AgentToolResolver
{
    protected const SUPPORTED_BUILTIN_TYPES = [
        'file_search',
        'web_search_preview',
        'web_search_preview_2025_03_11',
        'code_interpreter',
        'image_generation',
        'mcp',
        'custom',
        'computer_use_preview',
        'shell',
        'apply_patch',
    ];

    public function __construct(protected ToolRegistry $toolRegistry) {}

    public function resolveForAgent(Agent $agent): array
    {
        $responseTools = [];
        $executableTools = [];

        $dbTools = AgentTool::query()
            ->where('agent_id', $agent->id)
            ->get();

        foreach ($dbTools as $dbTool) {
            if (isset($dbTool->is_enabled) && !$dbTool->is_enabled) {
                continue;
            }

            $resolved = $this->resolveDbTool($agent, $dbTool);
            if (!$resolved) {
                continue;
            }

            $responseTools[] = $resolved['response_tool'];

            if ($resolved['executable_tool'] instanceof ToolDefinition) {
                $executableTools[$resolved['executable_tool']->name] = $resolved['executable_tool'];
            }
        }

        // Keep backward compatibility with runtime registrations.
        foreach ($this->toolRegistry->all() as $runtimeTool) {
            if (!isset($executableTools[$runtimeTool->name])) {
                $responseTools[] = $runtimeTool->toResponseToolArray();
                $executableTools[$runtimeTool->name] = $runtimeTool;
            }
        }

        $responseTools = $this->appendBuiltinToolsFromAgentConfig($agent, $responseTools);

        return [
            'response_tools' => array_values($this->uniqueTools($responseTools)),
            'executable_tools' => $executableTools,
        ];
    }

    protected function resolveDbTool(Agent $agent, AgentTool $dbTool): ?array
    {
        $type = (string) $dbTool->type;
        $metadata = $dbTool->metadata ?? [];

        if ($type === 'builtin') {
            $responseTool = $this->resolveBuiltinTool($agent, (string) $dbTool->name, $metadata);
            if (!$responseTool) {
                return null;
            }

            return [
                'response_tool' => $responseTool,
                'executable_tool' => null,
            ];
        }

        $runtimeTool = $this->toolRegistry->get((string) $dbTool->name);
        $handler = $runtimeTool?->handler;
        if ($handler === null && $type === 'api' && !empty($dbTool->handler_ref)) {
            $handler = $dbTool->handler_ref;
        }

        $toolDefinition = new ToolDefinition(
            name: (string) $dbTool->name,
            type: $type,
            description: (string) ($dbTool->description ?? ''),
            parametersSchema: is_array($dbTool->parameters_schema) ? $dbTool->parameters_schema : [],
            handler: $handler,
            metadata: is_array($metadata) ? $metadata : [],
        );

        return [
            'response_tool' => $toolDefinition->toResponseToolArray(),
            'executable_tool' => $toolDefinition,
        ];
    }

    protected function resolveBuiltinTool(Agent $agent, string $name, array $metadata = []): ?array
    {
        if ($name === 'file_search') {
            $vectorStoreIds = $this->resolveFileSearchVectorStoreIds($agent, $metadata);
            if (empty($vectorStoreIds)) {
                return null;
            }

            return [
                'type' => 'file_search',
                'vector_store_ids' => $vectorStoreIds,
            ];
        }

        return ['type' => $name];
    }

    protected function resolveFileSearchVectorStoreIds(Agent $agent, array $metadata = []): array
    {
        $fromToolMetadata = $metadata['vector_store_ids'] ?? null;
        if (is_array($fromToolMetadata) && !empty($fromToolMetadata)) {
            return array_values(array_unique(array_filter(array_map('strval', $fromToolMetadata))));
        }

        $agentMetadata = is_array($agent->metadata) ? $agent->metadata : [];
        if (!empty($agentMetadata['vector_store_id'])) {
            return [(string) $agentMetadata['vector_store_id']];
        }

        if (!empty($agentMetadata['vector_store_ids']) && is_array($agentMetadata['vector_store_ids'])) {
            return array_values(array_unique(array_filter(array_map('strval', $agentMetadata['vector_store_ids']))));
        }

        return [];
    }

    protected function appendBuiltinToolsFromAgentConfig(Agent $agent, array $responseTools): array
    {
        $tools = is_array($agent->tools) ? $agent->tools : [];

        foreach ($tools as $tool) {
            if (!is_string($tool) || $tool === '') {
                continue;
            }

            // Agent->tools can include function/api names for dashboard visibility.
            // Only emit valid OpenAI builtin tool types in this fallback path.
            if ($tool === 'file_search') {
                $resolved = $this->resolveBuiltinTool($agent, 'file_search');
                if ($resolved) {
                    $responseTools[] = $resolved;
                }
                continue;
            }

            if (in_array($tool, self::SUPPORTED_BUILTIN_TYPES, true)) {
                $responseTools[] = ['type' => $tool];
            }
        }

        return $responseTools;
    }

    protected function uniqueTools(array $tools): array
    {
        $unique = [];

        foreach ($tools as $tool) {
            $key = json_encode($tool);
            if (!isset($unique[$key])) {
                $unique[$key] = $tool;
            }
        }

        return $unique;
    }
}
