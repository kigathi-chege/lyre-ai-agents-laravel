<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $rawPrefix = env('AI_AGENTS_TABLE_PREFIX', 'ai_agents');
        $prefix = $rawPrefix === '' ? '' : (str_ends_with($rawPrefix, '_') ? $rawPrefix : $rawPrefix.'_');
        $agentsTable = $prefix.'agents';

        Schema::create($agentsTable, function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('model');
            $table->longText('instructions')->nullable();
            $table->text('openai_api_key')->nullable();
            $table->float('temperature')->nullable();
            $table->unsignedInteger('max_output_tokens')->nullable();
            $table->json('tools')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['is_active', 'model']);
        });
    }

    public function down(): void
    {
        $rawPrefix = env('AI_AGENTS_TABLE_PREFIX', 'ai_agents');
        $prefix = $rawPrefix === '' ? '' : (str_ends_with($rawPrefix, '_') ? $rawPrefix : $rawPrefix.'_');
        Schema::dropIfExists($prefix.'agents');
    }
};
