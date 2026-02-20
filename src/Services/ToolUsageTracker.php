<?php

namespace Lyre\AiAgents\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Lyre\AiAgents\Models\ToolUsageLog;

class ToolUsageTracker
{
    protected bool $tableChecked = false;
    protected bool $tableAvailable = false;

    public function record(array $payload): void
    {
        if (!$this->isTableAvailable()) {
            return;
        }

        try {
            ToolUsageLog::query()->create([
                'agent_id' => $payload['agent_id'],
                'conversation_id' => $payload['conversation_id'] ?? null,
                'agent_run_id' => $payload['agent_run_id'] ?? null,
                'tool_name' => $payload['tool_name'],
                'tool_type' => $payload['tool_type'] ?? null,
                'handler_type' => $payload['handler_type'] ?? null,
                'success' => (bool) ($payload['success'] ?? false),
                'duration_ms' => (int) ($payload['duration_ms'] ?? 0),
                'http_status' => $payload['http_status'] ?? null,
                'error_message' => $payload['error_message'] ?? null,
                'arguments' => $payload['arguments'] ?? null,
                'result' => $payload['result'] ?? null,
                'metadata' => $payload['metadata'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed recording tool usage metric', [
                'tool_name' => $payload['tool_name'] ?? null,
                'agent_id' => $payload['agent_id'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function isTableAvailable(): bool
    {
        if ($this->tableChecked) {
            return $this->tableAvailable;
        }

        $this->tableChecked = true;
        $table = (new ToolUsageLog())->getTable();
        $this->tableAvailable = Schema::hasTable($table);

        return $this->tableAvailable;
    }
}

