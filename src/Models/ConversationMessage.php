<?php

namespace Lyre\AiAgents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class ConversationMessage extends Model
{
    use ResolvesTableName;

    protected $guarded = ['id'];

    protected $casts = [
        'content' => 'array',
        'tool_arguments' => 'array',
        'tool_result' => 'array',
        'metadata' => 'array',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable($this->resolveTableName('conversation_messages', 'conversation_messages'));
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }
}
