<?php

namespace Lyre\AiAgents\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Queue;
use Lyre\AiAgents\Jobs\ProcessIngestedEvent;
use Lyre\AiAgents\Models\Event;

class EventIngestionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'event_name' => ['required', 'string', 'max:191'],
            'agent_id' => ['nullable', 'integer'],
            'conversation_id' => ['nullable', 'integer'],
            'run_id' => ['nullable', 'integer'],
            'payload' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
            'occurred_at' => ['nullable', 'date'],
            'idempotency_key' => ['nullable', 'string', 'max:191'],
        ]);

        $dedupeKey = (string) ($payload['idempotency_key'] ?? '');
        if ($dedupeKey === '') {
            $dedupeKey = hash('sha256', json_encode([
                'event_name' => $payload['event_name'],
                'agent_id' => $payload['agent_id'] ?? null,
                'conversation_id' => $payload['conversation_id'] ?? null,
                'run_id' => $payload['run_id'] ?? null,
                'payload' => $payload['payload'] ?? [],
                'metadata' => $payload['metadata'] ?? [],
                'occurred_at' => $payload['occurred_at'] ?? null,
            ], JSON_THROW_ON_ERROR));
        }

        $event = Event::query()->firstOrCreate(
            ['dedupe_key' => $dedupeKey],
            [
                'event_name' => $payload['event_name'],
                'agent_id' => $payload['agent_id'] ?? null,
                'conversation_id' => $payload['conversation_id'] ?? null,
                'agent_run_id' => $payload['run_id'] ?? null,
                'payload' => $payload['payload'] ?? [],
                'metadata' => $payload['metadata'] ?? [],
                'occurred_at' => $payload['occurred_at'] ?? now(),
                'status' => 'pending',
            ]
        );

        if (in_array($event->status, ['pending', 'failed'], true)) {
            Queue::push(new ProcessIngestedEvent($event->id));
        }

        return response()->json([
            'id' => $event->id,
            'status' => $event->status,
            'duplicate' => !$event->wasRecentlyCreated,
        ], $event->wasRecentlyCreated ? 201 : 202);
    }
}
