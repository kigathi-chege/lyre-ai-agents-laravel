<?php

$rawPrefix = env('AI_AGENTS_TABLE_PREFIX', 'ai_agents');
$prefix = $rawPrefix === '' ? '' : (str_ends_with($rawPrefix, '_') ? $rawPrefix : $rawPrefix.'_');

return [
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'organization' => env('OPENAI_ORG_ID'),
        'project' => env('OPENAI_PROJECT_ID'),
        'timeout' => (int) env('AI_AGENTS_TIMEOUT', 60),
    ],

    'default_model' => env('AI_AGENTS_DEFAULT_MODEL', 'gpt-4.1-mini'),

    'tables' => [
        'prefix' => $prefix,
        'agents' => env('AI_AGENTS_TABLE_AGENTS', $prefix.'agents'),
        'prompt_templates' => env('AI_AGENTS_TABLE_PROMPT_TEMPLATES', $prefix.'prompt_templates'),
        'agent_tools' => env('AI_AGENTS_TABLE_AGENT_TOOLS', $prefix.'agent_tools'),
        'conversations' => env('AI_AGENTS_TABLE_CONVERSATIONS', $prefix.'conversations'),
        'conversation_messages' => env('AI_AGENTS_TABLE_CONVERSATION_MESSAGES', $prefix.'conversation_messages'),
        'agent_runs' => env('AI_AGENTS_TABLE_AGENT_RUNS', $prefix.'agent_runs'),
        'usage_logs' => env('AI_AGENTS_TABLE_USAGE_LOGS', $prefix.'usage_logs'),
        'events' => env('AI_AGENTS_TABLE_EVENTS', $prefix.'events'),
    ],

    'prompts' => [
        'default_key' => env('AI_AGENTS_DEFAULT_PROMPT_KEY', 'enterprise_default'),
    ],

    'conversation' => [
        'max_history_messages' => (int) env('AI_AGENTS_MAX_HISTORY_MESSAGES', 30),
        'truncation_strategy' => env('AI_AGENTS_TRUNCATION_STRATEGY', 'summarize_then_keep_last_n'),
        'summary_model' => env('AI_AGENTS_SUMMARY_MODEL', 'gpt-4.1-mini'),
        'summary_max_tokens' => (int) env('AI_AGENTS_SUMMARY_MAX_TOKENS', 400),
        'batch_max_messages' => (int) env('AI_AGENTS_BATCH_MAX_MESSAGES', 80),
    ],

    'rate_limit' => [
        'enabled' => (bool) env('AI_AGENTS_RATE_LIMIT_ENABLED', true),
        'window_seconds' => (int) env('AI_AGENTS_RATE_LIMIT_WINDOW', 60),
        'max_requests' => (int) env('AI_AGENTS_RATE_LIMIT_MAX_REQUESTS', 30),
    ],

    'pricing' => [
        'gpt-4.1' => ['prompt_per_million' => 2.0, 'completion_per_million' => 8.0],
        'gpt-4.1-mini' => ['prompt_per_million' => 0.4, 'completion_per_million' => 1.6],
        'gpt-4.1-nano' => ['prompt_per_million' => 0.1, 'completion_per_million' => 0.4],
    ],

    'sync' => [
        'ingest_events_route' => '/api/ai-agents/events',
    ],
];
