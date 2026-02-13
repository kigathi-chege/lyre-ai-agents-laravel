<?php

namespace Lyre\AiAgents\Services;

use Illuminate\Support\Facades\Event as EventFacade;
use Lyre\AiAgents\Events\UsageRecorded;
use Lyre\AiAgents\Models\UsageLog;

class UsageTracker
{
    public function record(array $payload): UsageLog
    {
        $log = UsageLog::query()->create([
            'agent_id' => $payload['agent_id'] ?? null,
            'conversation_id' => $payload['conversation_id'] ?? null,
            'agent_run_id' => $payload['agent_run_id'] ?? null,
            'user_id' => $payload['user_id'] ?? null,
            'model' => $payload['model'] ?? null,
            'prompt_tokens' => $payload['prompt_tokens'] ?? 0,
            'completion_tokens' => $payload['completion_tokens'] ?? 0,
            'total_tokens' => $payload['total_tokens'] ?? 0,
            'cost_usd' => $payload['cost_usd'] ?? 0,
            'metadata' => $payload['metadata'] ?? [],
        ]);

        EventFacade::dispatch(new UsageRecorded(['usage_log_id' => $log->id] + $payload));

        return $log;
    }
}
