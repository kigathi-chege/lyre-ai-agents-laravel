<?php

namespace Lyre\AiAgents\Services;

use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Facades\Http;
use Throwable;
use Lyre\AiAgents\Events\AgentRunCompleted;
use Lyre\AiAgents\Events\AgentRunFailed;
use Lyre\AiAgents\Events\AgentRunStarted;
use Lyre\AiAgents\Events\AgentToolCalled;
use Lyre\AiAgents\Events\ConversationUpdated;
use Lyre\AiAgents\Models\Agent;
use Lyre\AiAgents\Models\AgentRun;

class AgentRunner
{
    public function __construct(
        protected OpenAIClient $openAIClient,
        protected ToolRegistry $toolRegistry,
        protected AgentToolResolver $agentToolResolver,
        protected ToolUsageTracker $toolUsageTracker,
        protected ConversationStore $conversationStore,
        protected UsageTracker $usageTracker,
        protected CostCalculator $costCalculator,
        protected RateLimiter $rateLimiter,
        protected PromptTemplateResolver $promptTemplateResolver,
    ) {}

    public function run(Agent $agent, string $userMessage, array $context = []): array
    {
        $clientOptions = $this->resolveOpenAIClientOptions($agent);

        $conversation = $this->conversationStore->resolveConversation([
            'conversation_id' => $context['conversation_id'] ?? null,
            'agent_id' => $agent->id,
            'user_id' => $context['user_id'] ?? null,
            'external_id' => $context['external_id'] ?? null,
            'metadata' => $context['metadata'] ?? [],
        ]);

        $this->rateLimiter->assertAllowed([
            'user' => $context['user_id'] ?? null,
            'agent' => $agent->id,
            'ip' => $context['ip'] ?? null,
            'api_key' => $context['api_key'] ?? null,
        ]);

        $run = AgentRun::query()->create([
            'agent_id' => $agent->id,
            'conversation_id' => $conversation->id,
            'user_id' => $context['user_id'] ?? null,
            'status' => 'running',
            'request_payload' => ['user_message' => $userMessage, 'context' => $context],
            'metadata' => $context['metadata'] ?? [],
            'started_at' => now(),
        ]);

        EventFacade::dispatch(new AgentRunStarted([
            'agent_id' => $agent->id,
            'conversation_id' => $conversation->id,
            'run_id' => $run->id,
        ]));

        $this->conversationStore->appendMessage($conversation, [
            'role' => 'user',
            'content' => [['type' => 'text', 'text' => $userMessage]],
        ]);

        try {
            $this->conversationStore->truncateIfNeeded($conversation, $this->openAIClient, $clientOptions);
            $history = $this->conversationStore->historyForModel($conversation);
            $instructions = $this->promptTemplateResolver->resolveInstructionsForAgent($agent);

            $result = $this->executeLoop($agent, $history, $context, $run->id, $conversation->id, $instructions, $clientOptions);
            $identifiers = $this->extractResponseIdentifiers($result['raw_response'] ?? []);
            $this->conversationStore->appendMessage($conversation, [
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => $result['text']]],
                ...$result['usage'],
                'cost_usd' => $result['cost_usd'],
                'metadata' => array_filter([
                    'model' => $agent->model,
                    'openai_response_id' => $identifiers['response_id'],
                    'openai_message_id' => $identifiers['output_message_id'],
                ]),
            ]);

            $run->update([
                'status' => 'completed',
                'response_payload' => $result['raw_response'],
                'prompt_tokens' => $result['usage']['prompt_tokens'],
                'completion_tokens' => $result['usage']['completion_tokens'],
                'total_tokens' => $result['usage']['total_tokens'],
                'cost_usd' => $result['cost_usd'],
                'completed_at' => now(),
            ]);

            $this->usageTracker->record([
                'agent_id' => $agent->id,
                'conversation_id' => $conversation->id,
                'agent_run_id' => $run->id,
                'user_id' => $context['user_id'] ?? null,
                'model' => $agent->model,
                ...$result['usage'],
                'cost_usd' => $result['cost_usd'],
            ]);

            EventFacade::dispatch(new ConversationUpdated([
                'conversation_id' => $conversation->id,
                'agent_id' => $agent->id,
                'run_id' => $run->id,
            ]));

