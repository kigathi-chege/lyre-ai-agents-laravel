<?php

namespace Lyre\AiAgents\Models;

use Illuminate\Database\Eloquent\Model;

class AgentTool extends Model
{
    use ResolvesTableName;

    protected $guarded = ['id'];

    protected $casts = [
        'parameters_schema' => 'array',
        'metadata' => 'array',
        'is_enabled' => 'boolean',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable($this->resolveTableName('agent_tools', 'agent_tools'));
    }
}
