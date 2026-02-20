<?php

namespace Lyre\AiAgents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class Agent extends Model
{
    use ResolvesTableName;

    protected $guarded = ['id'];

    protected $casts = [
        'metadata' => 'array',
        'tools' => 'array',
        'openai_api_key' => 'encrypted',
    ];

    protected $hidden = [
        'openai_api_key',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable($this->resolveTableName('agents', 'agents'));
    }

    public function promptTemplate(): BelongsTo
    {
        return $this->belongsTo(PromptTemplate::class, 'prompt_template_id');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'agent_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class, 'agent_id');
    }

    public function usageLogs(): HasMany
    {
        return $this->hasMany(UsageLog::class, 'agent_id');
    }

    public function toolUsageLogs(): HasMany
    {
        return $this->hasMany(ToolUsageLog::class, 'agent_id');
    }

    public function agentTools(): HasMany
    {
        return $this->hasMany(AgentTool::class, 'agent_id');
    }
}
