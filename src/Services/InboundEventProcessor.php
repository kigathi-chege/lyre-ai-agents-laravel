<?php

namespace Lyre\AiAgents\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as EventFacade;
use Throwable;
use Lyre\AiAgents\Events\AgentRunCompleted;
use Lyre\AiAgents\Events\AgentRunFailed;
use Lyre\AiAgents\Events\AgentRunStarted;
use Lyre\AiAgents\Events\AgentToolCalled;
use Lyre\AiAgents\Events\ConversationUpdated;
use Lyre\AiAgents\Models\AgentRun;
use Lyre\AiAgents\Models\ConversationMessage;
use Lyre\AiAgents\Models\Event;

class InboundEventProcessor
{
    public function __construct(
        protected ConversationStore $conversationStore,
        protected UsageTracker $usageTracker,
        protected CostCalculator $costCalculator,
    ) {}

    public function process(Event $event): Event
    {
        if ($event->status === 'processed') {
            return $event;
        }

        DB::transaction(function () use ($event) {
            $locked = Event::query()->lockForUpdate()->find($event->id);
            if (!$locked || $locked->status === 'processed') {
                return;
            }

            $locked->status = 'processing';
            $locked->attempts = (int) ($locked->attempts ?? 0) + 1;
            $locked->processing_error = null;
            $locked->save();
        });

        try {
            $this->handleEvent($event->fresh());

            Event::query()->whereKey($event->id)->update([
                'status' => 'processed',
                'processed_at' => now(),
                'processing_error' => null,
            ]);
            return Event::query()->findOrFail($event->id);
        } catch (Throwable $e) {
            Event::query()->whereKey($event->id)->update([
                'status' => 'failed',
                'processing_error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    protected function handleEvent(Event $event): void
    {
        $name = strtolower((string) $event->event_name);
        $payload = is_array($event->payload) ? $event->payload : [];
        $metadata = is_array($event->metadata) ? $event->metadata : [];

        if (in_array($name, ['agent.conversation.upsert', 'conversation.upsert', 'agentconversationcreated'], true)) {
            $this->handleConversationUpsert($event, $payload, $metadata);
            return;
        }

        if (in_array($name, ['agent.message.upsert', 'message.upsert', 'agentmessageadded', 'usermessageadded'], true)) {
            $this->handleMessageUpsert($event, $payload, $metadata, $name);
            return;
        }

        if (in_array($name, ['usage.recorded', 'usagerecorded'], true)) {
            $this->handleUsageRecorded($event, $payload, $metadata);
            return;
        }

        $this->handleRunOrToolEvents($event, $payload, $metadata, $name);
    }

    protected function handleConversationUpsert(Event $event, array $payload, array $metadata): void
    {
        $conversation = $this->conversationStore->resolveConversation([
            'conversation_id' => $event->conversation_id ?? $payload['conversation_id'] ?? null,
            'agent_id' => $event->agent_id ?? $payload['agent_id'] ?? null,
            'user_id' => $payload['user_id'] ?? null,
            'external_id' => $payload['external_id'] ?? null,
            'metadata' => array_merge($metadata, $payload['metadata'] ?? []),
        ]);

        if (!empty($payload['status']) && is_string($payload['status'])) {
            $conversation->status = $payload['status'];
            $conversation->save();
        }

        if (!empty($payload['external_id']) && $conversation->external_id !== (string) $payload['external_id']) {
            $conversation->external_id = (string) $payload['external_id'];
            $conversation->save();
        }

        Event::query()->whereKey($event->id)->update([
            'conversation_id' => $conversation->id,
            'agent_id' => $conversation->agent_id,
        ]);
    }

    protected function handleMessageUpsert(Event $event, array $payload, array $metadata, string $name): void
    {
        $role = $payload['role'] ?? null;
        if (!$role) {
            $role = $name === 'usermessageadded' ? 'user' : 'assistant';
        }

        $conversation = $this->conversationStore->resolveConversation([
            'conversation_id' => $event->conversation_id ?? $payload['conversation_id'] ?? null,
            'agent_id' => $event->agent_id ?? $payload['agent_id'] ?? null,
            'user_id' => $payload['user_id'] ?? null,
            'external_id' => $payload['external_id'] ?? null,
            'metadata' => array_merge($metadata, $payload['conversation_metadata'] ?? []),
        ]);

        $sourceMessageId = (string) ($payload['source_message_id']
            ?? $payload['message_id']
            ?? $payload['openai_message_id']
            ?? $payload['openai_response_id']
            ?? '');

        if ($sourceMessageId !== '') {
            $existing = ConversationMessage::query()
                ->where('conversation_id', $conversation->id)
                ->where('metadata->source_message_id', $sourceMessageId)
                ->first();
            if ($existing) {
                return;
            }
        }

        $content = $payload['content'] ?? $payload['message'] ?? '';
        $usage = $payload['usage'] ?? [];
        $promptTokens = (int) ($payload['prompt_tokens'] ?? $usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0);
        $completionTokens = (int) ($payload['completion_tokens'] ?? $usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0);
        $totalTokens = (int) ($payload['total_tokens'] ?? $usage['total_tokens'] ?? ($promptTokens + $completionTokens));
        $cost = $payload['cost_usd'] ?? $this->costCalculator->calculate(
            (string) ($payload['model'] ?? 'gpt-4.1-mini'),
            $promptTokens,
            $completionTokens
        );

        $this->conversationStore->appendMessage($conversation, [
            'role' => $role,
            'content' => is_array($content) ? $content : [['type' => 'text', 'text' => (string) $content]],
            'tool_name' => $payload['tool_name'] ?? null,
            'tool_arguments' => $payload['tool_arguments'] ?? null,
            'tool_result' => $payload['tool_result'] ?? null,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $totalTokens,
            'cost_usd' => $cost,
            'metadata' => array_filter(array_merge($metadata, $payload['metadata'] ?? [], [
                'source_message_id' => $sourceMessageId !== '' ? $sourceMessageId : null,
                'source_event_id' => $event->id,
            ])),
        ]);

        if (!empty($payload['external_id']) && $conversation->external_id !== (string) $payload['external_id']) {
            $conversation->external_id = (string) $payload['external_id'];
            $conversation->save();
        }

        Event::query()->whereKey($event->id)->update([
            'conversation_id' => $conversation->id,
            'agent_id' => $conversation->agent_id,
        ]);
    }

    protected function handleUsageRecorded(Event $event, array $payload, array $metadata): void
    {
        $usage = $payload['usage'] ?? [];
        $this->usageTracker->record([
            'agent_id' => $event->agent_id ?? $payload['agent_id'] ?? null,
            'conversation_id' => $event->conversation_id ?? $payload['conversation_id'] ?? null,
            'agent_run_id' => $event->agent_run_id ?? $payload['run_id'] ?? null,
            'user_id' => $payload['user_id'] ?? null,
            'model' => $payload['model'] ?? null,
            'prompt_tokens' => (int) ($payload['prompt_tokens'] ?? $usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0),
            'completion_tokens' => (int) ($payload['completion_tokens'] ?? $usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0),
            'total_tokens' => (int) ($payload['total_tokens'] ?? $usage['total_tokens'] ?? 0),
            'cost_usd' => $payload['cost_usd'] ?? 0,
            'metadata' => array_merge($metadata, $payload['metadata'] ?? []),
        ]);
    }

    protected function handleRunOrToolEvents(Event $event, array $payload, array $metadata, string $name): void
    {
        $runId = $event->agent_run_id ?? $payload['run_id'] ?? null;
        $run = null;

        if ($runId) {
            $run = AgentRun::query()->find($runId);
        }

        if (!$run && in_array($name, ['agentrunstarted', 'agentruncompleted', 'agentrunfailed'], true)) {
            $resolvedAgentId = $event->agent_id ?? $payload['agent_id'] ?? null;
            if (!$resolvedAgentId) {
                return;
            }
            $run = AgentRun::query()->create([
                'agent_id' => $resolvedAgentId,
                'conversation_id' => $event->conversation_id ?? $payload['conversation_id'] ?? null,
                'user_id' => $payload['user_id'] ?? null,
                'status' => 'running',
                'request_payload' => $payload['request_payload'] ?? $payload,
                'metadata' => array_merge($metadata, $payload['metadata'] ?? []),
                'started_at' => now(),
            ]);

            Event::query()->whereKey($event->id)->update([
                'agent_run_id' => $run->id,
                'agent_id' => $run->agent_id,
                'conversation_id' => $run->conversation_id,
            ]);
        }

        $eventPayload = [
            'agent_id' => $event->agent_id ?? $payload['agent_id'] ?? $run?->agent_id,
            'conversation_id' => $event->conversation_id ?? $payload['conversation_id'] ?? $run?->conversation_id,
            'run_id' => $run?->id ?? $runId,
        ];

        if ($name === 'agentrunstarted') {
            EventFacade::dispatch(new AgentRunStarted($eventPayload));
            return;
        }

        if ($name === 'agentruncompleted') {
            if ($run) {
                $run->update([
                    'status' => 'completed',
                    'response_payload' => $payload['response_payload'] ?? $payload,
                    'completed_at' => now(),
                ]);
            }
            EventFacade::dispatch(new AgentRunCompleted($eventPayload));
            return;
        }

        if ($name === 'agentrunfailed') {
            if ($run) {
                $run->update([
                    'status' => 'failed',
                    'error_payload' => $payload['error_payload'] ?? $payload,
                    'completed_at' => now(),
                ]);
            }
            EventFacade::dispatch(new AgentRunFailed($eventPayload + ['error' => $payload['error'] ?? null]));
            return;
        }

        if ($name === 'agenttoolcalled') {
            EventFacade::dispatch(new AgentToolCalled($eventPayload + [
                'tool_name' => $payload['tool_name'] ?? null,
                'tool_arguments' => $payload['tool_arguments'] ?? null,
                'tool_result' => $payload['tool_result'] ?? null,
            ]));
            return;
        }

        if ($name === 'conversationupdated') {
            EventFacade::dispatch(new ConversationUpdated($eventPayload));
        }
    }
}
