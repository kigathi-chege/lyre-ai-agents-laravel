<?php

namespace Lyre\AiAgents\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Lyre\AiAgents\Services\AgentManager;

class RunController extends Controller
{
    public function __construct(protected AgentManager $agents) {}

    public function run(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agent' => ['required'],
            'message' => ['required', 'string'],
            'conversation_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'metadata' => ['nullable', 'array'],
            'context' => ['nullable', 'array'],
        ]);

        $result = $this->agents->run($data['agent'], $data['message'], [
            'conversation_id' => $data['conversation_id'] ?? null,
            'user_id' => $data['user_id'] ?? null,
            'ip' => $request->ip(),
            'metadata' => $data['metadata'] ?? [],
            ...($data['context'] ?? []),
        ]);

        return response()->json($result);
    }

    public function stream(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'agent' => ['required'],
            'message' => ['required', 'string'],
            'conversation_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'metadata' => ['nullable', 'array'],
            'context' => ['nullable', 'array'],
        ]);

        $stream = $this->agents->stream($data['agent'], $data['message'], [
            'conversation_id' => $data['conversation_id'] ?? null,
            'user_id' => $data['user_id'] ?? null,
            'ip' => $request->ip(),
            'metadata' => $data['metadata'] ?? [],
            ...($data['context'] ?? []),
        ]);

        return response()->stream(function () use ($stream) {
            foreach ($stream as $chunk) {
                echo $chunk;
                ob_flush();
                flush();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
