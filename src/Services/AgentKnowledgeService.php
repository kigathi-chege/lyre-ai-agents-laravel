<?php

namespace Lyre\AiAgents\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Lyre\AiAgents\Models\Agent;
use Lyre\AiAgents\Models\AgentTool;

class AgentKnowledgeService
{
    public function __construct(protected OpenAIClient $openAIClient) {}

    public function syncAssistantVectorStore(Agent $agent, string $assistantId, array $clientConfig = []): ?string
    {
        $assistant = $this->openAIClient->retrieveAssistant($assistantId, $clientConfig);
        if (!$assistant) {
            return null;
        }

        $vectorStoreId = $this->extractAssistantVectorStoreId($assistant);
        if (!$vectorStoreId) {
            return null;
        }

        $this->setAgentVectorStore($agent, $vectorStoreId, [
            'vector_store_synced_at' => now()->toISOString(),
            'vector_store_source' => 'openai_assistant',
            'legacy_assistant_id' => $assistantId,
        ]);

        return $vectorStoreId;
    }

    public function ensureVectorStoreForAgent(Agent $agent, ?string $name = null, array $clientConfig = []): string
    {
        $vectorStoreId = (string) Arr::get($agent->metadata ?? [], 'vector_store_id', '');
        if ($vectorStoreId !== '') {
            return $vectorStoreId;
        }

        $created = $this->openAIClient->createVectorStore([
            'name' => $name ?: "{$agent->name} knowledge base",
        ], $clientConfig);

        $vectorStoreId = (string) ($created['id'] ?? '');
        if ($vectorStoreId === '') {
            throw new RuntimeException('OpenAI did not return vector store id.');
        }

        $this->setAgentVectorStore($agent, $vectorStoreId, [
            'vector_store_created_at' => now()->toISOString(),
        ]);

        return $vectorStoreId;
    }

    public function uploadLocalFileToAgentVectorStore(
        Agent $agent,
        string $absolutePath,
        ?string $originalFilename = null,
        array $clientConfig = []
    ): array {
        $vectorStoreId = $this->ensureVectorStoreForAgent($agent, null, $clientConfig);

        $uploaded = $this->openAIClient->uploadFile($absolutePath, $originalFilename, 'assistants', $clientConfig);
        $fileId = (string) ($uploaded['id'] ?? '');
        if ($fileId === '') {
            throw new RuntimeException('OpenAI file upload did not return a file id.');
        }

        $attached = $this->openAIClient->attachFileToVectorStore($vectorStoreId, $fileId, [], $clientConfig);
        $this->ensureFileSearchTool($agent, $vectorStoreId);

        return [
            'vector_store_id' => $vectorStoreId,
            'file_id' => $fileId,
            'vector_store_file' => $attached,
        ];
    }

    public function attachExistingOpenAiFileToAgentVectorStore(Agent $agent, string $fileId, array $clientConfig = []): array
    {
        $vectorStoreId = $this->ensureVectorStoreForAgent($agent, null, $clientConfig);

        try {
            $attached = $this->openAIClient->attachFileToVectorStore($vectorStoreId, $fileId, [], $clientConfig);
        } catch (RuntimeException $e) {
            $message = strtolower($e->getMessage());
            if (!str_contains($message, 'already')) {
                throw $e;
            }

            $attached = ['id' => $fileId, 'vector_store_id' => $vectorStoreId, 'status' => 'exists'];
        }

        $this->ensureFileSearchTool($agent, $vectorStoreId);

        return [
            'vector_store_id' => $vectorStoreId,
            'file_id' => $fileId,
            'vector_store_file' => $attached,
        ];
    }

    public function setAgentVectorStore(Agent $agent, string $vectorStoreId, array $extraMetadata = []): Agent
    {
        $metadata = is_array($agent->metadata) ? $agent->metadata : [];
        $metadata = array_merge($metadata, $extraMetadata, [
            'vector_store_id' => $vectorStoreId,
            'vector_store_ids' => [$vectorStoreId],
        ]);

        $agent->metadata = $metadata;
        $agent->save();

        $this->ensureFileSearchTool($agent, $vectorStoreId);

        return $agent->refresh();
    }

