<?php

namespace Lyre\AiAgents\Models;

use Illuminate\Database\Eloquent\Model;

class AgentRun extends Model
{
    use ResolvesTableName;

    protected $guarded = ['id'];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
        'error_payload' => 'array',
        'metadata' => 'array',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable($this->resolveTableName('agent_runs', 'agent_runs'));
    }
}
