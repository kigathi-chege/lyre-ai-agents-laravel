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
        $agentToolsTable = $prefix.'agent_tools';

        Schema::create($agentToolsTable, function (Blueprint $table) use ($agentsTable) {
            $table->id();
            $table->foreignId('agent_id')->constrained($agentsTable)->cascadeOnDelete();
            $table->string('type');
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('parameters_schema')->nullable();
            $table->string('handler_type')->nullable();
            $table->string('handler_ref')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['agent_id', 'name']);
        });
    }

    public function down(): void
    {
        $rawPrefix = env('AI_AGENTS_TABLE_PREFIX', 'ai_agents');
        $prefix = $rawPrefix === '' ? '' : (str_ends_with($rawPrefix, '_') ? $rawPrefix : $rawPrefix.'_');
        Schema::dropIfExists($prefix.'agent_tools');
    }
};
