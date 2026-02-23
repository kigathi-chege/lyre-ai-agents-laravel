<?php

namespace Lyre\AiAgents\Services;

use Illuminate\Support\Facades\Event as EventFacade;
use Lyre\AiAgents\Events\AgentConversationCreated;
use Lyre\AiAgents\Events\AgentMessageAdded;
use Lyre\AiAgents\Events\UserMessageAdded;
use Lyre\AiAgents\Models\Conversation;
use Lyre\AiAgents\Models\ConversationMessage;

class ConversationStore
{
    public function resolveConversation(array $context): Conversation
    {
        if (!empty($context['conversation_id'])) {
            $direct = Conversation::query()->find($context['conversation_id']);
            if ($direct) {
                return $direct;
            }
        }

        $axisConversationId = $context['metadata']['axis_conversation_id'] ?? null;
        if (!empty($axisConversationId)) {
            $mapped = Conversation::query()
                ->where('agent_id', $context['agent_id'])
                ->where('metadata->axis_conversation_id', (string) $axisConversationId)
                ->orderByDesc('id')
                ->first();

            if ($mapped) {
                return $mapped;
            }
        }

        if (!empty($context['external_id']) && !empty($context['agent_id'])) {
            $external = Conversation::query()
                ->where('agent_id', $context['agent_id'])
                ->where('external_id', (string) $context['external_id'])
                ->orderByDesc('id')
                ->first();
            if ($external) {
                return $external;
            }
        }

        $conversation = Conversation::query()->create([
            'agent_id' => $context['agent_id'],
            'user_id' => $context['user_id'] ?? null,
            'external_id' => $context['external_id'] ?? null,
            'metadata' => $context['metadata'] ?? [],
            'status' => 'active',
        ]);

        EventFacade::dispatch(new AgentConversationCreated([
            'conversation_id' => $conversation->id,
            'agent_id' => $conversation->agent_id,
            'user_id' => $conversation->user_id,
            'metadata' => $conversation->metadata ?? [],
        ]));

        return $conversation;
    }

    public function appendMessage(Conversation $conversation, array $message): ConversationMessage
    {
        $created = ConversationMessage::query()->create([
            'conversation_id' => $conversation->id,
            'agent_id' => $conversation->agent_id,
            'role' => $message['role'],
            'content' => is_array($message['content'] ?? null) ? $message['content'] : [['type' => 'text', 'text' => (string) ($message['content'] ?? '')]],
            'tool_name' => $message['tool_name'] ?? null,
            'tool_arguments' => $message['tool_arguments'] ?? null,
            'tool_result' => $message['tool_result'] ?? null,
            'prompt_tokens' => $message['prompt_tokens'] ?? 0,
            'completion_tokens' => $message['completion_tokens'] ?? 0,
            'total_tokens' => $message['total_tokens'] ?? 0,
            'cost_usd' => $message['cost_usd'] ?? 0,
            'metadata' => $message['metadata'] ?? [],
        ]);

        EventFacade::dispatch(new AgentMessageAdded([
            'message_id' => $created->id,
            'conversation_id' => $conversation->id,
            'agent_id' => $conversation->agent_id,
            'role' => $created->role,
            'content' => $created->content,
            'conversation_metadata' => $conversation->metadata ?? [],
            'prompt_tokens' => $created->prompt_tokens,
            'completion_tokens' => $created->completion_tokens,
            'total_tokens' => $created->total_tokens,
            'cost_usd' => $created->cost_usd,
            'metadata' => $created->metadata ?? [],
        ]));

        if ($created->role === 'user') {
            EventFacade::dispatch(new UserMessageAdded([
                'message_id' => $created->id,
                'conversation_id' => $conversation->id,
                'agent_id' => $conversation->agent_id,
                'role' => $created->role,
                'content' => $created->content,
                'conversation_metadata' => $conversation->metadata ?? [],
                'prompt_tokens' => $created->prompt_tokens,
                'completion_tokens' => $created->completion_tokens,
                'total_tokens' => $created->total_tokens,
                'cost_usd' => $created->cost_usd,
                'metadata' => $created->metadata ?? [],
            ]));
        }

        return $created;
    }

    public function historyForModel(Conversation $conversation): array
    {
        $max = (int) config('ai-agents.conversation.max_history_messages', 30);
        $messages = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('id')
            ->limit($max)
            ->get(['role', 'content', 'metadata'])
            ->reverse()
            ->values();

        return $messages
            ->map(function ($m) {
                $role = in_array($m->role, ['system', 'user', 'assistant'], true) ? $m->role : 'assistant';
                $metadata = is_array($m->metadata) ? $m->metadata : [];

                // Hard guard: instructions/description must be supplied via top-level "instructions",
                // never as conversational message history. Only keep generated truncation summaries.
                if ($role === 'system') {
                    $isGeneratedSummary = (($metadata['source'] ?? null) === 'truncation')
                        && (($metadata['generated'] ?? false) === true);
                    if (!$isGeneratedSummary) {
                        return null;
                    }
                }

                $text = $this->flattenContentToText($m->content);

                if ($text === '') {
                    return null;
                }

                return [
                    'role' => $role,
                    // Responses API accepts plain string content for conversational input.
                    'content' => $text,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    public function truncateIfNeeded(Conversation $conversation, OpenAIClient $client, array $clientConfig = []): void
    {
        $count = ConversationMessage::query()->where('conversation_id', $conversation->id)->count();
        $batchMax = (int) config('ai-agents.conversation.batch_max_messages', 80);

        if ($count <= $batchMax) {
            return;
        }

        $strategy = (string) config('ai-agents.conversation.truncation_strategy', 'summarize_then_keep_last_n');
        if ($strategy !== 'summarize_then_keep_last_n') {
            return;
        }

        $keep = (int) config('ai-agents.conversation.max_history_messages', 30);
        $old = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->orderBy('id')
            ->limit(max(1, $count - $keep))
            ->get(['role', 'content']);

        $summary = $client->summarizeMessages($old->toArray(), $clientConfig);

        if ($summary) {
            $this->appendMessage($conversation, [
                'role' => 'system',
                'content' => [['type' => 'text', 'text' => 'Conversation summary: '.$summary]],
                'metadata' => ['generated' => true, 'source' => 'truncation'],
            ]);
        }

        ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('id')
            ->skip($keep)
            ->take($count)
            ->delete();
    }

    protected function flattenContentToText(mixed $content): string
    {
        if (is_string($content)) {
            return trim($content);
        }

        if (!is_array($content)) {
            return '';
        }

        $chunks = [];
        foreach ($content as $part) {
            if (!is_array($part)) {
                continue;
            }

            $text = $part['text'] ?? $part['output_text'] ?? $part['input_text'] ?? null;
            if (is_string($text) && trim($text) !== '') {
                $chunks[] = trim($text);
            }
        }

        return trim(implode("\n", $chunks));
    }
}
