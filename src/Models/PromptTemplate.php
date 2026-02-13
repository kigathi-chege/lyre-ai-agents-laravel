<?php

namespace Lyre\AiAgents\Models;

use Illuminate\Database\Eloquent\Model;

class PromptTemplate extends Model
{
    use ResolvesTableName;

    protected $guarded = ['id'];

    protected $casts = [
        'variables' => 'array',
        'metadata' => 'array',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable($this->resolveTableName('prompt_templates', 'prompt_templates'));
    }
}
