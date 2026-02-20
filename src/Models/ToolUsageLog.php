<?php

namespace Lyre\AiAgents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class ToolUsageLog extends Model
{
    use ResolvesTableName;

    protected $guarded = ['id'];

    protected $casts = [
        'success' => 'boolean',
        'arguments' => 'array',
        'result' => 'array',
        'metadata' => 'array',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable($this->resolveTableName('tool_usage_logs', 'tool_usage_logs'));
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'agent_run_id');
    }
}
