<?php

namespace Lyre\AiAgents\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Lyre\AiAgents\Models\Agent;

class AgentResolutionController extends Controller
{
    public function resolve(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agent' => ['required'],
        ]);

        $agentInput = $data['agent'];
        $agent = is_numeric($agentInput)
            ? Agent::query()->find((int) $agentInput)
            : Agent::query()->where('name', (string) $agentInput)->first();

        if (!$agent) {
            return response()->json([
                'message' => 'Agent not found',
            ], 404);
        }

        return response()->json([
            'id' => $agent->id,
            'name' => $agent->name,
            'model' => $agent->model,
            'instructions' => $agent->instructions,
            'temperature' => $agent->temperature,
            'max_output_tokens' => $agent->max_output_tokens,
            'tools' => $agent->tools ?? [],
            'metadata' => $agent->metadata ?? [],
        ]);
    }
}