    public function ensureFileSearchTool(Agent $agent, ?string $vectorStoreId = null, array $toolMetadata = []): AgentTool
    {
        $vectorStoreId = $vectorStoreId ?: (string) Arr::get($agent->metadata ?? [], 'vector_store_id', '');
        if ($vectorStoreId === '') {
            throw new RuntimeException("Cannot enable file_search for agent {$agent->id} without vector store id.");
        }

        $payload = [
            'type' => 'builtin',
            'description' => 'Search files in this agent vector store.',
            'parameters_schema' => [],
            'handler_type' => null,
            'handler_ref' => null,
            'metadata' => array_merge($toolMetadata, [
                'vector_store_ids' => [$vectorStoreId],
                'managed_by' => 'agent_knowledge_service',
            ]),
        ];

        if (Schema::hasColumn((new AgentTool())->getTable(), 'is_enabled')) {
            $payload['is_enabled'] = true;
        }

        $tool = AgentTool::query()->updateOrCreate(
            ['agent_id' => $agent->id, 'name' => 'file_search'],
            $payload
        );

        $this->ensureToolListedOnAgent($agent, 'file_search');

        return $tool;
    }

    public function ensureLeadCollectionTool(Agent $agent, string $endpoint): AgentTool
    {
        $toolName = (string) config('ai-agents.tools.lead.tool_name', 'submit_lead');
        $schema = [
            'type' => 'object',
            'properties' => [
                'first_name' => ['type' => 'string'],
                'last_name' => ['type' => 'string'],
                'email' => ['type' => 'string'],
                'phone_number' => ['type' => 'string'],
                'business_name' => ['type' => 'string'],
                'industry' => ['type' => 'string'],
                'chat_summary' => ['type' => 'string'],
                'source' => ['type' => 'string'],
            ],
            'required' => ['first_name', 'phone_number', 'source'],
        ];

        $payload = [
            'type' => 'api',
            'description' => (string) config(
                'ai-agents.tools.lead.description',
                'Capture lead contact details and forward them to the configured endpoint.'
            ),
            'parameters_schema' => $schema,
            'handler_type' => 'endpoint',
            'handler_ref' => $endpoint,
            'metadata' => [
                'managed_by' => 'lyre.ai_agents',
                'lyre_builtin_tool' => 'lead',
            ],
        ];

        if (Schema::hasColumn((new AgentTool())->getTable(), 'is_enabled')) {
            $payload['is_enabled'] = true;
        }

        $this->renameLegacyTool($agent, ['submit_lead_to_axis'], $toolName);

        $tool = AgentTool::query()->updateOrCreate(
            ['agent_id' => $agent->id, 'name' => $toolName],
            $payload
        );

        $this->ensureToolListedOnAgent($agent, $toolName);

        return $tool;
    }

    public function ensureLeadToolForAllAgents(string $endpoint): int
    {
        $count = 0;

        Agent::query()->chunkById(200, function ($agents) use ($endpoint, &$count) {
            foreach ($agents as $agent) {
                $this->ensureLeadCollectionTool($agent, $endpoint);
                $count++;
            }
        });

        return $count;
    }