            EventFacade::dispatch(new AgentRunCompleted([
                'agent_id' => $agent->id,
                'conversation_id' => $conversation->id,
                'run_id' => $run->id,
            ]));

            return [
                'conversation_id' => $conversation->id,
                'run_id' => $run->id,
                'output_text' => $result['text'],
                'usage' => $result['usage'],
                'cost_usd' => $result['cost_usd'],
                'response_id' => $identifiers['response_id'],
                'output_message_id' => $identifiers['output_message_id'],
            ];
        } catch (Throwable $e) {
            $run->update([
                'status' => 'failed',
                'error_payload' => ['message' => $e->getMessage()],
                'completed_at' => now(),
            ]);

            EventFacade::dispatch(new AgentRunFailed([
                'agent_id' => $agent->id,
                'conversation_id' => $conversation->id,
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]));

            throw $e;
        }
    }

    public function stream(Agent $agent, string $userMessage, array $context = []): \Generator
    {
        $clientOptions = $this->resolveOpenAIClientOptions($agent);

        $conversation = $this->conversationStore->resolveConversation([
            'conversation_id' => $context['conversation_id'] ?? null,
            'agent_id' => $agent->id,
            'user_id' => $context['user_id'] ?? null,
            'external_id' => $context['external_id'] ?? null,
            'metadata' => $context['metadata'] ?? [],
        ]);

        $this->rateLimiter->assertAllowed([
            'user' => $context['user_id'] ?? null,
            'agent' => $agent->id,
            'ip' => $context['ip'] ?? null,
            'api_key' => $context['api_key'] ?? null,
        ]);

        $run = AgentRun::query()->create([
            'agent_id' => $agent->id,
            'conversation_id' => $conversation->id,
            'user_id' => $context['user_id'] ?? null,
            'status' => 'running',
            'request_payload' => ['user_message' => $userMessage, 'context' => $context, 'stream' => true],
            'metadata' => $context['metadata'] ?? [],
            'started_at' => now(),
        ]);

        EventFacade::dispatch(new AgentRunStarted([
            'agent_id' => $agent->id,
            'conversation_id' => $conversation->id,
            'run_id' => $run->id,
        ]));

        $this->conversationStore->appendMessage($conversation, [
            'role' => 'user',
            'content' => [['type' => 'text', 'text' => $userMessage]],
        ]);

        $this->conversationStore->truncateIfNeeded($conversation, $this->openAIClient, $clientOptions);
        $history = $this->conversationStore->historyForModel($conversation);
        $instructions = $this->promptTemplateResolver->resolveInstructionsForAgent($agent);

        $resolvedTools = $this->agentToolResolver->resolveForAgent($agent);
        $payload = [
            'model' => $agent->model,
            'input' => $history,
            'tools' => $resolvedTools['response_tools'],
            'temperature' => $agent->temperature,
            'max_output_tokens' => $agent->max_output_tokens,
            'instructions' => !empty($instructions) ? (string) $instructions : null,
        ];

        $buffer = '';
        $assistantText = '';
        $usage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
        $responseId = null;
        $outputMessageId = null;
        $completedResponse = [];
        $allResponses = [];
        $toolLoopExecuted = false;

        try {
            foreach ($this->openAIClient->streamResponse(array_filter($payload, fn ($v) => $v !== null), $clientOptions) as $chunk) {
                $buffer .= $chunk;
                $forwardEvents = [];

                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);

                    if (!str_starts_with($line, 'data:')) {
                        continue;
                    }

                    $json = trim(substr($line, 5));
                    if ($json === '' || $json === '[DONE]') {
                        continue;
                    }

                    $event = json_decode($json, true);
                    if (!is_array($event)) {
                        continue;
                    }

                    $eventType = $event['type'] ?? null;
                    if ($eventType === 'response.output_text.delta') {
                        $assistantText .= (string) ($event['delta'] ?? '');
                    } elseif ($eventType === 'response.completed') {
                        $completedResponse = is_array($event['response'] ?? null) ? $event['response'] : [];
                        $allResponses[] = $completedResponse;
                        $identifiers = $this->extractResponseIdentifiers($completedResponse);
                        $responseId = $identifiers['response_id'];
                        $outputMessageId = $identifiers['output_message_id'];
                        $responseUsage = $completedResponse['usage'] ?? [];
                        $usage = [
                            'prompt_tokens' => (int) ($responseUsage['input_tokens'] ?? 0),
                            'completion_tokens' => (int) ($responseUsage['output_tokens'] ?? 0),
                            'total_tokens' => (int) ($responseUsage['total_tokens'] ?? 0),
                        ];
                    }

                    $forwardEvents[] = $json;
                }

                foreach ($forwardEvents as $forwardEvent) {
                    yield 'data: '.$forwardEvent."\n\n";
                }
            }

            // If the streamed response ended with tool calls, execute them and continue until assistant message is produced.
            $pendingCalls = $this->extractFunctionCalls($completedResponse);
            $maxToolIterations = 8;
            $toolIteration = 0;
            while (!empty($pendingCalls)) {
                $toolLoopExecuted = true;
                $toolIteration++;
                if ($toolIteration > $maxToolIterations) {
                    throw new \RuntimeException('Streaming tool loop exceeded iteration limit');
                }

                if (empty($responseId)) {
                    throw new \RuntimeException('Cannot continue streamed tool call loop without response id.');
                }

                $toolOutputs = [];
                foreach ($pendingCalls as $call) {
                    yield 'data: '.json_encode([
                        'type' => 'tool.call.started',
                        'tool_name' => $call['name'] ?? null,
                        'call_id' => $call['call_id'] ?? null,
                    ])."\n\n";

                    $execution = $this->executeToolCall(
                        agent: $agent,
                        call: $call,
                        executableTools: $resolvedTools['executable_tools'],
                        context: $context,
                        runId: $run->id,
                        conversationId: $conversation->id,
                    );

                    $toolOutputs[] = [
                        'type' => 'function_call_output',
                        'call_id' => $call['call_id'] ?? null,
                        'output' => json_encode($execution['tool_result']),
                    ];

                    // Extra stream event so consumers can observe tool execution in-stream.
                    yield 'data: '.json_encode([
                        'type' => 'tool.call.completed',
                        'tool_name' => $execution['tool_name'],
                        'call_id' => $call['call_id'] ?? null,
                        'result' => $execution['tool_result'],
                        'duration_ms' => $execution['duration_ms'],
                    ])."\n\n";
                }

                $followUpPayload = [
                    'model' => $agent->model,
                    'previous_response_id' => $responseId,
                    'input' => $toolOutputs,
                    'tools' => $resolvedTools['response_tools'],
                    'temperature' => $agent->temperature,
                    'max_output_tokens' => $agent->max_output_tokens,
                    'instructions' => !empty($instructions) ? (string) $instructions : null,
                ];

                $nextResponse = [];
                $nextBuffer = '';
                foreach ($this->openAIClient->streamResponse(array_filter($followUpPayload, fn ($v) => $v !== null), $clientOptions) as $nextChunk) {
                    $nextBuffer .= $nextChunk;
                    $nextForwardEvents = [];

                    while (($nextPos = strpos($nextBuffer, "\n")) !== false) {
                        $nextLine = trim(substr($nextBuffer, 0, $nextPos));
                        $nextBuffer = substr($nextBuffer, $nextPos + 1);

                        if (!str_starts_with($nextLine, 'data:')) {
                            continue;
                        }

                        $nextJson = trim(substr($nextLine, 5));
                        if ($nextJson === '' || $nextJson === '[DONE]') {
                            continue;
                        }

                        $nextEvent = json_decode($nextJson, true);
                        if (!is_array($nextEvent)) {
                            continue;
                        }

                        $nextEventType = $nextEvent['type'] ?? null;
                        if ($nextEventType === 'response.output_text.delta') {
                            $assistantText .= (string) ($nextEvent['delta'] ?? '');
                        } elseif ($nextEventType === 'response.completed') {
                            $nextResponse = is_array($nextEvent['response'] ?? null) ? $nextEvent['response'] : [];
                        }

                        $nextForwardEvents[] = $nextJson;
                    }

                    foreach ($nextForwardEvents as $nextForwardEvent) {
                        yield 'data: '.$nextForwardEvent."\n\n";
                    }
                }

                if (empty($nextResponse)) {
                    throw new \RuntimeException('Follow-up streamed response did not return a completed response payload.');
                }

                $allResponses[] = $nextResponse;

                $identifiers = $this->extractResponseIdentifiers($nextResponse);
                $responseId = $identifiers['response_id'] ?? $responseId;
                $outputMessageId = $identifiers['output_message_id'] ?? $outputMessageId;

                $nextUsage = $this->openAIClient->extractUsage($nextResponse);
                $usage = [
                    'prompt_tokens' => $usage['prompt_tokens'] + $nextUsage['prompt_tokens'],
                    'completion_tokens' => $usage['completion_tokens'] + $nextUsage['completion_tokens'],
                    'total_tokens' => $usage['total_tokens'] + $nextUsage['total_tokens'],
                ];

                $pendingCalls = $this->extractFunctionCalls($nextResponse);
                $completedResponse = $nextResponse;
            }

            yield "data: [DONE]\n\n";

            $cost = $this->costCalculator->calculate($agent->model, $usage['prompt_tokens'], $usage['completion_tokens']);
            $this->conversationStore->appendMessage($conversation, [
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => $assistantText]],
                ...$usage,
                'cost_usd' => $cost,
                'metadata' => array_filter([
                    'model' => $agent->model,
                    'stream' => true,
                    'openai_response_id' => $responseId,
                    'openai_message_id' => $outputMessageId,
                ]),
            ]);

            $run->update([
                'status' => 'completed',
                'response_payload' => [
                    'output_text' => $assistantText,
                    'stream' => true,
                    'response_id' => $responseId,
                    'output_message_id' => $outputMessageId,
                    'response' => $completedResponse,
                    'responses' => $allResponses,
                ],
                'prompt_tokens' => $usage['prompt_tokens'],
                'completion_tokens' => $usage['completion_tokens'],
                'total_tokens' => $usage['total_tokens'],
                'cost_usd' => $cost,
                'completed_at' => now(),
            ]);

            $this->usageTracker->record([
                'agent_id' => $agent->id,
                'conversation_id' => $conversation->id,
                'agent_run_id' => $run->id,
                'user_id' => $context['user_id'] ?? null,
                'model' => $agent->model,
                ...$usage,
                'cost_usd' => $cost,
                'metadata' => ['stream' => true],
            ]);

            EventFacade::dispatch(new ConversationUpdated([
                'conversation_id' => $conversation->id,
                'agent_id' => $agent->id,
                'run_id' => $run->id,
            ]));

            EventFacade::dispatch(new AgentRunCompleted([
                'agent_id' => $agent->id,
                'conversation_id' => $conversation->id,
                'run_id' => $run->id,
            ]));
        } catch (Throwable $e) {
            $run->update([
                'status' => 'failed',
                'error_payload' => ['message' => $e->getMessage(), 'stream' => true],
                'completed_at' => now(),
            ]);

            EventFacade::dispatch(new AgentRunFailed([
                'agent_id' => $agent->id,
                'conversation_id' => $conversation->id,
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]));

            throw $e;
        }
    }

    protected function executeLoop(Agent $agent, array $history, array $context, int $runId, int $conversationId, ?string $instructions = null, array $clientOptions = []): array
    {
        $maxIterations = 8;
        $response = [];
        $resolvedTools = $this->agentToolResolver->resolveForAgent($agent);

        for ($i = 0; $i < $maxIterations; $i++) {
            $payload = [
                'model' => $agent->model,
                'input' => $history,
                'tools' => $resolvedTools['response_tools'],
                'temperature' => $agent->temperature,
                'max_output_tokens' => $agent->max_output_tokens,
                'instructions' => !empty($instructions) ? (string) $instructions : null,
            ];

            $response = $this->openAIClient->createResponse(array_filter($payload, fn ($v) => $v !== null), $clientOptions);
            $functionCalls = $this->extractFunctionCalls($response);

            if (empty($functionCalls)) {
                $text = $this->openAIClient->extractText($response);
                $usage = $this->openAIClient->extractUsage($response);
                $cost = $this->costCalculator->calculate($agent->model, $usage['prompt_tokens'], $usage['completion_tokens']);

                return [
                    'text' => $text,
                    'usage' => $usage,
                    'cost_usd' => $cost,
                    'raw_response' => $response,
                ];
            }

            foreach ($functionCalls as $call) {
                $execution = $this->executeToolCall(
                    agent: $agent,
                    call: $call,
                    executableTools: $resolvedTools['executable_tools'],
                    context: $context,
                    runId: $runId,
                    conversationId: $conversationId,
                );

                $history[] = [
                    'type' => 'function_call_output',
                    'call_id' => $call['call_id'] ?? null,
                    'output' => json_encode($execution['tool_result']),
                ];
            }
        }

        throw new \RuntimeException('Tool loop exceeded iteration limit');
    }

    protected function resolveOpenAIClientOptions(Agent $agent): array
    {
        if (!empty($agent->openai_api_key)) {
            return [
                'api_key' => $agent->openai_api_key,
            ];
        }

        return [];
    }

    protected function extractFunctionCalls(array $response): array
    {
        $calls = [];

        foreach (($response['output'] ?? []) as $item) {
            if (($item['type'] ?? null) === 'function_call') {
                $calls[] = $item;
            }
        }

        return $calls;
    }

    protected function extractResponseIdentifiers(array $response): array
    {
        $responseId = $response['id'] ?? null;
        $outputMessageId = null;

        foreach (($response['output'] ?? []) as $item) {
            if (($item['type'] ?? null) === 'message' && ($item['role'] ?? null) === 'assistant' && !empty($item['id'])) {
                $outputMessageId = (string) $item['id'];
                break;
            }
        }

        return [
            'response_id' => $responseId ? (string) $responseId : null,
            'output_message_id' => $outputMessageId,
        ];
    }

    protected function executeToolCall(
        Agent $agent,
        array $call,
        array $executableTools,
        array $context,
        int $runId,
        int $conversationId,
    ): array {
        $toolName = (string) ($call['name'] ?? '');
        $arguments = json_decode($call['arguments'] ?? '{}', true) ?: [];
        $tool = $executableTools[$toolName] ?? $this->toolRegistry->get($toolName);
        $startedAt = microtime(true);
        $httpStatus = null;
        $errorMessage = null;
        $toolResult = null;

        try {
            if (!$tool) {
                $toolResult = ['error' => "Tool [$toolName] is not registered"];
            } elseif (is_callable($tool->handler)) {
                $toolResult = call_user_func($tool->handler, $arguments, $context);
            } elseif ($tool->type === 'api' && is_string($tool->handler)) {
                // Send flat arguments for framework endpoints, but preserve context in side-channels.
                $apiPayload = array_merge($arguments, [
                    '_context' => $context,
                    '_tool_name' => $toolName,
                ]);
                $apiResponse = Http::post($tool->handler, $apiPayload);
                $httpStatus = $apiResponse->status();
                $toolResult = $apiResponse->json() ?? ['status' => $httpStatus];
            } else {
                $toolResult = ['error' => "Tool [$toolName] has invalid handler"];
            }
        } catch (Throwable $toolException) {
            $errorMessage = $toolException->getMessage();
            $toolResult = ['error' => $errorMessage];
        }

        $success = $errorMessage === null
            && !(is_array($toolResult) && array_key_exists('error', $toolResult))
            && ($httpStatus === null || ($httpStatus >= 200 && $httpStatus < 300));

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        $this->toolUsageTracker->record([
            'agent_id' => $agent->id,
            'conversation_id' => $conversationId,
            'agent_run_id' => $runId,
            'tool_name' => $toolName,
            'tool_type' => $tool?->type ?? null,
            'handler_type' => is_string($tool?->handler) ? 'endpoint' : 'callable',
            'success' => $success,
            'duration_ms' => $durationMs,
            'http_status' => $httpStatus,
            'error_message' => $errorMessage,
            'arguments' => $arguments,
            'result' => is_array($toolResult) ? $toolResult : ['result' => $toolResult],
            'metadata' => [
                'call_id' => $call['call_id'] ?? null,
                'response_tool_call_id' => $call['id'] ?? null,
            ],
        ]);

        EventFacade::dispatch(new AgentToolCalled([
            'agent_id' => $agent->id,
            'conversation_id' => $conversationId,
            'run_id' => $runId,
            'tool_name' => $toolName,
            'tool_arguments' => $arguments,
            'tool_result' => $toolResult,
        ]));

        if ($errorMessage !== null) {
            throw new \RuntimeException("Tool [$toolName] execution failed: ".$errorMessage);
        }

        return [
            'tool_name' => $toolName,
            'arguments' => $arguments,
            'tool_result' => $toolResult,
            'http_status' => $httpStatus,
            'duration_ms' => $durationMs,
        ];
    }
}
