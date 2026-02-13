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

## Events dispatched

- `Lyre\AiAgents\Events\AgentRunStarted`
- `Lyre\AiAgents\Events\AgentToolCalled`
- `Lyre\AiAgents\Events\AgentRunCompleted`
- `Lyre\AiAgents\Events\AgentRunFailed`
- `Lyre\AiAgents\Events\ConversationUpdated`
- `Lyre\AiAgents\Events\UsageRecorded`

## Migration plan from `gpts`

1. Run published migrations to create `agents`, `agent_tools`, `conversations`, `conversation_messages`, `agent_runs`, `usage_logs`, and `events`.
2. Run migration `2026_02_13_000007_migrate_gpts_to_agents.php` to backfill agents from `gpts`.
3. Existing `gpts` records are marked with `deprecated_at`; reads/writes should move to `agents` only.

## Frontend safety

- Frontend should call your backend proxy route, not OpenAI directly.
- If direct OpenAI is needed, use short-lived scoped credentials in trusted environments only.

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
