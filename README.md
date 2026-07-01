# lyre/ai-agents (Laravel)

Forward-only Agents/Bots package using OpenAI Responses API with first-class Laravel orchestration.

## Install

```bash
composer require lyre/ai-agents
php artisan vendor:publish --tag=ai-agents-config
php artisan vendor:publish --tag=ai-agents-migrations
php artisan migrate
```

## Quick start

```php
use Lyre\AiAgents\Facades\Agents;

Agents::registerTool([
    'name' => 'lookup_customer',
    'type' => 'function',
    'description' => 'Lookup customer by phone',
    'parameters_schema' => [
        'type' => 'object',
        'properties' => [
            'phone' => ['type' => 'string'],
        ],
        'required' => ['phone'],
    ],
    'handler' => fn (array $args) => ['customer' => ['phone' => $args['phone'], 'tier' => 'gold']],
]);

$agent = Agents::registerAgent([
    'name' => 'support-bot',
    'model' => 'gpt-4.1-mini',
    'instructions' => 'You are a support specialist.',
    'temperature' => 0.2,
    'max_output_tokens' => 800,
]);

$result = Agents::run($agent->id, 'Find customer with phone +254700111222', [
    'user_id' => auth()->id(),
    'ip' => request()->ip(),
]);
```

## Streaming

```php
$stream = Agents::stream('support-bot', 'Explain invoice #INV-22');

foreach ($stream as $chunk) {
    echo $chunk;
}
```

## Prompt templates

Templates live in `ai_agents_prompt_templates` and are linked to an agent via `agents.prompt_template_id`. The resolver renders template content with `{{variable}}` substitution and supports inheritance.

### Inheritance

Set `extends_template_id` on a template to compose it on top of a parent. The resolver walks root → leaf and concatenates content with the configured separator (default `\n\n`). Inheritance is depth-capped (`prompts.max_inheritance_depth`, default 3) and cycle-safe — on detection the resolver logs a warning and falls back to the leaf alone.

### Variables

Any `{{token}}` in a template is substituted at resolve time. Variables are merged from these sources, lowest-to-highest priority:

1. Each template's `variables` JSON column (parent first, child overrides).
2. System defaults: `assistant_name`, `agent_id`, `model`.
3. `agent.metadata.template_variables`.
4. Caller-supplied map passed to `resolveInstructionsForAgent($agent, $variables)`.

### Section contributors

Host apps can append extra sections to the resolved prompt without forking the resolver:

```php
use Lyre\AiAgents\Contracts\PromptSectionContributor;
use Lyre\AiAgents\Models\Agent;

class ImageCatalogSection implements PromptSectionContributor
{
    public function name(): string { return 'image_catalog'; }
    public function shouldApply(Agent $agent): bool { /* … */ }
    public function render(Agent $agent): ?string { /* … */ }
}

// In a service provider:
$this->app->bind(ImageCatalogSection::class);
$this->app->tag([ImageCatalogSection::class], PromptSectionContributor::TAG);
```

## Structured output (`text.format`)

Set `agent.metadata.response_format` to a Responses API JSON-schema config and the runner forwards it as `text.format` on every call:

```php
$agent->metadata = array_merge($agent->metadata ?? [], [
    'response_format' => [
        'type' => 'json_schema',
        'name' => 'kenchic_whatsapp_response',
        'strict' => true,
        'schema' => [/* … */],
    ],
]);
$agent->save();
```

## Built-in tools

### Lead capture (`submit_lead`)

```php
app(\Lyre\AiAgents\Services\AgentKnowledgeService::class)
    ->ensureLeadToolForAllAgents('https://your-app.test/api/leads');
```

The tool name is config-driven via `tools.lead.tool_name` (default `submit_lead`). Any agent that previously had the legacy `submit_lead_to_axis` tool will be migrated idempotently on next call.

### Human handover (`request_human_handover`)

```php
app(\Lyre\AiAgents\Services\AgentKnowledgeService::class)
    ->ensureHandoverToolForAllAgents('https://your-app.test/api/handover');
```

The handler endpoint receives the function-call arguments plus Lyre's run/conversation context and is responsible for whatever handover semantics the host app needs (flipping a flag, paging on-call, etc.).

## Events dispatched

- `Lyre\AiAgents\Events\AgentRunStarted`
- `Lyre\AiAgents\Events\AgentToolCalled`
- `Lyre\AiAgents\Events\AgentRunCompleted`
- `Lyre\AiAgents\Events\AgentRunFailed`
- `Lyre\AiAgents\Events\ConversationUpdated`
- `Lyre\AiAgents\Events\UsageRecorded`

## Local development

```json
"repositories": [
    {
        "type": "path",
        "url": "../packages/lyre-ai-agents-laravel",
        "options": {
            "symlink": true
        }
    }
]
```

## Frontend safety

- Frontend should call your backend proxy route, not OpenAI directly.
- If direct OpenAI is needed, use short-lived scoped credentials in trusted environments only.

## Output length limits

Two optional env vars bound how long an agent's reply can be. Both default to
unset, which keeps the prior behaviour (no character cap; the agent's own
`max_output_tokens`, if any, is used).

```env
# Global fallback for max_output_tokens when an agent has none set (native OpenAI limit).
AI_AGENTS_MAX_OUTPUT_TOKENS=800

# Hard cap on the final assistant TEXT, enforced by the package via truncation
# (OpenAI has no character limit). Skipped for structured json_schema responses.
AI_AGENTS_MAX_OUTPUT_CHARACTERS=1000
```

Precedence for tokens: the agent's own `max_output_tokens` column wins; otherwise
`AI_AGENTS_MAX_OUTPUT_TOKENS`; otherwise unset. `max_output_characters` truncates the
final `output_text` (and the stored assistant message) to that many characters. For
live SSE streaming, deltas are forwarded verbatim, so use `max_output_tokens` to bound
what streams in real time — the character cap backstops the persisted/returned text.

## Table naming

By default, the package uses these tables: `agents`, `agent_tools`, `conversations`, `conversation_messages`, `agent_runs`, `usage_logs`, `events`.

You can set a global prefix for multi-project flexibility:

```env
AI_AGENTS_TABLE_PREFIX=axis_
```

Or override individual table names:

```env
AI_AGENTS_TABLE_CONVERSATIONS=axis_conversations
AI_AGENTS_TABLE_CONVERSATION_MESSAGES=axis_conversation_messages
```
