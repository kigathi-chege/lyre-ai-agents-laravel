<?php

namespace Lyre\AiAgents\Models;

use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    use ResolvesTableName;

    protected $guarded = ['id'];

    protected $casts = [
        'payload' => 'array',
        'metadata' => 'array',
        'occurred_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable($this->resolveTableName('events', 'events'));
    }
}
