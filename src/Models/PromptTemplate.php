<?php

namespace Lyre\AiAgents\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromptTemplate extends Model
{
    use ResolvesTableName;

    protected $guarded = ['id'];

    protected $casts = [
        'variables' => 'array',
        'metadata' => 'array',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'extends_template_id' => 'integer',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable($this->resolveTableName('prompt_templates', 'prompt_templates'));
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'extends_template_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'extends_template_id');
    }
}
