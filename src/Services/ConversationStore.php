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

    /**
     * Insert a soft "session reset" system message when the gap since the last
     * message in the conversation exceeds the configured threshold. The marker
     * tells the model the next user turn should be treated as a fresh start,
     * without hard-deleting old history. Returns the inserted message, or null
     * when no boundary is needed (first turn, recent activity, threshold
     * disabled, or a boundary already sits at the tail).
     */
    public function maybeInsertSessionBoundary(Conversation $conversation): ?ConversationMessage
    {
        $threshold = (int) config('ai-agents.conversation.session_reset_after_minutes', 60);
        if ($threshold <= 0) {
            return null;
        }

        $last = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('id')
            ->first(['id', 'role', 'metadata', 'created_at']);

        if (!$last) {
            return null;
        }

        $lastMeta = is_array($last->metadata) ? $last->metadata : [];
        if ($last->role === 'system' && ($lastMeta['source'] ?? null) === 'session_boundary') {
            return null;
        }

        $gap = (int) $last->created_at->diffInMinutes(now());
        if ($gap < $threshold) {
            return null;
        }

        return $this->appendMessage($conversation, [
            'role' => 'system',
            'content' => [['type' => 'text', 'text' => sprintf(
                '--- Session reset after %s of inactivity. Treat the next user message as a fresh start. Do not continue prior topics unless the user clearly references them. ---',
                $this->formatGap($gap)
            )]],
            'metadata' => [
                'source' => 'session_boundary',
                'generated' => true,
                'gap_minutes' => $gap,
            ],
        ]);
    }

    protected function formatGap(int $minutes): string
    {
        if ($minutes < 60) {
            return "{$minutes}m";
        }
        if ($minutes < 60 * 24) {
            return intdiv($minutes, 60) . 'h';
        }
        return intdiv($minutes, 60 * 24) . 'd';
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
                // never as conversational message history. Only keep generated runtime markers
                // (truncation summaries and session boundary notices).
                if ($role === 'system') {
                    $source = $metadata['source'] ?? null;
                    $isGenerated = ($metadata['generated'] ?? false) === true;
                    if (!$isGenerated || !in_array($source, ['truncation', 'session_boundary'], true)) {
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

    public function historyForPromptContext(Conversation $conversation, int $excludeRecentMessages = 0): ?string
    {
        $max = max(1, (int) config('ai-agents.conversation.max_history_messages', 30));
        $messages = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('id')
            ->limit($max + max(0, $excludeRecentMessages))
            ->get(['role', 'content', 'metadata']);

        if ($excludeRecentMessages > 0) {
            $messages = $messages->slice($excludeRecentMessages)->values();
        }

        $entries = $messages
            ->map(function ($message) {
                $role = in_array($message->role, ['system', 'user', 'assistant'], true)
                    ? $message->role
                    : 'assistant';
                $metadata = is_array($message->metadata) ? $message->metadata : [];

                if ($role === 'system') {
                    $source = $metadata['source'] ?? null;
                    $isGenerated = ($metadata['generated'] ?? false) === true;
                    if (!$isGenerated || !in_array($source, ['truncation', 'session_boundary'], true)) {
                        return null;
                    }
                }

                $text = $this->flattenContentToText($message->content);
                if ($text === '') {
                    return null;
                }

                return [
                    'role_label' => $this->promptContextRoleLabel($role, $metadata),
                    'text' => preg_replace('/\s+/', ' ', trim($text)),
                ];
            })
            ->filter()
            ->values();

        if ($entries->isEmpty()) {
            return null;
        }

        $lines = [
            'Prior conversation context:',
            'The messages below are listed from most recent to oldest.',
            'Give more weight to more recent messages when details conflict, appear stale, or the user changes direction.',
            '<conversation_context>',
        ];

        foreach ($entries as $index => $entry) {
            $lines[] = sprintf('%d. %s: %s', $index + 1, $entry['role_label'], $entry['text']);
        }

        $lines[] = '</conversation_context>';

        return implode("\n", $lines);
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

    protected function promptContextRoleLabel(string $role, array $metadata = []): string
    {
        if ($role === 'user') {
            return 'User';
        }

        if ($role === 'assistant') {
            return 'Assistant';
        }

        $source = $metadata['source'] ?? null;

        return match ($source) {
            'session_boundary' => 'System note',
            'truncation' => 'System summary',
            default => 'System',
        };
    }
}