    /**
     * Register the generic "request human handover" tool on an agent.
     *
     * The tool POSTs to a host-configured webhook (`config('ai-agents.tools.handover.webhook_url')`)
     * with `{ reason, urgency, topic? }` plus Lyre's standard run/conversation context.
     * The host endpoint is responsible for flipping conversation state to a human.
     */
    public function ensureHandoverTool(Agent $agent, string $endpoint): AgentTool
    {
        $toolName = (string) config('ai-agents.tools.handover.tool_name', 'request_human_handover');
        $schema = [
            'type' => 'object',
            'properties' => [
                'reason' => [
                    'type' => 'string',
                    'description' => 'Short explanation of why a human is needed.',
                ],
                'urgency' => [
                    'type' => 'string',
                    'enum' => ['low', 'medium', 'high'],
                    'description' => 'Severity / time-sensitivity of the request.',
                ],
                'topic' => [
                    'type' => 'string',
                    'description' => 'Optional topic summary to help the human pick up context.',
                ],
            ],
            'required' => ['reason', 'urgency'],
        ];

        $payload = [
            'type' => 'api',
            'description' => (string) config(
                'ai-agents.tools.handover.description',
                'Request a human takeover of the current conversation. Call this when the user needs an action you cannot perform, when they explicitly ask for a person, or when the conversation falls outside your scope.'
            ),
            'parameters_schema' => $schema,
            'handler_type' => 'endpoint',
            'handler_ref' => $endpoint,
            'metadata' => [
                'managed_by' => 'lyre.ai_agents',
                'lyre_builtin_tool' => 'handover',
            ],
        ];

        if (Schema::hasColumn((new AgentTool())->getTable(), 'is_enabled')) {
            $payload['is_enabled'] = true;
        }

        $tool = AgentTool::query()->updateOrCreate(
            ['agent_id' => $agent->id, 'name' => $toolName],
            $payload
        );

        $this->ensureToolListedOnAgent($agent, $toolName);

        return $tool;
    }

    public function ensureHandoverToolForAllAgents(string $endpoint): int
    {
        $count = 0;

        Agent::query()->chunkById(200, function ($agents) use ($endpoint, &$count) {
            foreach ($agents as $agent) {
                $this->ensureHandoverTool($agent, $endpoint);
                $count++;
            }
        });

        return $count;
    }

    /**
     * Idempotently rename any legacy tool rows (and their entries on `agent.tools`)
     * to the canonical name. Safe to call repeatedly.
     */
    protected function renameLegacyTool(Agent $agent, array $legacyNames, string $canonicalName): void
    {
        if (empty($legacyNames)) {
            return;
        }

        $existingCanonical = AgentTool::query()
            ->where('agent_id', $agent->id)
            ->where('name', $canonicalName)
            ->exists();

        if ($existingCanonical) {
            AgentTool::query()
                ->where('agent_id', $agent->id)
                ->whereIn('name', $legacyNames)
                ->delete();
        } else {
            AgentTool::query()
                ->where('agent_id', $agent->id)
                ->whereIn('name', $legacyNames)
                ->update(['name' => $canonicalName]);
        }

        $tools = is_array($agent->tools) ? $agent->tools : [];
        if (empty($tools)) {
            return;
        }

        $changed = false;
        foreach ($tools as $i => $name) {
            if (in_array($name, $legacyNames, true)) {
                $tools[$i] = $canonicalName;
                $changed = true;
            }
        }

        if ($changed) {
            $tools = array_values(array_unique($tools));
            $agent->tools = $tools;
            $agent->save();
        }
    }

    protected function ensureToolListedOnAgent(Agent $agent, string $toolName): void
    {
        $tools = is_array($agent->tools) ? $agent->tools : [];
        if (!in_array($toolName, $tools, true)) {
            $tools[] = $toolName;
            $agent->tools = array_values($tools);
            $agent->save();
        }
    }

    protected function extractAssistantVectorStoreId(array $assistant): ?string
    {
        $toolResourceVectorStoreId = Arr::get($assistant, 'tool_resources.file_search.vector_store_ids.0');
        if (is_string($toolResourceVectorStoreId) && $toolResourceVectorStoreId !== '') {
            return $toolResourceVectorStoreId;
        }

        $toolResources = Arr::get($assistant, 'tool_resources.file_search.vector_stores', []);
        if (is_array($toolResources) && isset($toolResources[0]['id']) && is_string($toolResources[0]['id'])) {
            return $toolResources[0]['id'];
        }

        return null;
    }
}
